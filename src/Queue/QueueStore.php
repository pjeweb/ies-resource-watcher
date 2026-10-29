<?php

declare(strict_types=1);

namespace StadtBraunschweig\IesResourceWatcher\Queue;

use PDO;
use StadtBraunschweig\IesResourceWatcher\Index\Document;

/**
 * Durable state in SQLite (WAL, synchronous=FULL).
 *
 * files:   last observed signature per path and the documents last known to
 *          be indexed for it. documents is NULL while the identity of a
 *          baseline file is still unknown.
 * pending: paths that need a job. rev increases with every observation, so
 *          an older job can never acknowledge a newer version.
 */
final class QueueStore
{
    /** Coalesce bursts, but never postpone a path more than this beyond its first event. */
    private const MAX_DEFERRAL = 3.0;

    private PDO $db;
    private int $depth = 0;

    public function __construct(string $file, private readonly float $quiet = 0.75)
    {
        $this->db = new PDO('sqlite:' . $file, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 10,
        ]);
        $this->db->exec('PRAGMA journal_mode=WAL');
        $this->db->exec('PRAGMA synchronous=FULL');
        $this->db->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS files(path TEXT PRIMARY KEY, sig TEXT NOT NULL, documents TEXT);
            CREATE TABLE IF NOT EXISTS pending(path TEXT PRIMARY KEY, rev INTEGER NOT NULL, first REAL NOT NULL,
                due REAL NOT NULL, attempts INTEGER NOT NULL DEFAULT 0, error TEXT);
            CREATE TABLE IF NOT EXISTS meta(key TEXT PRIMARY KEY, value TEXT);
            SQL);
    }

    /**
     * Run $work in one write transaction; nested calls join the outer one.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public function atomically(callable $work): mixed
    {
        if ($this->depth > 0) {
            return $work();
        }
        // IMMEDIATE takes the write lock up front and waits for it, instead of
        // failing when a read transaction later needs to upgrade.
        $this->db->exec('BEGIN IMMEDIATE');
        $this->depth = 1;
        try {
            $result = $work();
            $this->db->exec('COMMIT');
            return $result;
        } catch (\Throwable $error) {
            try {
                $this->db->exec('ROLLBACK');
            } catch (\PDOException) {
                // SQLite already rolled back; keep the original error.
            }
            throw $error;
        } finally {
            $this->depth = 0;
        }
    }

    public function meta(string $key): ?string
    {
        $statement = $this->db->prepare('SELECT value FROM meta WHERE key=?');
        $statement->execute([$key]);
        $value = $statement->fetchColumn();
        return $value === false ? null : (string) $value;
    }

    public function setMeta(string $key, string $value): void
    {
        $this->atomically(fn() => $this->db->prepare('INSERT OR REPLACE INTO meta VALUES(?,?)')->execute([$key, $value]));
    }

    /**
     * Record an observed signature ('' means absent) and queue the path if needed.
     *
     * $force queues even an unchanged signature: a file event is evidence of
     * a write, whatever the hash says. $baseline records an unknown path
     * without queueing it, for the first scan of a scope.
     */
    public function observe(string $path, string $signature, bool $force = false, bool $baseline = false): void
    {
        $this->atomically(function () use ($path, $signature, $force, $baseline): void {
            $statement = $this->db->prepare('SELECT sig FROM files WHERE path=?');
            $statement->execute([$path]);
            $known = $statement->fetchColumn();
            if ($known === $signature && !$force) {
                return;
            }
            $this->db->prepare('INSERT INTO files(path, sig) VALUES(?, ?) ON CONFLICT(path) DO UPDATE SET sig=excluded.sig')
                ->execute([$path, $signature]);
            if (!$force && $baseline && $known === false) {
                return;
            }
            $now = microtime(true);
            // PDO binds every value as text; expressions without column affinity need explicit casts.
            $this->db->prepare(<<<'SQL'
                INSERT INTO pending(path, rev, first, due) VALUES(?, 1, ?, ?)
                ON CONFLICT(path) DO UPDATE SET rev=rev+1, due=min(CAST(? AS REAL), first+CAST(? AS REAL)), attempts=0, error=NULL
                SQL)->execute([$path, $now, $now + $this->quiet, $now + $this->quiet, self::MAX_DEFERRAL]);
        });
    }

    /**
     * Next job, oldest first.
     *
     * Fresh paths are batched together. Paths that already failed are retried
     * among themselves in halving batches, so a broken resource is isolated
     * after a few attempts and never holds back new publications.
     *
     * @return list<QueuedPath>
     */
    public function nextBatch(int $size): array
    {
        $now = microtime(true);
        $head = $this->db->prepare('SELECT attempts FROM pending WHERE due<=? ORDER BY first LIMIT 1');
        $head->execute([$now]);
        $attempts = $head->fetchColumn();
        if ($attempts === false) {
            return [];
        }
        $attempts = (int) $attempts;
        $retry = $attempts > 0;
        $limit = $retry ? max(1, $size >> min($attempts, 30)) : $size;
        $statement = $this->db->prepare(sprintf(<<<'SQL'
            SELECT pending.path, pending.rev, pending.attempts, files.sig, files.documents
            FROM pending JOIN files USING(path)
            WHERE pending.due<=? AND pending.attempts %s 0 ORDER BY pending.first LIMIT ?
            SQL, $retry ? '>' : '='));
        $statement->execute([$now, $limit]);
        return array_map(QueuedPath::fromRow(...), $statement->fetchAll());
    }

    /** @param list<QueuedPath> $batch */
    public function failed(array $batch, string $error): void
    {
        $this->atomically(function () use ($batch, $error): void {
            $statement = $this->db->prepare('UPDATE pending SET attempts=?, error=?, due=? WHERE path=? AND rev=?');
            foreach ($batch as $item) {
                $attempts = $item->attempts + 1;
                $statement->execute([$attempts, substr($error, 0, 1500), microtime(true) + min(300, 2 ** min($attempts, 8)), $item->path, $item->rev]);
            }
        });
    }

    /**
     * Acknowledge one path. A newer revision stays queued, but the documents
     * are remembered either way so that it can remove what this job wrote.
     *
     * @param list<Document> $documents
     */
    public function finish(QueuedPath $item, array $documents): void
    {
        $this->atomically(function () use ($item, $documents): void {
            $this->db->prepare('UPDATE files SET documents=? WHERE path=?')->execute([self::encode($documents), $item->path]);
            $this->db->prepare('DELETE FROM pending WHERE path=? AND rev=?')->execute([$item->path, $item->rev]);
            // A deleted path with nothing left in the index needs no record.
            $this->db->prepare(<<<'SQL'
                DELETE FROM files WHERE path=? AND sig='' AND documents='[]'
                AND NOT EXISTS(SELECT 1 FROM pending WHERE pending.path=files.path)
                SQL)->execute([$item->path]);
        });
    }

    /**
     * Durably add every identity a job is about to write, before it writes.
     *
     * @param array<string, list<Document>> $planned
     */
    public function recordIntents(array $planned): void
    {
        $this->atomically(function () use ($planned): void {
            $select = $this->db->prepare('SELECT documents FROM files WHERE path=?');
            $update = $this->db->prepare('UPDATE files SET documents=? WHERE path=?');
            foreach ($planned as $path => $documents) {
                $select->execute([$path]);
                $known = $select->fetchColumn();
                if ($known === false) {
                    throw new \RuntimeException('intent for an unknown path: ' . $path);
                }
                $merged = self::decode($known) ?? [];
                foreach ($documents as $document) {
                    $present = false;
                    foreach ($merged as $existing) {
                        $present = $present || $existing->equals($document);
                    }
                    if (!$present) {
                        $merged[] = $document;
                    }
                }
                $update->execute([self::encode($merged), $path]);
            }
        });
    }

    /** @return list<QueuedPath> */
    public function pendingFor(string ...$paths): array
    {
        if ($paths === []) {
            return [];
        }
        $statement = $this->db->prepare(sprintf(<<<'SQL'
            SELECT pending.path, pending.rev, pending.attempts, files.sig, files.documents
            FROM pending JOIN files USING(path) WHERE pending.path IN (%s)
            SQL, implode(',', array_fill(0, count($paths), '?'))));
        $statement->execute($paths);
        return array_map(QueuedPath::fromRow(...), $statement->fetchAll());
    }

    /**
     * Existing baseline paths whose indexed identity is still unknown.
     *
     * @return list<string>
     */
    public function unidentified(int $limit): array
    {
        $statement = $this->db->prepare(<<<'SQL'
            SELECT path FROM files WHERE documents IS NULL AND sig != ''
            AND NOT EXISTS(SELECT 1 FROM pending WHERE pending.path=files.path) ORDER BY path LIMIT ?
            SQL);
        $statement->execute([$limit]);
        return array_column($statement->fetchAll(), 'path');
    }

    /**
     * Fill unknown identities; never overwrites what a job recorded.
     *
     * @param array<string, list<Document>> $identities
     */
    public function identify(array $identities): void
    {
        $this->atomically(function () use ($identities): void {
            $statement = $this->db->prepare('UPDATE files SET documents=? WHERE path=? AND documents IS NULL');
            foreach ($identities as $path => $documents) {
                $statement->execute([self::encode($documents), $path]);
            }
        });
    }

    /**
     * Known existing paths, optionally below a directory.
     *
     * @return array<string, string> path => signature
     */
    public function present(?string $directory = null): array
    {
        if ($directory === null || $directory === '/') {
            $statement = $this->db->query("SELECT path, sig FROM files WHERE sig != ''");
        } else {
            $prefix = rtrim($directory, '/') . '/';
            $statement = $this->db->prepare("SELECT path, sig FROM files WHERE sig != '' AND substr(path, 1, ?) = ?");
            $statement->execute([strlen($prefix), $prefix]);
        }
        // Fetched completely: callers update rows while they iterate.
        return array_column($statement->fetchAll(), 'sig', 'path');
    }

    /** @return list<Document>|null */
    public function documents(string $path): ?array
    {
        $statement = $this->db->prepare('SELECT documents FROM files WHERE path=?');
        $statement->execute([$path]);
        $value = $statement->fetchColumn();
        return $value === false ? null : self::decode($value);
    }

    /** @return array{pending: int, oldest_age_s: float, max_attempts: int, unidentified: int, errors: list<array{path: string, attempts: int, error: string}>} */
    public function statistics(): array
    {
        $row = $this->db->query('SELECT count(*) AS pending, min(first) AS oldest, coalesce(max(attempts), 0) AS max_attempts FROM pending')->fetch();
        $unidentified = (int) $this->db->query("SELECT count(*) FROM files WHERE documents IS NULL AND sig != ''")->fetchColumn();
        $errors = $this->db->query('SELECT path, attempts, error FROM pending WHERE error IS NOT NULL ORDER BY first LIMIT 5')->fetchAll();
        return [
            'pending' => (int) $row['pending'],
            'oldest_age_s' => $row['oldest'] === null ? 0.0 : round(microtime(true) - (float) $row['oldest'], 1),
            'max_attempts' => (int) $row['max_attempts'],
            'unidentified' => $unidentified,
            'errors' => array_map(static fn(array $error) => [
                'path' => $error['path'], 'attempts' => (int) $error['attempts'], 'error' => $error['error'],
            ], $errors),
        ];
    }

    /** @param list<Document> $documents */
    private static function encode(array $documents): string
    {
        return json_encode(array_values($documents), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @return list<Document>|null */
    public static function decode(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }
        return Document::listFromArray(json_decode((string) $value, true, 8, JSON_THROW_ON_ERROR));
    }
}

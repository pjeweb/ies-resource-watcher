<?php

declare(strict_types=1);

namespace StadtBraunschweig\IesResourceWatcher\Tests\Unit;

use PHPUnit\Framework\TestCase;
use StadtBraunschweig\IesResourceWatcher\Index\Document;
use StadtBraunschweig\IesResourceWatcher\Queue\QueueStore;
use StadtBraunschweig\IesResourceWatcher\Tests\TemporaryDirectory;

final class QueueStoreTest extends TestCase
{
    use TemporaryDirectory;

    private function store(): QueueStore
    {
        return new QueueStore($this->tmp . '/queue.sqlite', 0.0);
    }

    private function observer(): \PDO
    {
        return new \PDO('sqlite:' . $this->tmp . '/queue.sqlite');
    }

    public function testQueueAndIdentitySurviveReopen(): void
    {
        $store = $this->store();
        $store->observe('/a.php', 'v1');
        $store->finish($store->nextBatch(10)[0], [new Document('1', 'www', '/a.html')]);
        $store->observe('/a.php', 'v2');

        $reopened = $this->store();
        $item = $reopened->nextBatch(10)[0];
        self::assertSame('v2', $item->signature);
        self::assertEquals([new Document('1', 'www', '/a.html')], $item->documents);
    }

    public function testNewerEventCannotBeAcknowledgedByAnOlderJob(): void
    {
        $store = $this->store();
        $store->observe('/a.php', 'v1');
        $old = $store->nextBatch(1)[0];
        $store->observe('/a.php', 'v2', true);
        $store->finish($old, [new Document('1', 'www', '/a.html')]);

        $pending = $store->nextBatch(1)[0];
        self::assertSame('v2', $pending->signature);
        self::assertGreaterThan($old->rev, $pending->rev);
        // The older job's identity is still remembered for the newer one.
        self::assertEquals([new Document('1', 'www', '/a.html')], $pending->documents);
    }

    public function testRemovalKeepsIdentityUntilAcknowledged(): void
    {
        $store = $this->store();
        $store->observe('/a.php', 'v1');
        $store->finish($store->nextBatch(1)[0], [new Document('1', 'www', '/a.html')]);
        $store->observe('/a.php', '');
        $item = $store->nextBatch(1)[0];
        self::assertEquals([new Document('1', 'www', '/a.html')], $item->documents);

        $store->failed([$item], 'Solr unavailable');
        self::assertSame([], $store->nextBatch(1), 'backoff');
        self::assertSame(1, $store->statistics()['pending']);
        self::assertSame('Solr unavailable', $store->statistics()['errors'][0]['error']);
    }

    public function testAcknowledgedDeletionForgetsThePath(): void
    {
        $store = $this->store();
        $store->observe('/a.php', 'v1');
        $store->finish($store->nextBatch(1)[0], [new Document('1', 'www', '/a.html')]);
        $store->observe('/a.php', '');
        $store->finish($store->nextBatch(1)[0], []);
        self::assertSame(0, (int) $this->observer()->query('SELECT count(*) FROM files')->fetchColumn());
    }

    public function testBaselineRecordsWithoutQueueingButQueuesChangesOfKnownFiles(): void
    {
        $store = $this->store();
        $store->observe('/known.php', 'v1', false, true);
        self::assertSame(0, $store->statistics()['pending']);
        self::assertSame(['/known.php'], $store->unidentified(10));

        $store->observe('/known.php', 'v2', false, true);
        self::assertSame(1, $store->statistics()['pending']);
    }

    public function testEventsAreForcedEvenWithUnchangedContent(): void
    {
        $store = $this->store();
        $store->observe('/a.php', 'v1');
        $store->finish($store->nextBatch(1)[0], []);
        $store->observe('/a.php', 'v1');
        self::assertSame(0, $store->statistics()['pending']);
        $store->observe('/a.php', 'v1', true);
        self::assertSame(1, $store->statistics()['pending']);
    }

    public function testGroupedWritesCommitAtomicallyAndRollBack(): void
    {
        $store = $this->store();
        $store->observe('/a.php', 'baseline', false, true);
        $observer = $this->observer();
        try {
            $store->atomically(function () use ($store, $observer): void {
                $store->observe('/a.php', 'changed');
                self::assertSame('baseline', $observer->query('SELECT sig FROM files')->fetchColumn());
                throw new \RuntimeException('crash before commit');
            });
        } catch (\RuntimeException) {
        }
        self::assertSame('baseline', $observer->query('SELECT sig FROM files')->fetchColumn());
        self::assertSame(0, (int) $observer->query('SELECT count(*) FROM pending')->fetchColumn());

        $store->atomically(fn() => $store->observe('/a.php', 'changed'));
        self::assertSame('changed', $observer->query('SELECT sig FROM files')->fetchColumn());
        self::assertSame(1, (int) $observer->query('SELECT count(*) FROM pending')->fetchColumn());
    }

    public function testIntentsAreDurableAndSurviveDeletion(): void
    {
        $store = $this->store();
        $store->observe('/new.pdf.meta.php', 'new');
        $store->recordIntents(['/new.pdf.meta.php' => [new Document('12', 'www', '/new.pdf')]]);

        // A second process (the watcher) sees the intent immediately.
        $documents = $this->observer()->query('SELECT documents FROM files')->fetchColumn();
        self::assertSame('[{"id":"12","index":"www","url":"/new.pdf"}]', $documents);

        // Crash before acknowledgement; the file is deleted while the watcher is down.
        $store->observe('/new.pdf.meta.php', '');
        self::assertEquals([new Document('12', 'www', '/new.pdf')], $store->nextBatch(1)[0]->documents);
    }

    public function testIntentForUnknownPathIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->store()->recordIntents(['/unknown.php' => [new Document('1', 'www', '/x')]]);
    }

    public function testFailedPathsAreRetriedInHalvingBatchesWithoutBlockingFreshOnes(): void
    {
        $store = $this->store();
        foreach (range(1, 8) as $number) {
            $store->observe("/old$number.php", 'v');
        }
        $failed = $store->nextBatch(8);
        self::assertCount(8, $failed);
        $store->failed($failed, 'broken resource');
        $store->observe('/fresh.php', 'v');

        // Fresh paths never share a job with failed ones.
        self::assertSame(['/fresh.php'], array_map(fn($item) => $item->path, $store->nextBatch(8)));

        $this->observer()->exec('UPDATE pending SET due=0');
        $retry = $store->nextBatch(8);
        self::assertCount(4, $retry);
        $store->failed($retry, 'still broken');
        $this->observer()->exec('UPDATE pending SET due=0 WHERE attempts=1');
        self::assertCount(4, $store->nextBatch(8), 'the other half');
    }

    public function testIdentificationNeverOverwritesRecordedDocuments(): void
    {
        $store = $this->store();
        $store->observe('/a.php', 'v', false, true);
        $store->observe('/b.php', 'v', false, true);
        $store->observe('/c.php', 'v', false, true);
        $store->observe('/c.php', 'v2', true); // queued: not identified separately
        self::assertSame(['/a.php', '/b.php'], $store->unidentified(10));

        $store->identify(['/a.php' => [new Document('1', 'www', '/a.html')], '/b.php' => []]);
        $store->identify(['/a.php' => []]);
        self::assertEquals([new Document('1', 'www', '/a.html')], $store->documents('/a.php'));
        self::assertSame([], $store->documents('/b.php'));
        self::assertSame([], $store->unidentified(10));
        self::assertSame(1, $store->statistics()['unidentified']);
    }

    public function testPresentFiltersByDirectoryPrefix(): void
    {
        $store = $this->store();
        foreach (['/a/x.php', '/a/b/y.php', '/ab/z.php'] as $path) {
            $store->observe($path, 'v');
        }
        self::assertEqualsCanonicalizing(['/a/x.php', '/a/b/y.php'], array_keys($store->present('/a')));
    }
}

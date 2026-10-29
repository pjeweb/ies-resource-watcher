<?php

declare(strict_types=1);

namespace StadtBraunschweig\IesResourceWatcher\Watcher;

use StadtBraunschweig\IesResourceWatcher\FullIndex\FullIndexGuard;
use StadtBraunschweig\IesResourceWatcher\Index\Document;
use StadtBraunschweig\IesResourceWatcher\Index\IndexTarget;
use StadtBraunschweig\IesResourceWatcher\Index\PathPlan;
use StadtBraunschweig\IesResourceWatcher\Queue\QueuedPath;
use StadtBraunschweig\IesResourceWatcher\Queue\QueueStore;
use StadtBraunschweig\IesResourceWatcher\Runtime\EventLog;
use StadtBraunschweig\IesResourceWatcher\Runtime\JobLauncher;
use StadtBraunschweig\IesResourceWatcher\Runtime\Notifier;
use Symfony\Component\Process\Process;

/**
 * Long-running watcher: file events and scans feed the durable queue; one
 * job process at a time indexes a batch through the target.
 *
 * A job is acknowledged only if no native full index run overlapped it.
 * Otherwise it stays queued and is replayed after the run.
 */
final class Watcher
{
    /** Stored for a file that was being replaced while it was read; the next check compares again. */
    public const CHANGING = '~changing';

    private const SCAN_SLICE = 0.05;

    private QueueStore $store;
    private PathPolicy $policy;
    private Inotify $inotify;
    private FullIndexGuard $guard;

    /** @var resource */
    private $lock;

    private bool $baseline = false;
    private bool $initialized = false;
    private bool $ready = false;
    private bool $fullScanRequested = true;
    private ?\Generator $fullScan = null;
    private ?\Generator $subtreeScan = null;

    /** @var array<string, true> queued subtree scans, absolute directories */
    private array $subtrees = [];

    /** @var array<string, true> resource paths seen by the running full scan */
    private array $seen = [];

    /** @var array<string, true> directories registered since the running full scan began */
    private array $scanned = [];

    private ?string $scanError = null;
    private float $scanStarted = 0.0;
    private float $lastReconcile = 0.0;
    private float $lastStatus = 0.0;

    /** @var array{process: Process, action: string, batch: list<QueuedPath>, paths: list<string>, epoch: int, checkpoint: ?string, started: float}|null */
    private ?array $job = null;

    private int $fullEpoch = 0;
    private bool $fullBusy = false;
    private int $identifyFailures = 0;

    /** Halved after each failed identification job until the failing path is isolated. */
    private ?int $identifySize = null;
    private float $identifyAfter = 0.0;
    private int $successes = 0;
    private int $failures = 0;
    private bool $stopping = false;

    /** @var array<string, string|false> */
    private array $releases = [];

    public function __construct(
        private readonly WatcherConfig $config,
        private readonly IndexTarget $target,
        private readonly JobLauncher $launcher,
        private readonly EventLog $log = new EventLog(),
        private readonly Notifier $notifier = new Notifier(),
    ) {}

    public function stop(): void
    {
        $this->stopping = true;
    }

    public function run(): void
    {
        $this->open();
        try {
            while (!$this->stopping) {
                $this->tick();
            }
        } finally {
            $this->close();
        }
    }

    /** One iteration of the loop; public for tests. */
    public function tick(?float $wait = null): void
    {
        $busy = $this->guard->isBusy();
        if ($busy && !$this->fullBusy) {
            $this->fullEpoch++;
            $this->log->write('full_index_busy');
        } elseif ($this->fullBusy && !$busy) {
            $this->log->write('full_index_released');
        }
        $this->fullBusy = $busy;

        $this->events();
        $this->scans();
        $this->completeJob();
        $this->startJob();

        $now = microtime(true);
        if ($this->fullScan === null && $now - $this->lastReconcile >= $this->config->reconcileInterval) {
            $this->fullScanRequested = true;
        }
        if ($now - $this->lastStatus >= 2.0) {
            $this->checkReleases();
            $this->writeStatus();
            $this->lastStatus = $now;
        }
        if ($wait === null) {
            $wait = $this->fullScan !== null || $this->subtreeScan !== null || $this->subtrees !== [] ? 0.0
                : ($this->job !== null ? 0.05 : 0.2);
        }
        $read = [$this->inotify->stream()];
        $write = $except = null;
        // A signal interrupts select(); the loop then checks $stopping.
        @stream_select($read, $write, $except, (int) $wait, (int) (fmod($wait, 1.0) * 1e6));
    }

    public function open(): void
    {
        $directory = $this->config->stateDir;
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new \RuntimeException('cannot create state directory ' . $directory);
        }
        $lock = fopen($directory . '/watcher.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            throw new \RuntimeException('another watcher uses ' . $directory);
        }
        $this->lock = $lock;
        foreach ($this->config->restartOnChange as $path) {
            $this->releases[$path] = realpath($path);
        }
        $this->store = new QueueStore($this->config->queueFile(), $this->config->quietPeriod);
        $this->policy = new PathPolicy($this->target->layout(), $this->config->scopes, $this->config->prune);
        $this->guard = $this->target->fullIndexGuard();
        $this->initialized = $this->store->meta('initialized') !== null;
        $this->baseline = !$this->initialized
            || $this->store->meta('scopes') !== json_encode($this->policy->scopes)
            || $this->store->meta('root') !== $this->policy->root;
        $this->inotify = new Inotify();
        $this->log->write('started', [
            'root' => $this->policy->root, 'scopes' => $this->policy->scopes, 'baseline' => $this->baseline,
            // false may be fine before the first full run, or point to a wrong native cache directory.
            'full_index_status' => $this->guard->checkpoint() !== null,
        ]);
    }

    public function close(): void
    {
        if ($this->job !== null) {
            $this->job['process']->stop(10.0, \defined('SIGTERM') ? \SIGTERM : 15);
            $this->job = null;
        }
        $this->writeStatus();
        $this->inotify->close();
        $this->log->write('stopped', ['pending' => $this->store->statistics()['pending']]);
        flock($this->lock, LOCK_UN);
        fclose($this->lock);
    }

    private function events(): void
    {
        $events = $this->inotify->read();
        if ($events === []) {
            return;
        }
        // The complete group of events is durable before a job may start.
        $this->store->atomically(function () use ($events): void {
            foreach ($events as $event) {
                $this->event($event);
            }
        });
    }

    private function event(InotifyEvent $event): void
    {
        if ($event->is(Inotify::Q_OVERFLOW) || ($event->directory === null && !$event->is(Inotify::IGNORED))) {
            $this->requestFullScan('overflow or unknown watch');
            return;
        }
        if ($event->is(Inotify::IGNORED | Inotify::DELETE_SELF | Inotify::MOVE_SELF)) {
            // Subdirectories are handled through their parent's events. The
            // root, a scope or a directory leading to one needs a new scan.
            if ($event->directory !== null && $this->anchorsScope($event->directory)) {
                // Watches below it now follow the old tree under the old names.
                $this->inotify->remove($event->directory, true);
                $this->requestFullScan('scope directory moved or deleted');
            }
            return;
        }
        $absolute = (string) $event->path();
        try {
            $path = $this->policy->relative($absolute);
        } catch (\InvalidArgumentException) {
            return;
        }
        if ($event->is(Inotify::ISDIR)) {
            if ($event->is(Inotify::MOVED_FROM | Inotify::DELETE)) {
                $this->inotify->remove($absolute, true);
                $this->recheck($path);
            }
            if ($event->is(Inotify::CREATE | Inotify::MOVED_TO) && !$this->policy->skipsDirectory($event->name)) {
                if ($this->policy->inScope($path)) {
                    $this->subtrees[$absolute] = true;
                } elseif ($this->policy->leadsToScope($path)) {
                    $this->requestFullScan('directory leading to a scope appeared');
                }
            }
            return;
        }
        if ($event->is(Inotify::CLOSE_WRITE | Inotify::MOVED_TO | Inotify::MOVED_FROM | Inotify::DELETE)
            && $this->policy->isCandidate($path)) {
            // An event is evidence of a write even if the content hash is unchanged.
            $this->store->observe($path, $this->signature($path), true);
        }
    }

    /** Directory moved away or deleted: every known file below it is checked again. */
    private function recheck(string $directory): void
    {
        foreach ($this->store->present($directory) as $path => $signature) {
            $this->store->observe($path, $this->signature($path));
        }
    }

    private function anchorsScope(string $absolute): bool
    {
        if ($absolute === $this->policy->root) {
            return true;
        }
        try {
            $path = $this->policy->relative($absolute);
        } catch (\InvalidArgumentException) {
            return true; // an ancestor of the root
        }
        return in_array($path, $this->policy->scopes, true) || $this->policy->leadsToScope($path);
    }

    private function requestFullScan(string $reason): void
    {
        if (!$this->fullScanRequested) {
            $this->log->write('reconcile_requested', ['reason' => $reason]);
        }
        $this->fullScanRequested = true;
    }

    private function scans(): void
    {
        if ($this->fullScan === null && $this->fullScanRequested) {
            $this->fullScanRequested = false;
            $this->seen = [];
            $this->scanned = [];
            $this->scanError = null;
            $this->scanStarted = microtime(true);
            $this->fullScan = $this->fullScan();
        }
        if ($this->fullScan === null && $this->subtreeScan === null && $this->subtrees === []) {
            return;
        }
        $deadline = microtime(true) + self::SCAN_SLICE;
        // One transaction per slice instead of one disk synchronisation per file.
        // A crash before the commit leaves the old signature; the next scan sees it again.
        $this->store->atomically(function () use ($deadline): void {
            while (microtime(true) < $deadline) {
                if ($this->subtreeScan === null && $this->subtrees !== []) {
                    $directory = (string) array_key_first($this->subtrees);
                    unset($this->subtrees[$directory]);
                    $this->subtreeScan = $this->walk($directory, false);
                }
                // New directories go first: their files have no other way in.
                $scan = $this->subtreeScan ?? $this->fullScan;
                if ($scan === null) {
                    return;
                }
                if ($scan->valid()) {
                    $scan->next();
                    continue;
                }
                if ($scan === $this->subtreeScan) {
                    $this->subtreeScan = null;
                } else {
                    $this->fullScan = null;
                    $this->finishFullScan();
                }
            }
        });
    }

    /** @return \Generator<int, null> */
    private function fullScan(): \Generator
    {
        foreach ($this->policy->scopes as $scope) {
            $base = $this->policy->physical($scope);
            if (is_dir($base) && !is_link($base)) {
                yield from $this->walk($base, $this->baseline);
                continue;
            }
            // Watch the nearest existing ancestor so that the scope can appear later.
            $ancestor = dirname($base);
            while (!is_dir($ancestor) && $ancestor !== $this->policy->root) {
                $ancestor = dirname($ancestor);
            }
            if ($this->inotify->add($ancestor)) {
                $this->scanned[$ancestor] = true;
            }
        }
    }

    /**
     * Register watches below $base, then compare every resource file.
     *
     * Each directory is watched before it is listed, so a file written after
     * the listing produces an event.
     *
     * @return \Generator<int, null>
     */
    private function walk(string $base, bool $baseline): \Generator
    {
        $stack = [$base];
        while ($stack !== []) {
            $directory = array_pop($stack);
            if (!$this->inotify->add($directory)) {
                continue; // vanished; its parent reported that
            }
            $this->scanned[$directory] = true;
            $handle = @opendir($directory);
            if ($handle === false) {
                if (!is_dir($directory)) {
                    continue;
                }
                throw new \RuntimeException('cannot list ' . $directory . '; recursive watches are incomplete');
            }
            try {
                while (($name = readdir($handle)) !== false) {
                    if ($name === '.' || $name === '..') {
                        continue;
                    }
                    $absolute = $directory . '/' . $name;
                    if (preg_match('//u', $name) !== 1) {
                        $this->log->write('skipped_name', ['path' => $absolute, 'reason' => 'not UTF-8']);
                        continue;
                    }
                    if (is_link($absolute)) {
                        continue;
                    }
                    if (is_dir($absolute)) {
                        if (!$this->policy->skipsDirectory($name)) {
                            $stack[] = $absolute;
                        }
                        continue;
                    }
                    $path = $this->policy->relative($absolute);
                    if (!$this->policy->isCandidate($path)) {
                        continue;
                    }
                    $this->seen[$path] = true;
                    try {
                        $signature = FileSignature::of($absolute);
                    } catch (FileChangedWhileReading) {
                        continue; // being replaced; its own event follows
                    } catch (\RuntimeException $error) {
                        $this->scanError = $error->getMessage();
                        $this->log->write('scan_error', ['error' => $error->getMessage()]);
                        continue;
                    }
                    $this->store->observe($path, $signature, false, $baseline);
                    yield;
                }
            } finally {
                closedir($handle);
            }
            yield;
        }
    }

    private function finishFullScan(): void
    {
        $this->inotify->retain($this->scanned);
        foreach ($this->store->present() as $path => $signature) {
            if (!isset($this->seen[$path])) {
                $this->store->observe($path, $this->signature($path));
            }
        }
        $this->lastReconcile = microtime(true);
        $this->log->write('reconciled', [
            'files' => count($this->seen), 'watches' => $this->inotify->count(),
            'seconds' => round($this->lastReconcile - $this->scanStarted, 1), 'baseline' => $this->baseline,
        ]);
        $this->seen = $this->scanned = [];
        // A file that could not be read stays visible as scan_error, but does not
        // keep the channel from being watched: it is treated as new once readable.
        $this->store->setMeta('initialized', '1');
        $this->store->setMeta('scopes', (string) json_encode($this->policy->scopes));
        $this->store->setMeta('root', $this->policy->root);
        if (!$this->ready) {
            // Once per process, after the first complete scan, as with every restart.
            $this->notifier->notify("READY=1\nSTATUS=Watching publication and durable queue");
            $this->ready = true;
        }
        $this->initialized = true;
        $this->baseline = false;
    }

    private function signature(string $path): string
    {
        try {
            return FileSignature::of($this->policy->physical($path));
        } catch (FileChangedWhileReading) {
            return self::CHANGING;
        } catch (\InvalidArgumentException) {
            return ''; // now resolves outside the root: no longer part of the publication
        } catch (\RuntimeException $error) {
            // Unreadable right now (permissions, descriptors): queue it, and let the job report it.
            $this->log->write('read_error', ['path' => $path, 'error' => $error->getMessage()]);
            $this->requestFullScan('unreadable file');
            return self::CHANGING;
        }
    }

    private function startJob(): void
    {
        if ($this->job !== null || !$this->initialized || $this->fullBusy) {
            return;
        }
        $batch = $this->store->nextBatch($this->config->batchSize);
        if ($batch !== []) {
            foreach ($batch as $item) {
                try {
                    $current = $this->signature($item->path);
                } catch (\RuntimeException | \InvalidArgumentException $error) {
                    $this->store->failed([$item], $error->getMessage());
                    return;
                }
                if ($current !== $item->signature) {
                    // Changed since it was queued: wait for the next quiet period.
                    $this->store->observe($item->path, $current, true);
                    return;
                }
            }
            $checkpoint = $this->guard->checkpoint();
            if ($this->guard->isBusy()) {
                return;
            }
            $this->launch('sync', ['action' => 'sync', 'items' => $batch], $batch, [], $checkpoint);
            return;
        }
        if ($this->config->identifyBatchSize > 0 && microtime(true) >= $this->identifyAfter) {
            $paths = $this->store->unidentified($this->identifySize ?? $this->config->identifyBatchSize);
            if ($paths !== []) {
                // The budget leaves the rest of the timeout for a slow last resource and the index lookup.
                $request = ['action' => 'identify', 'paths' => $paths, 'budget' => $this->config->jobTimeout * 0.4];
                $this->launch('identify', $request, [], $paths, null);
            }
        }
    }

    /**
     * @param array<string, mixed> $request
     * @param list<QueuedPath> $batch
     * @param list<string> $paths
     */
    private function launch(string $action, array $request, array $batch, array $paths, ?string $checkpoint): void
    {
        try {
            $process = $this->launcher->start($request);
        } catch (\Throwable $error) {
            if ($action === 'identify') {
                $this->identifyFailed($paths, $error->getMessage());
            } else {
                $this->failures++;
                $this->store->failed($batch, 'cannot start job: ' . $error->getMessage());
            }
            return;
        }
        $this->job = [
            'process' => $process, 'action' => $action, 'batch' => $batch, 'paths' => $paths,
            'epoch' => $this->fullEpoch, 'checkpoint' => $checkpoint, 'started' => microtime(true),
        ];
    }

    private function completeJob(): void
    {
        if ($this->job === null) {
            return;
        }
        $job = $this->job;
        $process = $job['process'];
        $timedOut = false;
        if ($process->isRunning()) {
            if (microtime(true) - $job['started'] <= $this->config->jobTimeout) {
                return;
            }
            $process->stop(0.0, \defined('SIGKILL') ? \SIGKILL : 9);
            $timedOut = true;
        }
        $this->job = null;
        $seconds = round(microtime(true) - $job['started'], 3);
        try {
            $result = $this->result($process, $timedOut);
            if ($job['action'] === 'identify') {
                $this->identified($job['paths'], $result, $seconds);
            } else {
                $this->acknowledge($job, $result, $seconds);
            }
        } catch (\Throwable $error) {
            if ($job['action'] === 'identify') {
                $this->identifyFailed($job['paths'], $error->getMessage());
                return;
            }
            $this->failures++;
            $this->store->failed($job['batch'], $error->getMessage());
            $this->log->write('batch_retry', [
                'paths' => array_map(static fn(QueuedPath $item) => $item->path, $job['batch']),
                'error' => $error->getMessage(), 'seconds' => $seconds,
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function result(Process $process, bool $timedOut): array
    {
        if ($timedOut) {
            throw new \RuntimeException('job timed out and was killed');
        }
        $output = trim($process->getOutput());
        $data = $output === '' ? null : json_decode(substr($output, (int) strrpos("\n" . $output, "\n")), true);
        if (!is_array($data) || ($data['ok'] ?? false) !== true || $process->getExitCode() !== 0) {
            $error = is_array($data) && isset($data['error']) ? (string) $data['error']
                : 'job exited with ' . var_export($process->getExitCode(), true) . ': ' . substr(trim($process->getErrorOutput()), -1200);
            throw new \RuntimeException($error);
        }
        return $data;
    }

    /**
     * @param array{batch: list<QueuedPath>, epoch: int, checkpoint: ?string} $job
     * @param array<string, mixed> $result
     */
    private function acknowledge(array $job, array $result, float $seconds): void
    {
        // The full run's final cleanup may have removed what this job wrote.
        if ($this->guard->isBusy() || $job['epoch'] !== $this->fullEpoch || $job['checkpoint'] !== $this->guard->checkpoint()) {
            throw new \RuntimeException('native full index overlapped the job; replay after it');
        }
        /** @var array<string, array<string, mixed>> $plans */
        $plans = $result['paths'] ?? [];
        $this->store->atomically(function () use ($job, $plans): void {
            foreach ($job['batch'] as $item) {
                if (!isset($plans[$item->path])) {
                    throw new \RuntimeException('job result misses ' . $item->path);
                }
                $plan = PathPlan::fromArray($plans[$item->path]);
                $current = $this->signature($item->path);
                if ($current !== $item->signature) {
                    $this->store->observe($item->path, $current, true);
                }
                $this->store->finish($item, $plan->documents);
            }
        });
        $this->successes += count($job['batch']);
        $this->log->write('batch_ok', ['paths' => count($job['batch']), 'seconds' => $seconds]);
    }

    /**
     * @param list<string> $paths
     * @param array<string, mixed> $result
     */
    private function identified(array $paths, array $result, float $seconds): void
    {
        /** @var array<string, list<array{id: string, index: string, url: string}>> $documents */
        $documents = $result['documents'] ?? [];
        $identities = [];
        // Paths beyond the job's budget are absent and offered again. A job that
        // processed nothing counts as failed, so that halving isolates the cause.
        foreach ($paths as $path) {
            if (array_key_exists($path, $documents)) {
                $identities[$path] = array_map(Document::fromArray(...), $documents[$path]);
            }
        }
        if ($identities === []) {
            throw new \RuntimeException('identification processed no path');
        }
        $this->store->identify($identities);
        $this->identifyFailures = 0;
        $this->identifySize = null;
        $this->log->write('identified', ['paths' => count($identities), 'seconds' => $seconds]);
    }

    /** @param list<string> $paths */
    private function identifyFailed(array $paths, string $error): void
    {
        $this->log->write('identify_retry', ['paths' => count($paths), 'error' => $error]);
        if (count($paths) === 1) {
            // Isolated: it cannot be attributed, so a later deletion leaves its document to the native full run.
            $this->store->identify([$paths[0] => []]);
            $this->log->write('identify_skipped', ['path' => $paths[0]]);
            return;
        }
        $this->identifySize = max(1, intdiv(count($paths), 2));
        $this->identifyFailures++;
        $this->identifyAfter = microtime(true) + min(300, 2 ** min($this->identifyFailures, 8));
    }

    private function checkReleases(): void
    {
        foreach ($this->releases as $path => $target) {
            if (realpath($path) !== $target) {
                throw new \RuntimeException($path . ' changed; restarting to load the new release, the queue is kept');
            }
        }
    }

    /** @return array<string, mixed> */
    public function status(): array
    {
        $statistics = $this->store->statistics();
        return [
            'at' => gmdate('c'),
            'pid' => getmypid(),
            'root' => $this->policy->root,
            'scopes' => $this->policy->scopes,
            'watches' => $this->inotify->count(),
            'scanning' => $this->fullScan !== null ? 'full' : ($this->subtreeScan !== null ? 'subtree' : false),
            'initialized' => $this->initialized,
            'baseline' => $this->baseline,
            'scan_error' => $this->scanError,
            'full_index_busy' => $this->fullBusy,
            'job' => $this->job['action'] ?? null,
            'successes' => $this->successes,
            'failed_batches' => $this->failures,
        ] + $statistics;
    }

    private function writeStatus(): void
    {
        $status = $this->status();
        $file = $this->config->statusFile();
        file_put_contents($file . '.tmp', json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
        rename($file . '.tmp', $file);
        $this->notifier->notify(sprintf(
            "WATCHDOG=1\nSTATUS=Queue %d; oldest %.1fs; full index %s",
            $status['pending'],
            $status['oldest_age_s'],
            $this->fullBusy ? 'busy' : 'idle',
        ));
    }
}

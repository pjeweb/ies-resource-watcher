<?php

declare(strict_types=1);

namespace StadtBraunschweig\IesResourceWatcher\Tests\Integration;

use PHPUnit\Framework\TestCase;
use StadtBraunschweig\IesResourceWatcher\Index\Document;
use StadtBraunschweig\IesResourceWatcher\Queue\QueueStore;
use StadtBraunschweig\IesResourceWatcher\Runtime\EventLog;
use StadtBraunschweig\IesResourceWatcher\Runtime\JobLauncher;
use StadtBraunschweig\IesResourceWatcher\Tests\Fixtures\FakeTarget;
use StadtBraunschweig\IesResourceWatcher\Tests\TemporaryDirectory;
use StadtBraunschweig\IesResourceWatcher\Watcher\Watcher;
use StadtBraunschweig\IesResourceWatcher\Watcher\WatcherConfig;

/**
 * The watcher loop with real inotify, SQLite and job processes. The index is
 * a JSON file written by FakeTarget.
 */
final class WatcherTest extends TestCase
{
    use TemporaryDirectory {
        setUp as private createTemporaryDirectory;
    }

    private string $root;
    private string $control;
    private string $state;
    private ?Watcher $watcher = null;

    /** @var resource|null */
    private $log = null;

    protected function setUp(): void
    {
        $this->createTemporaryDirectory();
        foreach (['root', 'control', 'state'] as $name) {
            $this->{$name} = $this->tmp . '/' . $name;
            mkdir($this->{$name});
        }
        $this->root = (string) realpath($this->root);
    }

    protected function tearDown(): void
    {
        $this->stop();
        $this->remove($this->tmp);
    }

    /** @param array<string, mixed> $overrides */
    private function start(array $overrides = []): Watcher
    {
        $watcher = $overrides + ['state_dir' => $this->state, 'quiet_period' => 0.05, 'batch_size' => 8, 'job_timeout' => 20];
        file_put_contents($this->tmp . '/fixture.json', json_encode([
            'root' => $this->root, 'control' => $this->control, 'watcher' => $watcher,
        ]));
        $this->log = fopen($this->tmp . '/watcher.log', 'a');
        $this->watcher = new Watcher(
            WatcherConfig::fromArray($watcher),
            new FakeTarget($this->root, $this->control),
            new JobLauncher([\PHP_BINARY, dirname(__DIR__) . '/Fixtures/console.php', $this->tmp . '/fixture.json']),
            new EventLog($this->log),
        );
        $this->watcher->open();
        return $this->watcher;
    }

    private function stop(): void
    {
        if ($this->watcher !== null) {
            $this->watcher->close();
            $this->watcher = null;
            fclose($this->log);
        }
    }

    private function until(callable $condition, float $seconds = 15.0, string $message = ''): void
    {
        $deadline = microtime(true) + $seconds;
        while (!$condition()) {
            if (microtime(true) > $deadline) {
                self::fail('timed out waiting' . ($message !== '' ? ': ' . $message : '') . "\n" . file_get_contents($this->tmp . '/watcher.log'));
            }
            $this->watcher->tick(0.02);
        }
    }

    /** Ticks for a while; the condition must hold throughout. */
    private function during(float $seconds, callable $condition, string $message): void
    {
        $deadline = microtime(true) + $seconds;
        while (microtime(true) < $deadline) {
            $this->watcher->tick(0.02);
            self::assertTrue($condition(), $message);
        }
    }

    private function settled(): bool
    {
        $status = $this->watcher->status();
        return $status['initialized'] && $status['pending'] === 0 && $status['job'] === null
            && $status['scanning'] === false && $status['unidentified'] === 0;
    }

    /** @return array<string, array{url: string, content: string, path: string}> */
    private function index(): array
    {
        return FakeTarget::read($this->control . '/index.json');
    }

    private function put(string $path, string $content): void
    {
        $file = $this->root . $path;
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0755, true);
        }
        // Like rsync: a hidden temporary file renamed over the target.
        $temporary = dirname($file) . '/.' . basename($file) . '.' . bin2hex(random_bytes(3));
        file_put_contents($temporary, $content);
        rename($temporary, $file);
    }

    private function writes(): int
    {
        return is_file($this->control . '/writes') ? (int) filesize($this->control . '/writes') : 0;
    }

    public function testBaselineIsRecordedAndIdentifiedButNotReindexed(): void
    {
        $this->put('/old/a.php', 'v1');
        $this->put('/old/b.php', 'v1');
        file_put_contents($this->control . '/index.json', json_encode([
            'a' => ['url' => '/old/a.html', 'content' => 'v1', 'path' => '/old/a.php'],
        ]));
        $this->start();
        $this->until($this->settled(...), message: 'baseline');
        self::assertSame(0, $this->writes(), 'existing files are a baseline, not a reindex');

        $store = new QueueStore($this->state . '/queue.sqlite');
        self::assertEquals([FakeTarget::document('/old/a.php')], $store->documents('/old/a.php'));
        self::assertSame([], $store->documents('/old/b.php'));

        // A baseline file deleted later is removed with its identified identity.
        unlink($this->root . '/old/a.php');
        $this->until(fn() => !isset($this->index()['a']) && $this->settled());
    }

    public function testPublicationUpdateBurstAndDeletion(): void
    {
        $this->start();
        $this->until($this->settled(...));

        $this->put('/news/a.php', 'v1');
        $this->until(fn() => ($this->index()['a']['content'] ?? null) === 'v1' && $this->settled());

        $before = $this->writes();
        foreach (range(2, 6) as $version) {
            $this->put('/news/a.php', "v$version");
        }
        $this->until(fn() => ($this->index()['a']['content'] ?? null) === 'v6' && $this->settled());
        self::assertLessThanOrEqual(2, $this->writes() - $before, 'a burst is coalesced');

        unlink($this->root . '/news/a.php');
        $this->until(fn() => !isset($this->index()['a']) && $this->settled());

        $this->put('/news/hidden.php', 'noindex');
        $this->put('/news/a-1015t.php', 'preview');
        $this->put('/WEB-IES/vendor.php', 'x');
        $this->until($this->settled(...));
        self::assertSame([], $this->index());
    }

    public function testNewMovedAndDeletedDirectories(): void
    {
        $this->start(['reconcile_interval' => 3600]);
        $this->until($this->settled(...));

        // Files written before the new directory is watched still arrive.
        mkdir($this->root . '/new/deep', 0755, true);
        file_put_contents($this->root . '/new/deep/x.php', 'x1');
        file_put_contents($this->root . '/new/y.php', 'y1');
        $this->until(fn() => isset($this->index()['x'], $this->index()['y']) && $this->settled(), message: 'new tree');

        rename($this->root . '/new', $this->root . '/renamed');
        $this->until(fn() => ($this->index()['x']['url'] ?? null) === '/renamed/deep/x.html'
            && ($this->index()['y']['url'] ?? null) === '/renamed/y.html' && $this->settled(), message: 'renamed tree');

        rename($this->root . '/renamed', $this->tmp . '/outside');
        $this->until(fn() => $this->index() === [] && $this->settled(), message: 'moved out');

        // A file in the old location is still watched under its new name only.
        rename($this->tmp . '/outside', $this->root . '/back');
        $this->put('/back/deep/x.php', 'x2');
        $this->until(fn() => ($this->index()['x']['content'] ?? null) === 'x2' && $this->settled(), message: 'moved back');
        self::assertSame(['/back/deep/x.html', '/back/y.html'], [$this->index()['x']['url'], $this->index()['y']['url']]);
    }

    public function testChangesWhileStoppedAreReconciled(): void
    {
        $this->start();
        $this->until($this->settled(...));
        $this->put('/a.php', 'v1');
        $this->put('/b.php', 'v1');
        $this->until(fn() => count($this->index()) === 2 && $this->settled());
        $this->stop();

        $this->put('/a.php', 'v2');
        unlink($this->root . '/b.php');
        $this->put('/c.php', 'v1');

        $this->start();
        $this->until(fn() => ($this->index()['a']['content'] ?? null) === 'v2' && !isset($this->index()['b'])
            && isset($this->index()['c']) && $this->settled());
        self::assertSame(['a', 'c'], array_keys($this->index()));
    }

    public function testBackendFailureIsRetriedDurably(): void
    {
        $this->start();
        $this->until($this->settled(...));
        touch($this->control . '/fail');
        $this->put('/a.php', 'v1');
        $this->until(fn() => $this->watcher->status()['failed_batches'] > 0);
        $this->stop();
        $this->start();
        $this->during(0.5, fn() => $this->watcher->status()['pending'] === 1, 'kept across a restart');
        unlink($this->control . '/fail');
        $this->until(fn() => isset($this->index()['a']) && $this->settled(), 30);
    }

    public function testBrokenResourceDoesNotHoldBackOthers(): void
    {
        $this->start();
        $this->until($this->settled(...));
        $this->put('/broken.php', 'broken');
        $this->put('/a.php', 'v1');
        $this->until(fn() => $this->watcher->status()['failed_batches'] > 0);
        $this->put('/fresh.php', 'v1');
        $this->until(fn() => isset($this->index()['fresh']), 5, 'fresh path indexed while the broken one waits');
        $this->until(fn() => isset($this->index()['a']), 20, 'isolated from the broken path');
        $this->until(fn() => $this->watcher->status()['job'] === null);
        self::assertSame(1, $this->watcher->status()['pending'], (string) json_encode($this->watcher->status()));
    }

    public function testFullIndexBlocksJobsAndOverlapIsReplayed(): void
    {
        $this->start();
        $this->until($this->settled(...));
        $lock = fopen($this->control . '/full.lock', 'c');

        flock($lock, LOCK_EX);
        $this->put('/a.php', 'v1');
        $this->during(1.0, fn() => $this->writes() === 0, 'no job while a full run holds the lock');
        flock($lock, LOCK_UN);
        $this->until(fn() => isset($this->index()['a']) && $this->settled());

        // A full run starting during the job: its result is not acknowledged.
        touch($this->control . '/slow');
        $this->put('/a.php', 'v2');
        $this->until(fn() => $this->watcher->status()['job'] === 'sync');
        flock($lock, LOCK_EX);
        $this->until(fn() => $this->watcher->status()['failed_batches'] > 0, message: 'overlap rejected');
        self::assertSame(1, $this->watcher->status()['pending']);
        unlink($this->control . '/slow');
        flock($lock, LOCK_UN);
        $this->until(fn() => ($this->index()['a']['content'] ?? null) === 'v2' && $this->settled(), 30);
        fclose($lock);
    }

    public function testCrashAfterIntentStillRemovesWhatWasWritten(): void
    {
        $this->start();
        $this->until($this->settled(...));
        touch($this->control . '/crash-after-write');
        $this->put('/media.pdf.meta.php', 'v1');
        $this->until(fn() => $this->watcher->status()['failed_batches'] > 0);
        self::assertArrayHasKey('media.pdf.meta', $this->index(), 'written, then the job died');
        $this->stop();

        // Deleted while the watcher is down: the recorded intent removes it.
        unlink($this->root . '/media.pdf.meta.php');
        $store = new QueueStore($this->state . '/queue.sqlite');
        self::assertEquals([FakeTarget::document('/media.pdf.meta.php')], $store->documents('/media.pdf.meta.php'));
        $this->start();
        $this->until(fn() => $this->index() === [] && $this->settled(), 30);
    }

    public function testRenamedDocumentSurvivesTheLateDeletionOfItsOldPath(): void
    {
        $this->start();
        $this->until($this->settled(...));
        $this->put('/a/doc.php', 'v1');
        $this->until(fn() => isset($this->index()['doc']) && $this->settled());

        mkdir($this->root . '/b');
        rename($this->root . '/a/doc.php', $this->root . '/b/doc.php');
        $this->until(fn() => ($this->index()['doc']['url'] ?? null) === '/b/doc.html' && $this->settled());
        self::assertSame('/b/doc.html', $this->index()['doc']['url']);
    }

    public function testHungJobIsKilledAndRetried(): void
    {
        $this->start(['job_timeout' => 2]);
        $this->until($this->settled(...));
        touch($this->control . '/hang');
        $this->put('/a.php', 'v1');
        $this->until(fn() => $this->watcher->status()['failed_batches'] > 0, message: 'killed after the timeout');
        self::assertStringContainsString('timed out', (string) file_get_contents($this->tmp . '/watcher.log'));
        $this->until(fn() => isset($this->index()['a']) && $this->settled(), 30);
    }

    public function testUndecodableNameUnreadableFileAndQuietConsoleDoNotStopIndexing(): void
    {
        // A console started with --quiet exports this to its children.
        putenv('SHELL_VERBOSITY=-1');
        $_ENV['SHELL_VERBOSITY'] = $_SERVER['SHELL_VERBOSITY'] = '-1';
        try {
            $this->start();
            $this->until($this->settled(...));
            $this->put("/latin1-\xfc.php", 'v1');
            $this->put('/locked.php', 'v1');
            chmod($this->root . '/locked.php', 0);
            $this->put('/a.php', 'v1');
            $this->until(fn() => isset($this->index()['a']), message: 'indexed despite the others');
            self::assertStringContainsString('read_error', (string) file_get_contents($this->tmp . '/watcher.log'));
            $this->watcher->status(); // still alive

            // A full scan reports the name instead of failing on it.
            $reconciled = fn() => substr_count((string) file_get_contents($this->tmp . '/watcher.log'), '"event":"reconciled"');
            $this->stop();
            $before = $reconciled();
            $this->start();
            $this->until(fn() => $reconciled() > $before);
            self::assertStringContainsString('skipped_name', (string) file_get_contents($this->tmp . '/watcher.log'));
            self::assertNotNull($this->watcher->status()['scan_error'], 'the unreadable file stays visible');
            chmod($this->root . '/locked.php', 0644);
        } finally {
            putenv('SHELL_VERBOSITY');
            unset($_ENV['SHELL_VERBOSITY'], $_SERVER['SHELL_VERBOSITY']);
        }
    }

    public function testReplacedScopeDirectoryIsWatchedAgain(): void
    {
        mkdir($this->root . '/live');
        $this->start(['scopes' => ['/live']]);
        $this->until($this->settled(...));
        $this->put('/live/a.php', 'v1');
        $this->until(fn() => isset($this->index()['a']) && $this->settled());

        // Swap in a new tree under the same name, as a deployment would.
        $this->put('/live.new/b.php', 'v1');
        rename($this->root . '/live', $this->root . '/live.old');
        rename($this->root . '/live.new', $this->root . '/live');
        $this->until(fn() => isset($this->index()['b']) && !isset($this->index()['a']) && $this->settled(), message: 'new tree');

        $this->put('/live/c.php', 'v1');
        $this->put('/live.old/stale.php', 'v1');
        $this->until(fn() => isset($this->index()['c']) && $this->settled(), 5, 'events of the new tree');
        self::assertArrayNotHasKey('stale', $this->index());
        self::assertSame('/live/c.html', $this->index()['c']['url']);
    }

    public function testFailingIdentificationIsIsolated(): void
    {
        foreach (range(1, 6) as $number) {
            $this->put("/old/$number.php", 'v1');
        }
        $this->put('/old/x.php', 'unidentifiable');
        $this->start(['identify_batch_size' => 8]);
        $this->until($this->settled(...), 60, 'identification completes around the failing path');
        self::assertStringContainsString('identify_skipped', (string) file_get_contents($this->tmp . '/watcher.log'));
        self::assertSame([], (new QueueStore($this->state . '/queue.sqlite'))->documents('/old/x.php'));
    }

    public function testSecondWatcherOnTheSameStateIsRefused(): void
    {
        $this->start();
        $second = new Watcher(
            WatcherConfig::fromArray(['state_dir' => $this->state]),
            new FakeTarget($this->root, $this->control),
            new JobLauncher([\PHP_BINARY, '-r', '']),
        );
        $this->expectExceptionMessage('another watcher');
        $second->open();
    }

    public function testDocumentMismatchKeepsPathQueued(): void
    {
        $this->start();
        $this->until($this->settled(...));
        touch($this->control . '/crash-before-write');
        $this->put('/a.php', 'v1');
        $this->until(fn() => $this->watcher->status()['failed_batches'] > 0);
        self::assertSame([], $this->index());
        $this->until(fn() => isset($this->index()['a']) && $this->settled(), 30);
        self::assertEquals([new Document('a', 'test', '/a.html')], (new QueueStore($this->state . '/queue.sqlite'))->documents('/a.php'));
    }
}

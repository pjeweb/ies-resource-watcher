<?php

declare(strict_types=1);

namespace StadtBraunschweig\IesResourceWatcher\Tests\Unit;

use PHPUnit\Framework\TestCase;
use StadtBraunschweig\IesResourceWatcher\FullIndex\FlockProbe;
use StadtBraunschweig\IesResourceWatcher\FullIndex\NativeIndexGuard;
use StadtBraunschweig\IesResourceWatcher\FullIndex\SysvSemaphoreProbe;
use StadtBraunschweig\IesResourceWatcher\Tests\TemporaryDirectory;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Lock\Store\SemaphoreStore;

final class FullIndexTest extends TestCase
{
    use TemporaryDirectory;

    public function testFlockInspectionNeitherAcquiresNorReleasesTheNativeLock(): void
    {
        $file = $this->tmp . '/native.lock';
        $held = fopen($file, 'c');
        $probe = new FlockProbe($file);
        self::assertFalse($probe->isHeld());
        flock($held, LOCK_EX | LOCK_NB);
        self::assertTrue($probe->isHeld());

        $other = fopen($file, 'r');
        self::assertFalse(flock($other, LOCK_SH | LOCK_NB), 'still exclusively held after the probe');
        flock($held, LOCK_UN);
        self::assertFalse($probe->isHeld());
        fclose($other);
        fclose($held);
    }

    public function testFlockProbeRecognisesASymfonyLock(): void
    {
        // The same file name the Atoolo adapter derives from the lock key.
        $key = 'indexer.braunschweig-www';
        $file = sprintf('%s/sf.%s.%s.lock', $this->tmp, substr((string) preg_replace('/[^a-z0-9\._-]+/i', '-', $key), 0, 50),
            strtr(substr(base64_encode(hash('sha256', $key, true)), 0, 7), '/', '_'));
        $lock = (new LockFactory(new FlockStore($this->tmp)))->createLock($key);
        self::assertTrue($lock->acquire());
        self::assertTrue((new FlockProbe($file))->isHeld());
        $lock->release();
        self::assertFalse((new FlockProbe($file))->isHeld());
    }

    public function testSemaphoreInspectionIsReadOnly(): void
    {
        if (!class_exists(\SysvSemaphore::class) && !function_exists('sem_get')) {
            self::markTestSkipped('sysvsem not available');
        }
        $key = 'indexer.test-' . getmypid();
        $id = unpack('i', hash('xxh128', $key, true))[1];
        $probe = new SysvSemaphoreProbe($id);
        self::assertFalse($probe->isHeld());

        $lock = (new LockFactory(new SemaphoreStore()))->createLock($key);
        self::assertTrue($lock->acquire());
        self::assertTrue($probe->isHeld());
        self::assertTrue($probe->isHeld(), 'probing twice does not change it');
        $lock->release();
        self::assertFalse($probe->isHeld());
    }

    public function testCheckpointFollowsOnlyTheStartOfAFullRun(): void
    {
        $status = $this->tmp . '/status.json';
        $guard = new NativeIndexGuard(new FlockProbe($this->tmp . '/none.lock'), $status, 'startTime');
        file_put_contents($status, '{"startTime":"2026-10-29T03:00:00+01:00","processed":10}');
        $run = $guard->checkpoint();
        // A native single-document update rewrites the file but keeps the start time.
        file_put_contents($status, '{"startTime":"2026-10-29T03:00:00+01:00","processed":11,"updated":1}');
        self::assertSame($run, $guard->checkpoint());
        file_put_contents($status, '{"startTime":"2026-10-30T03:00:00+01:00","processed":0}');
        self::assertNotSame($run, $guard->checkpoint());
        file_put_contents($status, '{"startTime"');
        self::assertStringNotContainsString('run:', (string) $guard->checkpoint(), 'unreadable falls back to file metadata');
    }

    public function testCheckpointChangesWhenTheStatusFileIsRewritten(): void
    {
        $status = $this->tmp . '/status.json';
        $guard = new NativeIndexGuard(new FlockProbe($this->tmp . '/none.lock'), $status);
        self::assertNull($guard->checkpoint());
        file_put_contents($status, '{"processed":1}');
        $first = $guard->checkpoint();
        file_put_contents($status . '.new', '{"processed":2}');
        rename($status . '.new', $status);
        self::assertNotSame($first, $guard->checkpoint());
        self::assertFalse($guard->isBusy());
    }
}

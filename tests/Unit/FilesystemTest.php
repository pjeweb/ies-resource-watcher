<?php

declare(strict_types=1);

namespace StadtBraunschweig\IesResourceWatcher\Tests\Unit;

use PHPUnit\Framework\TestCase;
use StadtBraunschweig\IesResourceWatcher\Index\ResourceLayout;
use StadtBraunschweig\IesResourceWatcher\Tests\TemporaryDirectory;
use StadtBraunschweig\IesResourceWatcher\Watcher\FileSignature;
use StadtBraunschweig\IesResourceWatcher\Watcher\Inotify;
use StadtBraunschweig\IesResourceWatcher\Watcher\PathPolicy;

final class FilesystemTest extends TestCase
{
    use TemporaryDirectory;

    public function testSignatureDetectsContentChangeWithPreservedSizeAndTime(): void
    {
        $file = $this->tmp . '/a.php';
        file_put_contents($file, 'aaaa');
        $before = FileSignature::of($file);
        $mtime = filemtime($file);
        file_put_contents($file, 'bbbb');
        touch($file, $mtime);
        self::assertNotSame($before, FileSignature::of($file));
    }

    public function testSignatureOfMissingFileOrSymlinkIsEmpty(): void
    {
        file_put_contents($this->tmp . '/target.php', 'x');
        symlink($this->tmp . '/target.php', $this->tmp . '/link.php');
        self::assertSame('', FileSignature::of($this->tmp . '/missing.php'));
        self::assertSame('', FileSignature::of($this->tmp . '/link.php'));
        self::assertSame('', FileSignature::of($this->tmp));
        self::assertNotSame('', FileSignature::of($this->tmp . '/target.php'));
    }

    public function testPolicyBlocksVendorTemporaryScaledPreviewAndEscapes(): void
    {
        mkdir($this->tmp . '/root');
        $layout = new ResourceLayout($this->tmp . '/root', ['.php'], ['WEB-IES'], ['.translations'], ['#-1015t\.php#']);
        $policy = new PathPolicy($layout, ['/content'], ['*.scaled']);
        foreach (['/other/a.php', '/content/../x.php', '/content/WEB-IES/a.php', '/content/.a.php',
                  '/content/p.jpg.scaled/x.php', '/content/sp_a.tmp', '/content/a-1015t.php',
                  '/content/.git/a.php', '/content'] as $path) {
            self::assertFalse($policy->isCandidate($path), $path);
        }
        self::assertTrue($policy->isCandidate('/content/.translations/en/a.php'));
        self::assertTrue($policy->isCandidate('/content/a.php.translations/en_US.php'));
        self::assertTrue($policy->leadsToScope('/'));
        self::assertFalse($policy->leadsToScope('/other'));

        mkdir($this->tmp . '/outside');
        symlink($this->tmp . '/outside', $this->tmp . '/root/link');
        $this->expectException(\InvalidArgumentException::class);
        $policy->physical('/link/a.php');
    }

    public function testDirectoryRenameMovesTheWatchAndStaleWatchesArePruned(): void
    {
        $inotify = new Inotify();
        mkdir($this->tmp . '/a');
        self::assertTrue($inotify->add($this->tmp . '/a'));
        rename($this->tmp . '/a', $this->tmp . '/b');
        self::assertTrue($inotify->add($this->tmp . '/b'));
        self::assertFalse($inotify->isWatched($this->tmp . '/a'));
        self::assertFalse($inotify->add($this->tmp . '/missing'));

        $inotify->retain([]);
        self::assertSame(0, $inotify->count());
        $inotify->close();
    }

    public function testAtomicReplacementIsReportedInItsDirectory(): void
    {
        $inotify = new Inotify();
        $inotify->add($this->tmp);
        file_put_contents($this->tmp . '/.a.php.tmp', 'new');
        rename($this->tmp . '/.a.php.tmp', $this->tmp . '/a.php');
        unlink($this->tmp . '/a.php');
        $events = [];
        foreach ($inotify->read() as $event) {
            $events[] = [$event->mask & ~Inotify::ISDIR, $event->name];
        }
        self::assertContains([Inotify::MOVED_TO, 'a.php'], $events);
        self::assertContains([Inotify::DELETE, 'a.php'], $events);

        // Removing a watch ourselves produces no event.
        $inotify->remove($this->tmp);
        self::assertSame([], $inotify->read());
        $inotify->close();
    }
}

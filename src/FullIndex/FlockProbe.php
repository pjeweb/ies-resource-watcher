<?php

declare(strict_types=1);

namespace StadtBraunschweig\IesResourceWatcher\FullIndex;

/**
 * Detects an exclusive flock() on a file by reading /proc/locks (Linux).
 */
final class FlockProbe implements LockProbe
{
    public function __construct(
        private readonly string $file,
        private readonly string $locks = '/proc/locks',
    ) {}

    public function isHeld(): bool
    {
        clearstatcache(true, $this->file);
        $stat = @stat($this->file);
        if ($stat === false) {
            return false;
        }
        $wanted = [self::major($stat['dev']), self::minor($stat['dev']), $stat['ino']];
        $table = file_get_contents($this->locks);
        if ($table === false) {
            throw new \RuntimeException('cannot read ' . $this->locks);
        }
        preg_match_all('/\bFLOCK\s+ADVISORY\s+WRITE\s+\S+\s+([0-9a-f]+):([0-9a-f]+):(\d+)/i', $table, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            if ([hexdec($match[1]), hexdec($match[2]), (int) $match[3]] === $wanted) {
                return true;
            }
        }
        return false;
    }

    public function describe(): array
    {
        return ['type' => 'flock', 'file' => $this->file];
    }

    /** glibc gnu_dev_major() */
    private static function major(int $device): int
    {
        return (($device >> 8) & 0xfff) | (($device >> 32) & ~0xfff);
    }

    /** glibc gnu_dev_minor() */
    private static function minor(int $device): int
    {
        return ($device & 0xff) | (($device >> 12) & ~0xff);
    }
}

<?php

declare(strict_types=1);

namespace StadtBraunschweig\IesResourceWatcher\Watcher;

/**
 * Content signature of a regular file; '' if it is absent or not a regular file.
 *
 * Hashes the content because rsync can preserve size and modification time.
 */
final class FileSignature
{
    private const TYPE_MASK = 0170000;
    private const REGULAR = 0100000;

    /** @throws FileChangedWhileReading */
    public static function of(string $file): string
    {
        clearstatcache(true, $file);
        $before = @lstat($file);
        if ($before === false || ($before['mode'] & self::TYPE_MASK) !== self::REGULAR) {
            return '';
        }
        $stream = @fopen($file, 'rb');
        if ($stream === false) {
            clearstatcache(true, $file);
            if (@lstat($file) === false) {
                return '';
            }
            throw new \RuntimeException('cannot read ' . $file);
        }
        try {
            // fopen() follows symlinks. Comparing the opened inode with lstat()
            // closes the race in which the path was swapped for a link.
            $opened = fstat($stream);
            if ($opened === false || $opened['ino'] !== $before['ino'] || $opened['dev'] !== $before['dev']) {
                throw new FileChangedWhileReading($file);
            }
            $context = hash_init('sha256');
            hash_update_stream($context, $stream);
            $digest = hash_final($context);
        } finally {
            fclose($stream);
        }
        clearstatcache(true, $file);
        $after = @lstat($file);
        if ($after === false) {
            return '';
        }
        foreach (['ino', 'size', 'mtime', 'ctime'] as $field) {
            if ($after[$field] !== $before[$field]) {
                throw new FileChangedWhileReading($file);
            }
        }
        return implode(':', [$after['ino'], $after['size'], $after['mtime'], $digest]);
    }
}

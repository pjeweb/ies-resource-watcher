<?php

declare(strict_types=1);

namespace StadtBraunschweig\IesResourceWatcher\Watcher;

/**
 * Linux inotify through FFI on libc; needs no PECL extension.
 *
 * Directories are watched rather than files, so an atomic rename that
 * replaces a file (rsync, IES) is seen as MOVED_TO in its directory.
 */
final class Inotify
{
    public const CLOSE_WRITE = 0x00000008;
    public const MOVED_FROM = 0x00000040;
    public const MOVED_TO = 0x00000080;
    public const CREATE = 0x00000100;
    public const DELETE = 0x00000200;
    public const DELETE_SELF = 0x00000400;
    public const MOVE_SELF = 0x00000800;
    public const Q_OVERFLOW = 0x00004000;
    public const IGNORED = 0x00008000;
    public const ISDIR = 0x40000000;

    private const ONLYDIR = 0x01000000;
    private const DONT_FOLLOW = 0x02000000;
    private const MASK = self::CLOSE_WRITE | self::MOVED_FROM | self::MOVED_TO | self::CREATE | self::DELETE
        | self::DELETE_SELF | self::MOVE_SELF | self::ONLYDIR | self::DONT_FOLLOW;
    private const NONBLOCK = 0x800;
    private const CLOEXEC = 0x80000;
    private const ENOENT = 2;
    private const ENOTDIR = 20;

    private static ?\FFI $libc = null;

    private int $fd;

    /** @var resource */
    private $stream;

    /** @var array<int, string> watch descriptor => directory */
    private array $directories = [];

    /** @var array<string, int> directory => watch descriptor */
    private array $descriptors = [];

    /** @var array<int, string> watch descriptor => device:inode of the watched directory */
    private array $inodes = [];

    /** @var array<int, true> descriptors removed by us; their IGNORED event is expected */
    private array $released = [];

    private string $buffer = '';

    public function __construct()
    {
        $this->fd = self::libc()->inotify_init1(self::NONBLOCK | self::CLOEXEC);
        if ($this->fd < 0) {
            throw new \RuntimeException('inotify_init1 failed: errno ' . self::errno());
        }
        // php://fd duplicates the descriptor; both refer to the same inotify instance.
        $stream = fopen('php://fd/' . $this->fd, 'rb');
        if ($stream === false) {
            self::libc()->close($this->fd);
            throw new \RuntimeException('cannot open inotify stream');
        }
        stream_set_read_buffer($stream, 0);
        stream_set_blocking($stream, false);
        $this->stream = $stream;
    }

    public function __destruct()
    {
        $this->close();
    }

    public function close(): void
    {
        if ($this->fd >= 0) {
            fclose($this->stream);
            self::libc()->close($this->fd);
            $this->fd = -1;
        }
    }

    /** @return resource for stream_select() */
    public function stream()
    {
        return $this->stream;
    }

    /**
     * Watch a directory. Returns false if it vanished in the meantime.
     *
     * Adding an already watched inode under a new name (after a rename)
     * returns the same descriptor; the mapping follows the new name. A name
     * that now refers to another directory (replaced by a rename) is
     * watched anew.
     */
    public function add(string $directory): bool
    {
        if (isset($this->descriptors[$directory])) {
            if ($this->inodes[$this->descriptors[$directory]] === self::inode($directory)) {
                return true;
            }
            $this->remove($directory);
        }
        $wd = self::libc()->inotify_add_watch($this->fd, $directory, self::MASK);
        if ($wd < 0) {
            $error = self::errno();
            if ($error === self::ENOENT || $error === self::ENOTDIR) {
                return false;
            }
            // ENOSPC: fs.inotify.max_user_watches is exhausted.
            throw new \RuntimeException(sprintf('inotify_add_watch %s failed: errno %d', $directory, $error));
        }
        $previous = $this->directories[$wd] ?? null;
        if ($previous !== null && $previous !== $directory) {
            unset($this->descriptors[$previous]);
        }
        $this->directories[$wd] = $directory;
        $this->descriptors[$directory] = $wd;
        $this->inodes[$wd] = self::inode($directory);
        unset($this->released[$wd]);
        return true;
    }

    private static function inode(string $directory): string
    {
        clearstatcache(true, $directory);
        $stat = @stat($directory);
        return $stat === false ? '' : $stat['dev'] . ':' . $stat['ino'];
    }

    public function isWatched(string $directory): bool
    {
        return isset($this->descriptors[$directory]);
    }

    public function count(): int
    {
        return count($this->descriptors);
    }

    /** @return list<string> */
    public function watched(): array
    {
        return array_keys($this->descriptors);
    }

    /**
     * Stop watching a directory and, with $recursive, everything below it.
     */
    public function remove(string $directory, bool $recursive = false): void
    {
        $prefix = rtrim($directory, '/') . '/';
        foreach ($this->descriptors as $path => $wd) {
            if ($path === $directory || ($recursive && str_starts_with($path, $prefix))) {
                self::libc()->inotify_rm_watch($this->fd, $wd);
                unset($this->descriptors[$path], $this->directories[$wd], $this->inodes[$wd]);
                $this->released[$wd] = true;
            }
        }
    }

    /**
     * Keep only the given directories.
     *
     * @param array<string, true> $wanted
     */
    public function retain(array $wanted): void
    {
        foreach (array_keys($this->descriptors) as $directory) {
            if (!isset($wanted[$directory])) {
                $this->remove($directory);
            }
        }
    }

    /**
     * Events available now, without blocking.
     *
     * @return list<InotifyEvent>
     */
    public function read(): array
    {
        while (true) {
            $data = fread($this->stream, 65536);
            if ($data === false || $data === '') {
                break;
            }
            $this->buffer .= $data;
        }
        $events = [];
        $offset = 0;
        $length = strlen($this->buffer);
        while ($length - $offset >= 16) {
            /** @var array{wd: int, mask: int, cookie: int, len: int} $header */
            $header = unpack('lwd/Lmask/Lcookie/Llen', $this->buffer, $offset);
            if ($length - $offset < 16 + $header['len']) {
                break;
            }
            $name = rtrim(substr($this->buffer, $offset + 16, $header['len']), "\0");
            $offset += 16 + $header['len'];
            $wd = $header['wd'];
            $mask = $header['mask'];
            $directory = $this->directories[$wd] ?? null;
            if ($mask & self::IGNORED) {
                if ($directory !== null) {
                    unset($this->directories[$wd], $this->descriptors[$directory], $this->inodes[$wd]);
                }
                if (isset($this->released[$wd])) {
                    unset($this->released[$wd]);
                    continue;
                }
            }
            if ($directory === null && !($mask & (self::Q_OVERFLOW | self::IGNORED)) && isset($this->released[$wd])) {
                continue; // late event of a directory we stopped watching
            }
            $events[] = new InotifyEvent($mask, $directory, $name, $header['cookie']);
        }
        $this->buffer = substr($this->buffer, $offset);
        return $events;
    }

    private static function libc(): \FFI
    {
        return self::$libc ??= \FFI::cdef(
            'int inotify_init1(int flags);'
            . 'int inotify_add_watch(int fd, const char *pathname, uint32_t mask);'
            . 'int inotify_rm_watch(int fd, int wd);'
            . 'int close(int fd);'
            . 'int *__errno_location(void);',
            'libc.so.6',
        );
    }

    private static function errno(): int
    {
        return self::libc()->__errno_location()[0];
    }
}

<?php

declare(strict_types=1);

namespace StadtBraunschweig\IesResourceWatcher\FullIndex;

/**
 * Detects a lock held through PHP sysvsem (Symfony SemaphoreStore).
 *
 * Looks the set up without creating it and reads the acquisition semaphore
 * with GETVAL; zero means it is held.
 */
final class SysvSemaphoreProbe implements LockProbe
{
    private const GETVAL = 12;
    private const ENOENT = 2;
    private const EINVAL = 22;
    private const EIDRM = 43;

    private static ?\FFI $libc = null;

    public function __construct(private readonly int $key) {}

    public function isHeld(): bool
    {
        $libc = self::libc();
        $identifier = $libc->semget($this->key, 0, 0);
        if ($identifier < 0) {
            $error = self::errno();
            if ($error === self::ENOENT) {
                return false;
            }
            throw new \RuntimeException('cannot look up native semaphore: errno ' . $error);
        }
        $value = $libc->semctl($identifier, 0, self::GETVAL);
        if ($value < 0) {
            $error = self::errno();
            if ($error === self::EIDRM || $error === self::EINVAL) {
                return false;
            }
            throw new \RuntimeException('cannot read native semaphore: errno ' . $error);
        }
        return $value === 0;
    }

    public function describe(): array
    {
        return ['type' => 'semaphore', 'key' => $this->key];
    }

    /** @internal test support: create, set and remove a test-owned set */
    public static function libc(): \FFI
    {
        return self::$libc ??= \FFI::cdef(
            'int semget(int key, int nsems, int semflg);'
            . 'int semctl(int semid, int semnum, int cmd, ...);'
            . 'int *__errno_location(void);',
            'libc.so.6',
        );
    }

    private static function errno(): int
    {
        return self::libc()->__errno_location()[0];
    }
}

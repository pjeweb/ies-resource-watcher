<?php

declare(strict_types=1);

namespace StadtBraunschweig\IesResourceWatcher\FullIndex;

/**
 * Lock probe plus the status file of the native indexer.
 *
 * The status file also reveals a complete full run that started and finished
 * between two observations of the lock. If the file is JSON with a field
 * that only a full run changes (its start time), only that field counts:
 * the native single-document updates rewrite the file too.
 */
final class NativeIndexGuard implements FullIndexGuard
{
    public function __construct(
        private readonly LockProbe $lock,
        private readonly ?string $statusFile = null,
        private readonly ?string $runField = null,
    ) {}

    public function isBusy(): bool
    {
        return $this->lock->isHeld();
    }

    public function checkpoint(): ?string
    {
        if ($this->statusFile === null) {
            return null;
        }
        clearstatcache(true, $this->statusFile);
        $stat = @stat($this->statusFile);
        if ($stat === false) {
            return null;
        }
        if ($this->runField !== null) {
            $data = json_decode((string) @file_get_contents($this->statusFile), true);
            if (is_array($data) && is_scalar($data[$this->runField] ?? null)) {
                return 'run:' . $data[$this->runField];
            }
            // Unreadable, e.g. while being rewritten: fall through, which is conservative.
        }
        // mtime has one-second resolution; size and inode cover rewrites within that second.
        return implode(':', [$stat['ino'], $stat['mtime'], $stat['ctime'], $stat['size']]);
    }

    /** @return array<string, mixed> */
    public function describe(): array
    {
        return ['lock' => $this->lock->describe(), 'status_file' => $this->statusFile];
    }
}

<?php

declare(strict_types=1);

namespace StadtBraunschweig\IesResourceWatcher\Watcher;

final class InotifyEvent
{
    public function __construct(
        public readonly int $mask,
        /** Watched directory, or null if the descriptor is unknown. */
        public readonly ?string $directory,
        public readonly string $name,
        public readonly int $cookie = 0,
    ) {}

    public function is(int $flags): bool
    {
        return ($this->mask & $flags) !== 0;
    }

    public function path(): ?string
    {
        if ($this->directory === null) {
            return null;
        }
        return $this->name === '' ? $this->directory : $this->directory . '/' . $this->name;
    }
}

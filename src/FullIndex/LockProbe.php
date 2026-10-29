<?php

declare(strict_types=1);

namespace StadtBraunschweig\IesResourceWatcher\FullIndex;

/**
 * Inspects a lock held by another process without touching it.
 *
 * Even a momentary shared acquisition could make a native full run skip, so
 * implementations read kernel state only.
 */
interface LockProbe
{
    public function isHeld(): bool;

    /** @return array<string, mixed> */
    public function describe(): array;
}

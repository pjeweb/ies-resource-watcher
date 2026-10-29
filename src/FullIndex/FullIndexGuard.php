<?php

declare(strict_types=1);

namespace StadtBraunschweig\IesResourceWatcher\FullIndex;

/**
 * Read-only view of a native full index run.
 *
 * A full run may delete documents it did not touch when it finishes. A job
 * that overlaps such a run is therefore not acknowledged, but replayed.
 */
interface FullIndexGuard
{
    /** True while a full run holds its lock. Must not acquire or release that lock. */
    public function isBusy(): bool;

    /** Opaque value that changes whenever a full run records progress; null if unknown. */
    public function checkpoint(): ?string;
}

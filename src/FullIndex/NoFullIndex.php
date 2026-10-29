<?php

declare(strict_types=1);

namespace StadtBraunschweig\IesResourceWatcher\FullIndex;

/** For targets without a competing full index run. */
final class NoFullIndex implements FullIndexGuard
{
    public function isBusy(): bool
    {
        return false;
    }

    public function checkpoint(): ?string
    {
        return null;
    }
}

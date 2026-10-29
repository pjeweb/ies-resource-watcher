<?php

declare(strict_types=1);

namespace StadtBraunschweig\IesResourceWatcher\Atoolo\Search1;

use Atoolo\Resource\Resource;
use Atoolo\Resource\ResourceLoader;
use Atoolo\Resource\ResourceLocation;

/**
 * Loader handed to the job's indexer: a planned resource is indexed exactly
 * in the version whose identity was recorded, even if a newer file arrives
 * between planning and indexing. Everything else is loaded normally.
 */
final class PinnedResourceLoader implements ResourceLoader
{
    /** @var array<string, Resource> */
    private array $pinned = [];

    public function __construct(private readonly ResourceLoader $inner) {}

    public function pin(ResourceLocation $location): Resource
    {
        return $this->pinned[(string) $location] = $this->inner->load($location);
    }

    public function load(ResourceLocation $location): Resource
    {
        return $this->pinned[(string) $location] ?? $this->inner->load($location);
    }

    public function exists(ResourceLocation $location): bool
    {
        return isset($this->pinned[(string) $location]) || $this->inner->exists($location);
    }

    /** Pins survive: enrichers clean loader caches between documents. */
    public function cleanup(): void
    {
        $this->inner->cleanup();
    }
}

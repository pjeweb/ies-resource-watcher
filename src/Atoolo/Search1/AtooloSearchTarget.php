<?php

declare(strict_types=1);

namespace StadtBraunschweig\IesResourceWatcher\Atoolo\Search1;

use Atoolo\Resource\ResourceChannel;
use Atoolo\Resource\ResourceLanguage;
use Atoolo\Resource\ResourceLocation;
use Atoolo\Search\Exception\UnsupportedIndexLanguageException;
use Atoolo\Search\Service\Indexer\IndexerConfigurationLoader;
use Atoolo\Search\Service\Indexer\InternalResourceIndexer;
use Atoolo\Search\Service\Indexer\LocationFinder;
use Atoolo\Search\Service\Indexer\ResourceFilter;
use StadtBraunschweig\IesResourceWatcher\FullIndex\FlockProbe;
use StadtBraunschweig\IesResourceWatcher\FullIndex\FullIndexGuard;
use StadtBraunschweig\IesResourceWatcher\FullIndex\NativeIndexGuard;
use StadtBraunschweig\IesResourceWatcher\FullIndex\SysvSemaphoreProbe;
use StadtBraunschweig\IesResourceWatcher\Index\Document;
use StadtBraunschweig\IesResourceWatcher\Index\IndexTarget;
use StadtBraunschweig\IesResourceWatcher\Index\PathPlan;
use StadtBraunschweig\IesResourceWatcher\Index\ResourceLayout;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Lock\Store\SemaphoreStore;

/**
 * Atoolo Search 1.x (SiteKit resources, Solr schema 2.x), tested with 1.14.
 *
 * Documents are built by the native InternalResourceIndexer with its
 * configured excludes, filter, enrichers and language routing. This adapter
 * adds only planning, conditional removal and verification.
 */
final class AtooloSearchTarget implements IndexTarget
{
    /** @var list<string>|null */
    private ?array $managed = null;

    private ?string $base = null;

    /**
     * @param InternalResourceIndexer $indexer copy of the native indexer that loads through $loader
     * @param InternalResourceIndexer $native the native indexer, inspected for its lock only
     */
    public function __construct(
        private readonly InternalResourceIndexer $indexer,
        private readonly InternalResourceIndexer $native,
        private readonly PinnedResourceLoader $loader,
        private readonly LocationFinder $finder,
        private readonly IndexerConfigurationLoader $configuration,
        private readonly ResourceFilter $filter,
        private readonly SolrDocuments $solr,
        private readonly ResourceChannel $channel,
        private readonly string $cacheDir,
        private readonly string $nativeCacheDir,
        private readonly ?string $expectedIndex = null,
    ) {}

    public function layout(): ResourceLayout
    {
        return new ResourceLayout(
            rtrim($this->channel->resourceDir, '/'),
            ['.php'],
            ['WEB-IES'],
            ['.translations'],
            ['#-1015t\.php#'], // SiteKit preview files, as in the native LocationFinder
        );
    }

    public function fullIndexGuard(): FullIndexGuard
    {
        $base = $this->base();
        // The injected store matters, not the constructor default: Symfony may autowire flock.
        $factory = (new \ReflectionProperty($this->native, 'lockFactory'))->getValue($this->native);
        $store = (new \ReflectionProperty($factory, 'store'))->getValue($factory);
        $key = 'indexer.' . $base;
        if ($store instanceof FlockStore) {
            $directory = (string) (new \ReflectionProperty($store, 'lockPath'))->getValue($store);
            if ($this->cacheDir !== $this->nativeCacheDir && str_starts_with($directory, $this->cacheDir)) {
                $directory = $this->nativeCacheDir . substr($directory, strlen($this->cacheDir));
            }
            // File name as computed by FlockStore::lock().
            $probe = new FlockProbe(sprintf(
                '%s/sf.%s.%s.lock',
                $directory,
                substr((string) preg_replace('/[^a-z0-9\._-]+/i', '-', $key), 0, 50),
                strtr(substr(base64_encode(hash('sha256', $key, true)), 0, 7), '/', '_'),
            ));
        } elseif ($store instanceof SemaphoreStore) {
            // Key as computed by SemaphoreStore::lock().
            $probe = new SysvSemaphoreProbe(unpack('i', hash('xxh128', $key, true))[1]);
        } else {
            throw new \RuntimeException('unsupported native lock store: ' . get_debug_type($store));
        }
        return new NativeIndexGuard(
            $probe,
            sprintf('%s/atoolo.search.index.%s-%s.status.json', $this->nativeCacheDir, $base, $this->native->getSource()),
            // IndexerProgressState sets a new start time only in prepare()/start() of a full run;
            // startUpdate() keeps it.
            'startTime',
        );
    }

    public function plan(array $paths): array
    {
        $this->base();
        $root = $this->root();
        $excludes = $this->excludes();
        $plan = [];
        foreach ($paths as $path) {
            $absolute = $root . $path;
            if (!is_file($absolute) || is_link($absolute)) {
                $plan[$path] = PathPlan::excluded('missing');
                continue;
            }
            $real = realpath($absolute);
            if ($real === false || !str_starts_with($real, $root . '/')) {
                $plan[$path] = PathPlan::excluded('outside the publication');
                continue;
            }
            if ($this->finder->findPaths([$path], $excludes) === []) {
                $plan[$path] = PathPlan::excluded('native exclude');
                continue;
            }
            $resource = $this->loader->pin(self::location($path));
            if (!$this->filter->accept($resource)) {
                $plan[$path] = PathPlan::excluded('native filter');
                continue;
            }
            $index = $this->indexFor($resource->lang);
            if ($index === null) {
                $plan[$path] = PathPlan::excluded('no managed index for ' . $resource->lang->code);
                continue;
            }
            $url = $resource->data->getString('mediaUrl') ?: $resource->data->getString('url');
            if (!str_starts_with($url, '/')) {
                throw new \RuntimeException('resource without a site URL: ' . $path);
            }
            // The version is identified by its generation time. Names are not compared:
            // enrichers may rewrite sp_name (CityGov products drop suffixes such as " [OZG]").
            // The native enricher returns before sp_generated for search tips.
            $plan[$path] = PathPlan::indexed(
                [new Document($resource->id, $index, $url)],
                ['generated' => $resource->objectType === 'searchTip' ? 0 : $resource->data->getInt('generated')],
            );
        }
        return $plan;
    }

    public function apply(array $plan, array $previous): void
    {
        $eligible = array_keys(array_filter($plan, static fn(PathPlan $path) => !$path->isExcluded()));
        if ($eligible !== []) {
            $progress = new CollectingProgressHandler();
            $this->indexer->setProgressHandler($progress);
            $this->indexer->update(array_map('strval', $eligible));
            if ($progress->errors !== []) {
                throw new \RuntimeException(implode('; ', $progress->errors));
            }
        }
        foreach ($previous as $path => $documents) {
            foreach ($documents ?? [] as $old) {
                if ($plan[$path]->holds($old)) {
                    continue; // overwritten in place by the update
                }
                $this->assertManaged($old->index);
                $this->solr->deleteIfUnchanged($old->index, $old->id, $old->url !== '' ? $old->url : $path);
            }
        }
    }

    public function verify(array $plan, array $previous): void
    {
        foreach ($plan as $path => $pathPlan) {
            foreach ($pathPlan->documents as $document) {
                $found = $this->solr->search($document->index, $document->id, ['id', 'url', 'sp_generated']);
                $generated = (int) ($pathPlan->expectations['generated'] ?? 0);
                $expected = $generated > 0 ? gmdate('Y-m-d\TH:i:s\Z', $generated) : null;
                if (count($found) !== 1 || ($found[0]['url'] ?? null) !== $document->url
                    || ($found[0]['sp_generated'] ?? null) !== $expected) {
                    throw new \RuntimeException('update not reflected in Solr: ' . $path);
                }
            }
        }
        foreach ($previous as $path => $documents) {
            foreach ($documents ?? [] as $old) {
                if ($plan[$path]->holds($old)) {
                    continue;
                }
                $url = $old->url !== '' ? $old->url : $path;
                foreach ($this->solr->search($old->index, $old->id, ['id', 'url']) as $found) {
                    if (($found['url'] ?? null) === $url) {
                        throw new \RuntimeException('removal not reflected in Solr: ' . $path);
                    }
                }
            }
        }
    }

    public function identify(array $paths): array
    {
        $this->base();
        $root = $this->root();
        $result = [];
        $wanted = []; // index => id => paths
        foreach ($paths as $path) {
            $result[$path] = [];
            if (!is_file($root . $path) || is_link($root . $path)) {
                continue;
            }
            try {
                $resource = $this->loader->load(self::location($path));
            } catch (\Throwable) {
                continue; // unreadable: nothing can be attributed to it
            }
            $index = $this->indexFor($resource->lang);
            if ($index !== null) {
                $wanted[$index][$resource->id][$path] = $resource->data->getString('mediaUrl') ?: $resource->data->getString('url');
            }
        }
        $this->loader->cleanup();
        foreach ($wanted as $index => $ids) {
            $found = $this->solr->get($index, array_map('strval', array_keys($ids)));
            foreach ($ids as $id => $owners) {
                $stored = $found[(string) $id]['url'] ?? null;
                foreach ($owners as $path => $url) {
                    // Only the file the document was built from owns it; a leftover copy
                    // with the same ID must not take the live document along when deleted.
                    if (is_string($stored) && $stored === $url) {
                        $result[$path] = [new Document((string) $id, $index, $stored)];
                    }
                }
            }
        }
        return $result;
    }

    /** Same mapping as InternalResourceIndexer::toResourceLocation(). */
    private static function location(string $path): ResourceLocation
    {
        $position = strrpos($path, '.php.translations');
        if ($position === false) {
            return ResourceLocation::of($path);
        }
        return ResourceLocation::of(substr($path, 0, $position + 4), ResourceLanguage::of(basename($path, '.php')));
    }

    private function root(): string
    {
        $root = realpath($this->channel->resourceDir);
        if ($root === false) {
            throw new \RuntimeException('resource directory missing: ' . $this->channel->resourceDir);
        }
        return $root;
    }

    /** @return list<string> */
    private function excludes(): array
    {
        /** @var list<string> $excludes */
        $excludes = $this->configuration->load($this->native->getSource())->data->getArray('excludes');
        return $excludes;
    }

    private function indexFor(ResourceLanguage $lang): ?string
    {
        try {
            $index = $this->indexer->getIndex($lang);
        } catch (UnsupportedIndexLanguageException) {
            return null;
        }
        return in_array($index, $this->managed(), true) ? $index : null;
    }

    private function base(): string
    {
        if ($this->base === null) {
            $base = $this->native->getIndex(ResourceLanguage::default());
            if ($this->expectedIndex !== null && $base !== $this->expectedIndex) {
                throw new \RuntimeException(sprintf('channel index is %s, configuration expects %s', $base, $this->expectedIndex));
            }
            $this->assertManaged($base);
            $this->base = $base;
        }
        return $this->base;
    }

    /** @return list<string> */
    private function managed(): array
    {
        return $this->managed ??= array_values($this->native->getManagedIndices());
    }

    private function assertManaged(string $index): void
    {
        if (!in_array($index, $this->managed(), true)) {
            throw new \RuntimeException('index is not managed by this channel: ' . $index);
        }
    }
}

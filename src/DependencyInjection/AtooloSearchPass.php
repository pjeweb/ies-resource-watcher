<?php

declare(strict_types=1);

namespace StadtBraunschweig\IesResourceWatcher\DependencyInjection;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Gives the Atoolo Search adapter its own copy of the native indexer that
 * loads through the pinned loader. The native service stays untouched, so
 * installing the bundle does not change the application's own indexing.
 */
final class AtooloSearchPass implements CompilerPassInterface
{
    public const NATIVE = 'atoolo_search.indexer.internal_resource_indexer';
    public const COPY = 'ies_resource_watcher.atoolo_search.indexer';
    public const LOADER = 'ies_resource_watcher.atoolo_search.loader';
    public const TARGET = 'ies_resource_watcher.atoolo_search.target';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(self::TARGET)) {
            return;
        }
        if (!$container->hasDefinition(self::NATIVE)) {
            foreach ([self::TARGET, self::LOADER, 'ies_resource_watcher.atoolo_search.solr'] as $id) {
                $container->removeDefinition($id);
            }
            return;
        }
        // Runs after instanceof conditionals were applied and before autowiring:
        // the copy keeps the native arguments, receives the same autowired lock
        // factory, and is not registered as a second indexer.
        $copy = clone $container->getDefinition(self::NATIVE);
        $copy->setPublic(false)->setTags([])->setAutoconfigured(false)->setInstanceofConditionals([]);
        $copy->setArgument('$resourceLoader', new Reference(self::LOADER));
        // An abort requested for a full run must stay with that run, not be consumed by a job.
        $copy->setArgument('$aborter', new Definition(
            'Atoolo\Search\Service\Indexer\IndexingAborter',
            ['%kernel.cache_dir%/ies-resource-watcher', 'indexing'],
        ));
        $container->setDefinition(self::COPY, $copy);
    }
}

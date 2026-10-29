<?php

declare(strict_types=1);

namespace StadtBraunschweig\IesResourceWatcher;

use StadtBraunschweig\IesResourceWatcher\Atoolo\Search1\AtooloSearchTarget;
use StadtBraunschweig\IesResourceWatcher\Atoolo\Search1\PinnedResourceLoader;
use StadtBraunschweig\IesResourceWatcher\Atoolo\Search1\SolrDocuments;
use StadtBraunschweig\IesResourceWatcher\Command\CheckCommand;
use StadtBraunschweig\IesResourceWatcher\Command\JobCommand;
use StadtBraunschweig\IesResourceWatcher\Command\StatusCommand;
use StadtBraunschweig\IesResourceWatcher\Command\WatchCommand;
use StadtBraunschweig\IesResourceWatcher\DependencyInjection\AtooloSearchPass;
use StadtBraunschweig\IesResourceWatcher\Runtime\JobLauncher;
use StadtBraunschweig\IesResourceWatcher\Watcher\WatcherConfig;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

final class IesResourceWatcherBundle extends AbstractBundle
{
    protected string $extensionAlias = 'ies_resource_watcher';

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('state_dir')->isRequired()->cannotBeEmpty()
                    ->info('Writable directory for the durable queue and status; one per watched channel')->end()
                ->scalarNode('target')->defaultValue(AtooloSearchPass::TARGET)
                    ->info('Service implementing IndexTarget')->end()
                ->arrayNode('scopes')->scalarPrototype()->end()->defaultValue(['/'])
                    ->info('Directories below the resource root that are watched')->end()
                ->arrayNode('prune')->scalarPrototype()->end()->defaultValue([])
                    ->info('Additional shell patterns of directory names to skip, e.g. *.scaled')->end()
                ->floatNode('quiet_period')->defaultValue(0.75)->min(0)
                    ->info('Seconds to coalesce events for one path (deferral is capped at 3s)')->end()
                ->integerNode('batch_size')->defaultValue(32)->min(1)->end()
                ->floatNode('reconcile_interval')->defaultValue(3600)->min(1)
                    ->info('Seconds between full scans that repair missed events')->end()
                ->floatNode('job_timeout')->defaultValue(120)->min(1)->end()
                ->integerNode('identify_batch_size')->defaultValue(250)->min(0)
                    ->info('Baseline files identified per job; 0 disables identification')->end()
                ->arrayNode('console')->scalarPrototype()->end()->defaultValue([])
                    ->info('Command line of this console for job processes; default [PHP_BINARY, "%kernel.project_dir%/bin/console"]')->end()
                ->arrayNode('restart_on_change')->scalarPrototype()->end()->defaultValue([])
                    ->info('Symlinks whose target change makes the watcher exit for a restart, e.g. a release link')->end()
                ->arrayNode('atoolo_search')->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('index')->defaultNull()
                            ->info('Expected base index of the channel; refuse to run against another one')->end()
                        ->scalarNode('native_cache_dir')->defaultValue('%kernel.cache_dir%')
                            ->info('Cache directory of the application that runs full indexing (status file, lock path)')->end()
                    ->end()
                ->end()
            ->end();
    }

    /** @param array<string, mixed> $config */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $services = $container->services();
        $services->set('ies_resource_watcher.config', WatcherConfig::class)
            ->factory([WatcherConfig::class, 'fromArray'])
            ->args([$config]);
        $services->set('ies_resource_watcher.job_launcher', JobLauncher::class)
            ->factory([JobLauncher::class, 'forConsole'])
            ->args([$config['console'], '%kernel.project_dir%']);
        $services->alias('ies_resource_watcher.target', $config['target']);

        $services->set('ies_resource_watcher.command.watch', WatchCommand::class)
            ->args([service('ies_resource_watcher.config'), service('ies_resource_watcher.target'), service('ies_resource_watcher.job_launcher')])
            ->tag('console.command', ['command' => 'ies-resource-watcher:watch']);
        $services->set('ies_resource_watcher.command.job', JobCommand::class)
            ->args([service('ies_resource_watcher.config'), service('ies_resource_watcher.target')])
            ->tag('console.command', ['command' => JobLauncher::COMMAND]);
        $services->set('ies_resource_watcher.command.check', CheckCommand::class)
            ->args([service('ies_resource_watcher.config'), service('ies_resource_watcher.target')])
            ->tag('console.command', ['command' => 'ies-resource-watcher:check']);
        $services->set('ies_resource_watcher.command.status', StatusCommand::class)
            ->args([service('ies_resource_watcher.config')])
            ->tag('console.command', ['command' => 'ies-resource-watcher:status']);

        if (!interface_exists(\Atoolo\Search\Indexer::class)) {
            return;
        }
        $services->set(AtooloSearchPass::LOADER, PinnedResourceLoader::class)
            ->args([service('atoolo_resource.resource_loader')]);
        $services->set('ies_resource_watcher.atoolo_search.solr', SolrDocuments::class)
            ->args([service('atoolo_search.solarium_client_factory')]);
        $services->set(AtooloSearchPass::TARGET, AtooloSearchTarget::class)
            ->args([
                service(AtooloSearchPass::COPY),
                service(AtooloSearchPass::NATIVE),
                service(AtooloSearchPass::LOADER),
                service('atoolo_search.indexer.location_finder'),
                service('atoolo_search.indexer.configuration_loader'),
                service('atoolo_search.indexer.resource_filter'),
                service('ies_resource_watcher.atoolo_search.solr'),
                service('atoolo_resource.resource_channel'),
                '%kernel.cache_dir%',
                $config['atoolo_search']['native_cache_dir'],
                $config['atoolo_search']['index'],
            ]);
    }

    public function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new AtooloSearchPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION);
    }
}

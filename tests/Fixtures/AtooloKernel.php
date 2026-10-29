<?php

declare(strict_types=1);

namespace StadtBraunschweig\IesResourceWatcher\Tests\Fixtures;

use Atoolo\Resource\AtooloResourceBundle;
use Atoolo\Search\AtooloSearchBundle;
use StadtBraunschweig\IesResourceWatcher\IesResourceWatcherBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;

/** Atoolo application with the released Resource and Search bundles plus the watcher. */
final class AtooloKernel extends Kernel
{
    use MicroKernelTrait;

    /** @param array<string, mixed> $watcher */
    public function __construct(private readonly string $directory, private readonly array $watcher)
    {
        parent::__construct('test', false);
    }

    public function registerBundles(): iterable
    {
        return [new FrameworkBundle(), new AtooloResourceBundle(), new AtooloSearchBundle(), new IesResourceWatcherBundle()];
    }

    public function getProjectDir(): string
    {
        return $this->directory;
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'test' => true,
            'http_method_override' => false,
            'handle_all_throwables' => true,
            'php_errors' => ['log' => true],
            'lock' => 'flock://' . $this->directory . '/locks',
        ]);
        $container->extension('ies_resource_watcher', $this->watcher);
        $container->services()->set('atoolo_search.solarium_client_factory', MemorySolr::class)
            ->args([['test-www', 'test-www-en_US']])->public();
        $container->services()->set(RenamingEnricher::class)
            ->tag('atoolo_search.indexer.document_enricher.schema2x', ['priority' => -10]);
        $container->services()->alias('test.target', 'ies_resource_watcher.target')->public();
        $container->services()->alias('test.native_indexer', 'atoolo_search.indexer.internal_resource_indexer')->public();
    }
}

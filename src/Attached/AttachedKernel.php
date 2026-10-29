<?php

declare(strict_types=1);

namespace StadtBraunschweig\IesResourceWatcher\Attached;

use StadtBraunschweig\IesResourceWatcher\IesResourceWatcherBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * The host application's kernel plus the watcher bundle.
 *
 * Only used by bin/ies-resource-watcher. The host's code, configuration and
 * compiled container are read, never written: this kernel compiles its own
 * container into the watcher's state directory.
 */
final class AttachedKernel extends \App\Kernel
{
    /** @param array<string, mixed> $watcher bundle configuration */
    public function __construct(
        private readonly string $hostProjectDir,
        private readonly array $watcher,
        private readonly string $fingerprint,
        string $environment,
    ) {
        parent::__construct($environment, false);
    }

    /** Changes with the host release, the configuration and the watcher code. */
    public static function fingerprint(string $hostProjectDir, string $environment, array $watcher, string $sourceDir): string
    {
        $context = hash_init('sha256');
        hash_update($context, json_encode([$hostProjectDir, $environment, $watcher, PHP_VERSION], JSON_THROW_ON_ERROR));
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($sourceDir, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $files[] = $file->getPathname();
        }
        sort($files);
        foreach ($files as $file) {
            hash_update($context, $file);
            hash_update_file($context, $file);
        }
        return substr(hash_final($context), 0, 16);
    }

    public function getProjectDir(): string
    {
        return $this->hostProjectDir;
    }

    public function getCacheDir(): string
    {
        return $this->watcher['state_dir'] . '/php-cache-' . $this->fingerprint;
    }

    public function getBuildDir(): string
    {
        return $this->getCacheDir();
    }

    public function getLogDir(): string
    {
        return $this->watcher['state_dir'] . '/php-log';
    }

    public function registerBundles(): iterable
    {
        yield from parent::registerBundles();
        yield new IesResourceWatcherBundle();
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        parent::registerContainerConfiguration($loader);
        $loader->load(function (ContainerBuilder $container): void {
            $container->loadFromExtension('ies_resource_watcher', $this->watcher);
        });
    }
}

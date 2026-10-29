<?php

declare(strict_types=1);

namespace StadtBraunschweig\IesResourceWatcher\Tests\Integration;

use PHPUnit\Framework\TestCase;
use StadtBraunschweig\IesResourceWatcher\Tests\TemporaryDirectory;
use Symfony\Component\Process\Process;

/**
 * bin/ies-resource-watcher against an installed application it must not
 * modify: a host project with its own App\Kernel, bundles and .env.
 */
final class AttachedModeTest extends TestCase
{
    use TemporaryDirectory;

    private function host(): string
    {
        $host = $this->tmp . '/host';
        $package = dirname(__DIR__, 2);
        $this->write($host . '/composer.json', '{}');
        $this->write($host . '/.env', "APP_ENV=prod\n");
        $this->write($host . '/vendor/autoload.php', sprintf(<<<'PHP'
            <?php
            $loader = require %s;
            $loader->addPsr4('App\\', __DIR__ . '/../src/');
            return $loader;
            PHP, var_export($package . '/vendor/autoload.php', true)));
        $this->write($host . '/src/Kernel.php', <<<'PHP'
            <?php
            namespace App;
            use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
            use Symfony\Component\HttpKernel\Kernel as BaseKernel;
            class Kernel extends BaseKernel { use MicroKernelTrait; }
            PHP);
        $this->write($host . '/config/bundles.php', <<<'PHP'
            <?php
            return [
                Symfony\Bundle\FrameworkBundle\FrameworkBundle::class => ['all' => true],
                Atoolo\Resource\AtooloResourceBundle::class => ['all' => true],
                Atoolo\Search\AtooloSearchBundle::class => ['all' => true],
            ];
            PHP);
        $this->write($host . '/config/packages/framework.yaml', "framework:\n  lock: 'flock://%kernel.project_dir%/var/lock'\n");
        $this->write($host . '/config/services.yaml', sprintf(<<<'YAML'
            services:
              atoolo_search.solarium_client_factory:
                class: StadtBraunschweig\IesResourceWatcher\Tests\Fixtures\MemorySolr
                arguments: [['www', 'www-en_US'], %s]
            YAML, json_encode($this->tmp . '/solr.json')));
        return $host;
    }

    private function channel(): string
    {
        $root = $this->tmp . '/www';
        $this->write($root . '/WEB-IES/context.php', '<?php return ' . var_export([
            'publisher' => ['id' => 1, 'name' => 'WWW', 'anchor' => 'www', 'serverName' => 'www.test', 'preview' => false,
                'locale' => 'de_DE', 'translationLocales' => ['en_US']],
            'tenant' => ['id' => 1, 'name' => 'Tenant', 'anchor' => 'tenant', 'attributes' => []],
            'server' => ['host' => 'www.test'],
        ], true) . ';');
        $this->write($root . '/index.php', "<?php return ['id' => 1, 'name' => 'Home', 'objectType' => 'home', 'locale' => 'de_DE', 'url' => '/index.php', 'home' => true];");
        return $root;
    }

    private function resource(string $root, string $path, int $id, string $name): void
    {
        $file = $root . $path;
        $this->write($file . '.tmp', '<?php return ' . var_export([
            'id' => $id, 'name' => $name, 'objectType' => 'content', 'locale' => 'de_DE', 'url' => $path, 'generated' => time(),
        ], true) . ';');
        rename($file . '.tmp', $file);
    }

    /** @return array<string, array<string, mixed>> */
    private function solr(): array
    {
        return is_file($this->tmp . '/solr.json') ? json_decode((string) file_get_contents($this->tmp . '/solr.json'), true)['www'] ?? [] : [];
    }

    private function until(Process $watcher, callable $condition, string $message): void
    {
        $deadline = microtime(true) + 30;
        while (!$condition()) {
            if (!$watcher->isRunning() || microtime(true) > $deadline) {
                self::fail($message . "\n" . $watcher->getOutput() . $watcher->getErrorOutput());
            }
            usleep(50000);
        }
    }

    public function testWatchIndexAndStopWithoutTouchingTheHost(): void
    {
        $host = $this->host();
        $root = $this->channel();
        $this->resource($root, '/existing.php', 10, 'Existing');
        $state = $this->tmp . '/state';
        $config = $this->tmp . '/www.json';
        file_put_contents($config, json_encode([
            'project' => $host,
            'resource_root' => $root,
            'watcher' => ['state_dir' => $state, 'quiet_period' => 0.1, 'prune' => ['*.scaled'], 'atoolo_search' => ['index' => 'www']],
        ]));
        $launcher = [\PHP_BINARY, dirname(__DIR__, 2) . '/bin/ies-resource-watcher', $config];

        $check = new Process([...$launcher, 'ies-resource-watcher:check']);
        $check->run();
        self::assertSame(0, $check->getExitCode(), $check->getOutput() . $check->getErrorOutput());
        self::assertStringContainsString('"type": "flock"', $check->getOutput());
        self::assertStringContainsString('atoolo.search.index.www-internal.status.json', $check->getOutput());

        $watcher = new Process([...$launcher, 'ies-resource-watcher:watch']);
        $watcher->start();
        try {
            $status = static fn() => is_file($state . '/status.json') ? json_decode((string) file_get_contents($state . '/status.json'), true) : [];
            $this->until($watcher, fn() => ($status()['initialized'] ?? false) && ($status()['unidentified'] ?? 1) === 0, 'initial scan');
            self::assertSame([], $this->solr(), 'the existing file is a baseline');

            $this->resource($root, '/news/new.php', 11, 'Published');
            $this->until($watcher, fn() => ($this->solr()['11']['sp_name'] ?? null) === 'Published', 'publication indexed');
            unlink($root . '/existing.php');
            $this->resource($root, '/existing2.php', 12, 'Other');
            $this->until($watcher, fn() => isset($this->solr()['12']), 'second publication');

            // Symfony's console would exit with status 0 on SIGUSR1; the watcher ignores it.
            $watcher->signal(\SIGUSR1);
            usleep(300000);
            self::assertTrue($watcher->isRunning(), 'SIGUSR1 does not stop the watcher');

            $health = new Process([...$launcher, 'ies-resource-watcher:status']);
            $health->run();
            self::assertSame(0, $health->getExitCode(), $health->getOutput() . $health->getErrorOutput());
        } finally {
            $watcher->signal(\SIGTERM);
            $watcher->wait();
        }
        self::assertSame(0, $watcher->getExitCode(), $watcher->getErrorOutput());
        self::assertStringContainsString('"event":"stopped"', $watcher->getOutput());
        self::assertDirectoryDoesNotExist($host . '/var/cache', 'the host cache is not written');
        self::assertNotEmpty(glob($state . '/php-cache-*'));
    }

    public function testRefusesRootAndMissingProject(): void
    {
        $config = $this->tmp . '/bad.json';
        file_put_contents($config, json_encode(['project' => $this->tmp . '/nothing', 'watcher' => []]));
        $process = new Process([\PHP_BINARY, dirname(__DIR__, 2) . '/bin/ies-resource-watcher', $config, 'list']);
        $process->run();
        self::assertSame(2, $process->getExitCode());
        self::assertStringContainsString('not an installed application', $process->getErrorOutput());
    }
}

<?php

declare(strict_types=1);

namespace StadtBraunschweig\IesResourceWatcher\Tests\Integration;

use Atoolo\Search\Service\Indexer\InternalResourceIndexer;
use PHPUnit\Framework\TestCase;
use StadtBraunschweig\IesResourceWatcher\Atoolo\Search1\AtooloSearchTarget;
use StadtBraunschweig\IesResourceWatcher\FullIndex\NativeIndexGuard;
use StadtBraunschweig\IesResourceWatcher\Index\Document;
use StadtBraunschweig\IesResourceWatcher\Queue\QueueStore;
use StadtBraunschweig\IesResourceWatcher\Runtime\JobRunner;
use StadtBraunschweig\IesResourceWatcher\Tests\Fixtures\AtooloKernel;
use StadtBraunschweig\IesResourceWatcher\Tests\Fixtures\MemorySolr;
use StadtBraunschweig\IesResourceWatcher\Tests\TemporaryDirectory;
use StadtBraunschweig\IesResourceWatcher\Watcher\PathPolicy;
use Symfony\Component\Lock\LockFactory;

/**
 * The adapter inside a real Atoolo Search 1.14 container: native loader,
 * filter, enrichers and language routing, with an in-memory Solr.
 */
final class AtooloSearchTargetTest extends TestCase
{
    use TemporaryDirectory {
        setUp as private createTemporaryDirectory;
    }

    private string $root;
    private AtooloKernel $kernel;
    private AtooloSearchTarget $target;
    private MemorySolr $solr;
    private QueueStore $store;
    private JobRunner $runner;

    protected function setUp(): void
    {
        $this->createTemporaryDirectory();
        $this->root = $this->tmp . '/www';
        $this->write($this->root . '/WEB-IES/context.php', '<?php return ' . var_export([
            'publisher' => [
                'id' => 1, 'name' => 'Test', 'anchor' => 'test-www', 'serverName' => 'www.test', 'preview' => false,
                'locale' => 'de_DE', 'translationLocales' => ['en_US'],
            ],
            'tenant' => ['id' => 1, 'name' => 'Tenant', 'anchor' => 'tenant', 'attributes' => []],
            'server' => ['host' => 'www.test'],
        ], true) . ';');
        $this->write($this->tmp . '/composer.json', '{}');
        // The enricher needs a navigation root.
        $this->write($this->root . '/index.php', "<?php return ['id' => 1000, 'name' => 'Home', 'objectType' => 'home', 'locale' => 'de_DE', 'url' => '/index.php', 'home' => true];");
        putenv('RESOURCE_ROOT=' . $this->root);
        $_SERVER['RESOURCE_ROOT'] = $_ENV['RESOURCE_ROOT'] = $this->root;

        $this->kernel = new AtooloKernel($this->tmp, ['state_dir' => $this->tmp . '/state', 'atoolo_search' => ['index' => 'test-www']]);
        $this->kernel->boot();
        $container = $this->kernel->getContainer();
        $this->target = $container->get('test.target');
        $this->solr = $container->get('atoolo_search.solarium_client_factory');
        mkdir($this->tmp . '/state');
        $this->store = new QueueStore($this->tmp . '/state/queue.sqlite', 0);
        $this->runner = new JobRunner($this->target, $this->store, new PathPolicy($this->target->layout()));
    }

    protected function tearDown(): void
    {
        $this->kernel->shutdown();
        putenv('RESOURCE_ROOT');
        unset($_SERVER['RESOURCE_ROOT'], $_ENV['RESOURCE_ROOT']);
        $this->remove($this->tmp);
    }

    /** @param array<string, mixed> $extra */
    private function resource(string $path, int $id, string $name, string $locale = 'de_DE', array $extra = []): void
    {
        $this->write($this->root . $path, '<?php return ' . var_export($extra + [
            'id' => $id, 'name' => $name, 'objectType' => 'content', 'locale' => $locale,
            'url' => preg_replace('/(\.php\.translations\/.*|\.php)$/', '.php', $path), 'generated' => 1761730000,
            'base' => ['title' => $name],
        ], true) . ';');
    }

    /** Queue a path as the watcher would and run one job for it. */
    private function sync(string ...$paths): array
    {
        foreach ($paths as $path) {
            $this->store->observe($path, (string) @filemtime($this->root . $path) . random_int(0, PHP_INT_MAX), true);
        }
        $batch = $this->store->pendingFor(...$paths);
        $plan = $this->runner->sync($batch);
        foreach ($batch as $item) {
            $this->store->finish($item, $plan[$item->path]->documents);
        }
        return $plan;
    }

    public function testCopyOfTheNativeIndexerLoadsThroughThePinnedLoaderOnly(): void
    {
        $native = $this->kernel->getContainer()->get('test.native_indexer');
        $copy = (new \ReflectionProperty($this->target, 'indexer'))->getValue($this->target);
        self::assertInstanceOf(InternalResourceIndexer::class, $copy);
        self::assertNotSame($native, $copy);
        $loader = static fn(object $indexer) => get_class((new \ReflectionProperty($indexer, 'resourceLoader'))->getValue($indexer));
        self::assertStringEndsWith('PinnedResourceLoader', $loader($copy));
        self::assertStringEndsWith('SiteKitLoader', $loader($native));
        $property = static fn(object $indexer, string $name) => (new \ReflectionProperty($indexer, $name))->getValue($indexer);
        self::assertSame($property($native, 'lockFactory'), $property($copy, 'lockFactory'), 'same autowired lock factory');
        self::assertNotSame($property($native, 'aborter'), $property($copy, 'aborter'), 'abort requests stay with the full run');
    }

    public function testLayoutAndFullIndexGuardFollowTheNativeConfiguration(): void
    {
        self::assertSame($this->root, $this->target->layout()->root);
        $guard = $this->target->fullIndexGuard();
        self::assertInstanceOf(NativeIndexGuard::class, $guard);
        self::assertSame($this->tmp . '/var/cache/test/atoolo.search.index.test-www-internal.status.json', $guard->describe()['status_file']);
        self::assertFalse($guard->isBusy());

        // The lock the native full index takes is seen, without being touched.
        $factory = $this->kernel->getContainer()->get('test.native_indexer');
        $lock = (new \ReflectionProperty($factory, 'lockFactory'))->getValue($factory);
        self::assertInstanceOf(LockFactory::class, $lock);
        $held = $lock->createLock('indexer.test-www');
        self::assertTrue($held->acquire());
        self::assertTrue($guard->isBusy());
        $held->release();
        self::assertFalse($guard->isBusy());
    }

    public function testIndexesUpdatesAndRemovesThroughTheNativePipeline(): void
    {
        $this->resource('/news/a.php', 12, 'First');
        $this->resource('/news/a.php.translations/en_US.php', 12, 'First (en)', 'en_US');
        $plan = $this->sync('/news/a.php', '/news/a.php.translations/en_US.php');

        self::assertEquals([new Document('12', 'test-www', '/news/a.php')], $plan['/news/a.php']->documents);
        self::assertEquals([new Document('12', 'test-www-en_US', '/news/a.php')], $plan['/news/a.php.translations/en_US.php']->documents);
        $stored = $this->solr->cores['test-www']['12'];
        self::assertSame(['First', '/news/a.php', ['internal'], '2025-10-29T09:26:40Z'],
            [$stored['sp_name'], $stored['url'], $stored['sp_source'], $stored['sp_generated']]);
        self::assertSame('First (en)', $this->solr->cores['test-www-en_US']['12']['sp_name']);

        $this->resource('/news/a.php', 12, 'Second');
        $this->sync('/news/a.php');
        self::assertSame('Second', $this->solr->cores['test-www']['12']['sp_name']);

        // Removing the translation file leaves the German document alone.
        unlink($this->root . '/news/a.php.translations/en_US.php');
        $plan = $this->sync('/news/a.php.translations/en_US.php');
        self::assertTrue($plan['/news/a.php.translations/en_US.php']->isExcluded());
        self::assertArrayNotHasKey('12', $this->solr->cores['test-www-en_US']);
        self::assertArrayHasKey('12', $this->solr->cores['test-www']);
    }

    public function testNoIndexFilterAndExcludesRemoveThePreviousDocument(): void
    {
        $this->resource('/a.php', 7, 'Visible');
        $this->sync('/a.php');
        $this->resource('/a.php', 7, 'Hidden', 'de_DE', ['noIndex' => true]);
        $plan = $this->sync('/a.php');
        self::assertSame('native filter', $plan['/a.php']->reason);
        self::assertArrayNotHasKey('7', $this->solr->cores['test-www']);

        $this->resource('/private/b.php', 8, 'Private');
        $this->sync('/private/b.php');
        self::assertArrayHasKey('8', $this->solr->cores['test-www']);
        // Native excludes from the channel's indexer configuration.
        $this->write($this->root . '/indexer/internal.php', "<?php return ['data' => ['excludes' => ['^/private/']]];");
        $plan = $this->sync('/private/b.php');
        self::assertSame('native exclude', $plan['/private/b.php']->reason);
        self::assertArrayNotHasKey('8', $this->solr->cores['test-www']);
    }

    public function testRenamedDocumentSurvivesTheDeletionOfItsOldPath(): void
    {
        $this->resource('/old/doc.php', 5, 'Doc');
        $this->sync('/old/doc.php');
        $this->resource('/new/doc.php', 5, 'Doc');
        $this->sync('/new/doc.php');
        self::assertSame('/new/doc.php', $this->solr->cores['test-www']['5']['url']);

        unlink($this->root . '/old/doc.php');
        $this->sync('/old/doc.php');
        self::assertSame('/new/doc.php', $this->solr->cores['test-www']['5']['url'], 'conditional delete keeps the moved document');
    }

    public function testIntentIsRecordedBeforeTheFirstWrite(): void
    {
        $this->resource('/media/file.pdf.meta.php', 30, 'File', 'de_DE', ['mediaUrl' => '/media/file.pdf']);
        $this->store->observe('/media/file.pdf.meta.php', 'v1', true);
        $batch = $this->store->pendingFor('/media/file.pdf.meta.php');
        $updates = fn() => count(array_filter($this->solr->requests, fn($request) => $request['handler'] === 'update'));
        $before = $updates();
        $plan = $this->target->plan(['/media/file.pdf.meta.php']);
        self::assertSame($before, $updates(), 'planning writes nothing');
        self::assertEquals([new Document('30', 'test-www', '/media/file.pdf')], $plan['/media/file.pdf.meta.php']->documents);

        $this->runner->sync($batch);
        self::assertEquals([new Document('30', 'test-www', '/media/file.pdf')], $this->store->documents('/media/file.pdf.meta.php'));
    }

    public function testConcurrentRewriteFailsTheDeletionInsteadOfLosingTheDocument(): void
    {
        $this->resource('/a.php', 9, 'A');
        $this->sync('/a.php');
        unlink($this->root . '/a.php');
        $this->store->observe('/a.php', '', true);
        $batch = $this->store->pendingFor('/a.php');

        // Someone rewrites the document between the lookup and the delete.
        $this->solr->beforeUpdate = static function (MemorySolr $solr, string $core): void {
            $solr->cores[$core]['9']['_version_'] = 999999;
        };
        try {
            $this->runner->sync($batch);
            self::fail('the deletion must not succeed');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('HTTP 409', $error->getMessage());
        }
        self::assertArrayHasKey('9', $this->solr->cores['test-www']);
        self::assertSame(1, $this->store->statistics()['pending'], 'still queued for a retry');
    }

    public function testIdentifyAttributesIndexedDocumentsToBaselinePaths(): void
    {
        $this->resource('/a.php', 1, 'A');
        $this->resource('/a.php.translations/en_US.php', 1, 'A (en)', 'en_US');
        $this->resource('/b.php', 2, 'B');
        $this->write($this->root . '/broken.php', '<?php syntax error');
        $this->sync('/a.php', '/a.php.translations/en_US.php');

        $identities = $this->runner->identify(['/a.php', '/a.php.translations/en_US.php', '/b.php', '/broken.php', '/missing.php']);
        self::assertEquals([
            '/a.php' => [new Document('1', 'test-www', '/a.php')],
            '/a.php.translations/en_US.php' => [new Document('1', 'test-www-en_US', '/a.php')],
            '/b.php' => [],
            '/broken.php' => [],
            '/missing.php' => [],
        ], $identities);
    }

    public function testIdentificationIgnoresALeftoverCopyWithTheSameId(): void
    {
        $this->resource('/new/a.php', 7, 'A');
        $this->sync('/new/a.php');
        $this->resource('/old/a.php', 7, 'A');
        $identities = $this->runner->identify(['/old/a.php', '/new/a.php']);
        self::assertSame([], $identities['/old/a.php']);
        self::assertEquals([new Document('7', 'test-www', '/new/a.php')], $identities['/new/a.php']);
    }

    public function testEnrichersMayRewriteTheNameAndMediaMayLackAGenerationTime(): void
    {
        // Both were acknowledged never by the first (Python) implementation.
        $this->resource('/vv/produkte/wahlhilfe.php', 50, 'Wahlhilfe [OZG]', 'de_DE', ['objectType' => 'citygovProduct']);
        $this->write($this->root . '/media/plakat.jpg.meta.php', '<?php return ' . var_export([
            'id' => 51, 'name' => 'plakat.jpg', 'objectType' => 'embedded-media', 'locale' => 'de_DE',
            'url' => '/media/plakat.jpg.meta.php', 'mediaUrl' => '/media/plakat.jpg',
        ], true) . ';');
        $this->sync('/vv/produkte/wahlhilfe.php', '/media/plakat.jpg.meta.php');
        self::assertSame('Wahlhilfe', $this->solr->cores['test-www']['50']['sp_name']);
        self::assertArrayNotHasKey('sp_generated', $this->solr->cores['test-www']['51']);
    }

    public function testSearchTipIsVerifiedWithoutGeneratedDate(): void
    {
        $this->write($this->root . '/tip.php', '<?php return ' . var_export([
            'id' => 40, 'name' => 'Tip', 'objectType' => 'searchTip', 'locale' => 'de_DE', 'url' => '/tip.php', 'generated' => 1761730000,
        ], true) . ';');
        $this->sync('/tip.php');
        self::assertArrayNotHasKey('sp_generated', $this->solr->cores['test-www']['40']);
    }

    public function testBrokenResourceFailsTheJob(): void
    {
        $this->write($this->root . '/broken.php', '<?php syntax error');
        $this->store->observe('/broken.php', 'v', true);
        $this->expectException(\Throwable::class);
        $this->runner->sync($this->store->pendingFor('/broken.php'));
    }
}

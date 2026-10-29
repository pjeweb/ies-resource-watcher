<?php

declare(strict_types=1);

namespace StadtBraunschweig\IesResourceWatcher\Tests\Fixtures;

use StadtBraunschweig\IesResourceWatcher\FullIndex\FlockProbe;
use StadtBraunschweig\IesResourceWatcher\FullIndex\FullIndexGuard;
use StadtBraunschweig\IesResourceWatcher\FullIndex\NativeIndexGuard;
use StadtBraunschweig\IesResourceWatcher\Index\Document;
use StadtBraunschweig\IesResourceWatcher\Index\IndexTarget;
use StadtBraunschweig\IesResourceWatcher\Index\PathPlan;
use StadtBraunschweig\IesResourceWatcher\Index\ResourceLayout;

/**
 * Index in a JSON file. A resource's ID is its file name; its URL is the
 * path with .html. Files in the control directory inject faults.
 */
final class FakeTarget implements IndexTarget
{
    /** @var array<string, string> path => content as planned */
    private array $pinned = [];

    public function __construct(private readonly string $root, private readonly string $control) {}

    public function layout(): ResourceLayout
    {
        return new ResourceLayout($this->root, ['.php'], ['WEB-IES'], [], ['#-1015t\.php#']);
    }

    public function fullIndexGuard(): FullIndexGuard
    {
        return new NativeIndexGuard(new FlockProbe($this->control . '/full.lock'), $this->control . '/full.status');
    }

    public function plan(array $paths): array
    {
        $plan = [];
        foreach ($paths as $path) {
            $file = $this->root . $path;
            if (!is_file($file)) {
                $plan[$path] = PathPlan::excluded('missing');
                continue;
            }
            $content = (string) file_get_contents($file);
            if ($content === 'broken') {
                throw new \RuntimeException('broken resource ' . $path);
            }
            if ($content === 'noindex') {
                $plan[$path] = PathPlan::excluded('filter');
                continue;
            }
            $this->pinned[$path] = $content;
            $plan[$path] = PathPlan::indexed([self::document($path)], ['content' => $content]);
        }
        return $plan;
    }

    public function apply(array $plan, array $previous): void
    {
        $this->hook('crash-before-write');
        if (is_file($this->control . '/fail')) {
            throw new \RuntimeException('injected backend failure');
        }
        if (is_file($this->control . '/hang')) {
            unlink($this->control . '/hang');
            sleep(5);
        }
        if (is_file($this->control . '/slow')) {
            usleep(800000);
        }
        $this->mutate(function (array &$index) use ($plan, $previous): void {
            foreach ($plan as $path => $pathPlan) {
                foreach ($pathPlan->documents as $document) {
                    $index[$document->id] = ['url' => $document->url, 'content' => $this->pinned[$path], 'path' => $path];
                }
            }
            foreach ($previous as $path => $documents) {
                foreach ($documents ?? [] as $old) {
                    if (!$plan[$path]->holds($old) && ($index[$old->id]['url'] ?? null) === $old->url) {
                        unset($index[$old->id]);
                    }
                }
            }
        });
        $this->hook('crash-after-write');
    }

    public function verify(array $plan, array $previous): void
    {
        $index = self::read($this->control . '/index.json');
        foreach ($plan as $path => $pathPlan) {
            foreach ($pathPlan->documents as $document) {
                if (($index[$document->id]['content'] ?? null) !== $pathPlan->expectations['content']) {
                    throw new \RuntimeException('not reflected: ' . $path);
                }
            }
        }
    }

    public function identify(array $paths): array
    {
        $index = self::read($this->control . '/index.json');
        $result = [];
        foreach ($paths as $path) {
            if (@file_get_contents($this->root . $path) === 'unidentifiable') {
                throw new \RuntimeException('cannot identify ' . $path);
            }
            $document = self::document($path);
            $result[$path] = ($index[$document->id]['url'] ?? null) === $document->url ? [$document] : [];
        }
        return $result;
    }

    public static function document(string $path): Document
    {
        return new Document(basename($path, '.php'), 'test', substr($path, 0, -4) . '.html');
    }

    /** @return array<string, array{url: string, content: string, path: string}> */
    public static function read(string $file): array
    {
        return is_file($file) ? json_decode((string) file_get_contents($file), true) : [];
    }

    private function mutate(callable $change): void
    {
        $file = $this->control . '/index.json';
        $index = self::read($file);
        $change($index);
        file_put_contents($file . '.tmp', json_encode($index));
        rename($file . '.tmp', $file);
        file_put_contents($this->control . '/writes', '.', FILE_APPEND);
    }

    private function hook(string $name): void
    {
        if (is_file($this->control . '/' . $name)) {
            unlink($this->control . '/' . $name);
            exit(9); // as if killed
        }
    }
}

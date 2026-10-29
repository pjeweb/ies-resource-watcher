<?php

declare(strict_types=1);

namespace StadtBraunschweig\IesResourceWatcher\Watcher;

use StadtBraunschweig\IesResourceWatcher\Index\ResourceLayout;

/**
 * Which files below the resource root are watched, and how paths map to it.
 *
 * Paths are relative to the root and start with '/'. The target's layout
 * supplies the resource conventions; configuration adds scopes and pruning.
 */
final class PathPolicy
{
    public readonly string $root;

    /** @var list<string> */
    public readonly array $scopes;

    /** @var list<string> */
    private readonly array $prune;

    /**
     * @param list<string> $scopes directories (relative to the root) that are watched
     * @param list<string> $prune additional shell patterns of directory names to skip
     */
    public function __construct(private readonly ResourceLayout $layout, array $scopes = ['/'], array $prune = [])
    {
        $root = realpath($layout->root);
        if ($root === false || !is_dir($root)) {
            throw new \InvalidArgumentException('resource root does not exist: ' . $layout->root);
        }
        $this->root = $root;
        $normalized = [];
        foreach ($scopes === [] ? ['/'] : $scopes as $scope) {
            if (!str_starts_with($scope, '/') || in_array('..', explode('/', $scope), true) || str_contains($scope, "\0")) {
                throw new \InvalidArgumentException('invalid scope: ' . $scope);
            }
            $normalized[] = $scope === '/' ? '/' : rtrim($scope, '/');
        }
        $this->scopes = array_values(array_unique($normalized));
        $this->prune = array_values(array_unique([...$layout->prune, ...$prune]));
    }

    public function inScope(string $path): bool
    {
        foreach ($this->scopes as $scope) {
            if ($scope === '/' || $path === $scope || str_starts_with($path, $scope . '/')) {
                return true;
            }
        }
        return false;
    }

    /** Directory on the way to a scope, watched so that the scope itself can appear. */
    public function leadsToScope(string $path): bool
    {
        $prefix = rtrim($path, '/') . '/';
        foreach ($this->scopes as $scope) {
            if ($scope !== '/' && str_starts_with($scope, $prefix)) {
                return true;
            }
        }
        return false;
    }

    public function isCandidate(string $path): bool
    {
        // Paths travel as JSON between processes, which requires UTF-8.
        if (!str_starts_with($path, '/') || str_contains($path, "\0") || preg_match('//u', $path) !== 1
            || !$this->inScope($path)) {
            return false;
        }
        $suffix = false;
        foreach ($this->layout->suffixes as $ending) {
            $suffix = $suffix || str_ends_with($path, $ending);
        }
        if (!$suffix) {
            return false;
        }
        $parts = explode('/', substr($path, 1));
        $name = array_pop($parts);
        if ($name === '' || $name[0] === '.') {
            return false;
        }
        foreach ($parts as $part) {
            if ($part === '' || $part === '.' || $part === '..' || $this->skipsDirectory($part)) {
                return false;
            }
        }
        foreach ($this->layout->excludePatterns as $pattern) {
            if (preg_match($pattern, $path) === 1) {
                return false;
            }
        }
        return true;
    }

    /** Hidden directories and pruned names are never descended into. */
    public function skipsDirectory(string $name): bool
    {
        if ($name[0] === '.' && !in_array($name, $this->layout->hiddenAllowed, true)) {
            return true;
        }
        foreach ($this->prune as $pattern) {
            if (fnmatch($pattern, $name)) {
                return true;
            }
        }
        return false;
    }

    public function relative(string $absolute): string
    {
        if ($absolute === $this->root) {
            return '/';
        }
        if (!str_starts_with($absolute, $this->root . '/')) {
            throw new \InvalidArgumentException('path outside the resource root: ' . $absolute);
        }
        return substr($absolute, strlen($this->root));
    }

    /**
     * Absolute location of a relative path; refuses anything that resolves
     * outside the root, including through a symlinked directory.
     */
    public function physical(string $path): string
    {
        if (!str_starts_with($path, '/') || in_array('..', explode('/', $path), true) || str_contains($path, "\0")) {
            throw new \InvalidArgumentException('invalid resource path: ' . $path);
        }
        if ($path === '/') {
            return $this->root;
        }
        $target = $this->root . $path;
        // The final component may be missing or a symlink (never a resource);
        // the nearest existing directory above it must resolve inside the root.
        $directory = dirname($target);
        while (($resolved = realpath($directory)) === false && $directory !== dirname($directory)) {
            $directory = dirname($directory);
        }
        if ($resolved === false || ($resolved !== $this->root && !str_starts_with($resolved, $this->root . '/'))) {
            throw new \InvalidArgumentException('path escapes the resource root: ' . $path);
        }
        return $target;
    }
}

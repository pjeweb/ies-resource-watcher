<?php

declare(strict_types=1);

namespace StadtBraunschweig\IesResourceWatcher\Index;

/**
 * File conventions of the published resources, supplied by the target.
 */
final class ResourceLayout
{
    /**
     * @param string $root directory against which resource paths are resolved
     * @param list<string> $suffixes file name endings of resource files
     * @param list<string> $prune shell patterns of directory names never descended into
     * @param list<string> $hiddenAllowed dot-directories that still hold resources
     * @param list<string> $excludePatterns PCRE patterns of relative paths that are never resources
     */
    public function __construct(
        public readonly string $root,
        public readonly array $suffixes = ['.php'],
        public readonly array $prune = [],
        public readonly array $hiddenAllowed = [],
        public readonly array $excludePatterns = [],
    ) {}
}

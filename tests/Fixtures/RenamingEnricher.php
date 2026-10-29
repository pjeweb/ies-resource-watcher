<?php

declare(strict_types=1);

namespace StadtBraunschweig\IesResourceWatcher\Tests\Fixtures;

use Atoolo\Resource\Resource;
use Atoolo\Search\Service\Indexer\DocumentEnricher;
use Atoolo\Search\Service\Indexer\IndexDocument;

/**
 * Rewrites sp_name like the CityGov bundle does for products, after the
 * default enricher set it from the resource name.
 *
 * @implements DocumentEnricher<IndexDocument>
 */
final class RenamingEnricher implements DocumentEnricher
{
    public function enrichDocument(Resource $resource, IndexDocument $doc, string $processId): IndexDocument
    {
        if ($resource->objectType === 'citygovProduct') {
            $doc->sp_name = (string) preg_replace('/ \[OZG\]$/', '', $resource->name);
        }
        return $doc;
    }

    public function cleanup(): void {}
}

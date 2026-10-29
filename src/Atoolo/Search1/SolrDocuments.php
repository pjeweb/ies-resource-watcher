<?php

declare(strict_types=1);

namespace StadtBraunschweig\IesResourceWatcher\Atoolo\Search1;

use Atoolo\Search\Service\SolrClientFactory;
use Solarium\Core\Client\Request;

/**
 * Exact reads and conditional deletes of internal documents.
 *
 * Uses realtime get on stored fields: the url search field is analysed text
 * and cannot tell a renamed document apart.
 */
final class SolrDocuments
{
    private const SOURCE = 'internal';

    public function __construct(private readonly SolrClientFactory $clients) {}

    /**
     * Stored fields of internal documents by ID (realtime get).
     *
     * @param list<string> $ids
     * @param list<string> $fields
     * @return array<string, array<string, mixed>>
     */
    public function get(string $index, array $ids, array $fields = ['id', 'url']): array
    {
        $found = [];
        foreach (array_chunk($ids, 100) as $chunk) {
            $data = $this->request($index, 'get', [
                'ids' => implode(',', $chunk),
                'fl' => implode(',', array_unique([...$fields, 'id', 'sp_source', 'sp_objecttype'])),
            ]);
            foreach ($data['response']['docs'] ?? [] as $document) {
                if (self::isInternal($document)) {
                    $found[(string) $document['id']] = $document;
                }
            }
        }
        return $found;
    }

    /**
     * Delete a document only while it still has the remembered URL. The
     * version condition protects a concurrent rewrite; Solr answers 409 and
     * the job is retried.
     */
    public function deleteIfUnchanged(string $index, string $id, string $url): void
    {
        $data = $this->request($index, 'get', ['id' => $id, 'fl' => 'id,url,sp_source,sp_objecttype,_version_']);
        $document = $data['doc'] ?? null;
        if (!is_array($document) || ($document['url'] ?? null) !== $url || !self::isInternal($document)) {
            return;
        }
        if (empty($document['_version_'])) {
            throw new \RuntimeException('Solr document version missing');
        }
        $request = new Request();
        $request->setHandler('update')->setMethod(Request::METHOD_POST)->setContentType('application/json')
            ->setRawData(json_encode(
                ['delete' => ['id' => $id, '_version_' => $document['_version_']], 'commit' => new \stdClass()],
                JSON_THROW_ON_ERROR,
            ));
        $response = $this->clients->create($index)->executeRequest($request);
        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException(sprintf('conditional deletion of %s in %s failed with HTTP %d', $id, $index, $response->getStatusCode()));
        }
    }

    /**
     * Searchable state of a document, as a reader would see it.
     *
     * @param list<string> $fields
     * @return list<array<string, mixed>>
     */
    public function search(string $index, string $id, array $fields): array
    {
        $data = $this->request($index, 'select', [
            'q' => 'id:' . json_encode($id, JSON_THROW_ON_ERROR),
            'rows' => '2',
            'fl' => implode(',', array_unique([...$fields, 'sp_source', 'sp_objecttype'])),
        ]);
        return array_values(array_filter($data['response']['docs'] ?? [], self::isInternal(...)));
    }

    /**
     * Written by the internal indexer. Its enricher stops before sp_source
     * for search tips, so those carry no source at all.
     *
     * @param array<string, mixed> $document
     */
    private static function isInternal(array $document): bool
    {
        if (!isset($document['sp_source'])) {
            return ($document['sp_objecttype'] ?? null) === 'searchTip';
        }
        return in_array(self::SOURCE, (array) $document['sp_source'], true);
    }

    /**
     * @param array<string, string> $parameters
     * @return array<string, mixed>
     */
    private function request(string $index, string $handler, array $parameters): array
    {
        $request = new Request();
        $request->setHandler($handler)->addParam('wt', 'json');
        foreach ($parameters as $name => $value) {
            $request->addParam($name, $value);
        }
        $response = $this->clients->create($index)->executeRequest($request);
        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException(sprintf('Solr %s on %s failed with HTTP %d', $handler, $index, $response->getStatusCode()));
        }
        return json_decode($response->getBody(), true, 64, JSON_THROW_ON_ERROR);
    }
}

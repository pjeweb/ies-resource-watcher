<?php

declare(strict_types=1);

namespace StadtBraunschweig\IesResourceWatcher\Tests\Fixtures;

use Atoolo\Search\Service\SolrClientFactory;
use Solarium\Client;
use Solarium\Core\Client\Adapter\AdapterInterface;
use Solarium\Core\Client\Endpoint;
use Solarium\Core\Client\Request;
use Solarium\Core\Client\Response;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * Just enough Solr for the Atoolo adapter: core status, JSON updates with
 * optimistic concurrency, realtime get and id/source queries. Commits are
 * immediate; stored values are returned as sent.
 */
final class MemorySolr implements SolrClientFactory, AdapterInterface
{
    /** @var array<string, array<string, array<string, mixed>>> core => id => document */
    public array $cores = [];

    /** @var list<array{core: string, handler: string, body: string}> */
    public array $requests = [];

    /** Called before every update request; lets a test change state concurrently. */
    public ?\Closure $beforeUpdate = null;

    private int $version = 1;

    /**
     * @param list<string> $cores
     * @param string|null $file shares the cores between processes
     */
    public function __construct(array $cores, private readonly ?string $file = null)
    {
        foreach ($cores as $core) {
            $this->cores[$core] = [];
        }
    }

    public function create(string $core): Client
    {
        return new Client($this, new EventDispatcher(), ['endpoint' => ['memory' => ['host' => 'memory', 'core' => $core]]]);
    }

    public function execute(Request $request, Endpoint $endpoint): Response
    {
        if ($this->file !== null && is_file($this->file)) {
            $this->cores = json_decode((string) file_get_contents($this->file), true);
        }
        try {
            return $this->handle($request, $endpoint);
        } finally {
            if ($this->file !== null) {
                file_put_contents($this->file . '.tmp', json_encode($this->cores));
                rename($this->file . '.tmp', $this->file);
            }
        }
    }

    private function handle(Request $request, Endpoint $endpoint): Response
    {
        $core = (string) $endpoint->getCore();
        $handler = $request->getHandler();
        $this->requests[] = ['core' => $core, 'handler' => $handler, 'body' => (string) $request->getRawData()];
        $parameters = $request->getParams();
        if ($handler === 'admin/cores') {
            return $this->respond(['initFailures' => [], 'status' => array_map(fn($documents) => ['index' => ['numDocs' => count($documents)]], $this->cores)]);
        }
        if (!isset($this->cores[$core])) {
            return $this->respond(['error' => ['msg' => 'no core ' . $core]], 404);
        }
        return match ($handler) {
            'update' => $this->update($core, (string) $request->getRawData()),
            'get' => $this->get($core, $parameters),
            'select' => $this->select($core, $parameters),
            default => $this->respond(['error' => ['msg' => 'unsupported ' . $handler]], 400),
        };
    }

    private function update(string $core, string $body): Response
    {
        if ($this->beforeUpdate !== null) {
            ($this->beforeUpdate)($this, $core);
        }
        foreach (self::members($body) as [$command, $value]) {
            if ($command === 'add') {
                $document = $value['doc'];
                $document['_version_'] = ++$this->version;
                $this->cores[$core][(string) $document['id']] = $document;
            } elseif ($command === 'delete') {
                foreach (array_is_list($value) ? $value : [$value] as $target) {
                    if (isset($target['query'])) {
                        throw new \LogicException('delete by query is not expected from a job');
                    }
                    $id = (string) (is_array($target) ? $target['id'] : $target);
                    $expected = is_array($target) ? ($target['_version_'] ?? null) : null;
                    $current = $this->cores[$core][$id]['_version_'] ?? null;
                    if ($expected !== null && $expected !== $current) {
                        return $this->respond(['error' => ['msg' => 'version conflict for ' . $id, 'code' => 409]], 409);
                    }
                    unset($this->cores[$core][$id]);
                }
            }
        }
        return $this->respond([]);
    }

    /** @param array<string, mixed> $parameters */
    private function get(string $core, array $parameters): Response
    {
        $fields = explode(',', (string) ($parameters['fl'] ?? ''));
        if (isset($parameters['id'])) {
            $document = $this->cores[$core][(string) $parameters['id']] ?? null;
            return $this->respond(['doc' => $document === null ? null : self::only($document, $fields)]);
        }
        $documents = [];
        foreach (explode(',', (string) $parameters['ids']) as $id) {
            if (isset($this->cores[$core][$id])) {
                $documents[] = self::only($this->cores[$core][$id], $fields);
            }
        }
        return $this->respond(['response' => ['numFound' => count($documents), 'docs' => $documents]]);
    }

    /** @param array<string, mixed> $parameters */
    private function select(string $core, array $parameters): Response
    {
        if (!preg_match('/^id:(".*")$/', (string) $parameters['q'], $match)) {
            throw new \LogicException('unsupported query ' . $parameters['q']);
        }
        $document = $this->cores[$core][(string) json_decode($match[1])] ?? null;
        $documents = [];
        if ($document !== null && (($parameters['fq'] ?? '') !== 'sp_source:internal' || in_array('internal', (array) ($document['sp_source'] ?? []), true))) {
            $documents[] = self::only($document, explode(',', (string) $parameters['fl']));
        }
        return $this->respond(['response' => ['numFound' => count($documents), 'docs' => $documents]]);
    }

    /**
     * Top-level members of a JSON object that may repeat keys ("add", "add", ...).
     *
     * @return list<array{0: string, 1: mixed}>
     */
    private static function members(string $json): array
    {
        $members = [];
        $depth = 0;
        $inString = false;
        $start = 1;
        for ($i = 0, $length = strlen($json); $i < $length; $i++) {
            $char = $json[$i];
            if ($inString) {
                if ($char === '\\') {
                    $i++;
                } elseif ($char === '"') {
                    $inString = false;
                }
                continue;
            }
            if ($char === '"') {
                $inString = true;
            } elseif ($char === '{' || $char === '[') {
                $depth++;
            } elseif ($char === '}' || $char === ']') {
                $depth--;
                if ($depth === 0) {
                    $members[] = substr($json, $start, $i - $start);
                }
            } elseif ($char === ',' && $depth === 1) {
                $members[] = substr($json, $start, $i - $start);
                $start = $i + 1;
            }
        }
        $result = [];
        foreach ($members as $member) {
            if (trim($member) === '') {
                continue;
            }
            $decoded = json_decode('{' . $member . '}', true, 64, JSON_THROW_ON_ERROR);
            $result[] = [(string) array_key_first($decoded), reset($decoded)];
        }
        return $result;
    }

    /**
     * @param array<string, mixed> $document
     * @param list<string> $fields
     * @return array<string, mixed>
     */
    private static function only(array $document, array $fields): array
    {
        return $fields === [''] ? $document : array_intersect_key($document, array_flip($fields));
    }

    /** @param array<string, mixed> $data */
    private function respond(array $data, int $status = 200): Response
    {
        return new Response(
            json_encode(['responseHeader' => ['status' => $status === 200 ? 0 : $status, 'QTime' => 0]] + $data, JSON_THROW_ON_ERROR),
            ['HTTP/1.1 ' . $status . ' ' . ($status === 200 ? 'OK' : 'Error')],
        );
    }
}

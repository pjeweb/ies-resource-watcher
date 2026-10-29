<?php

declare(strict_types=1);

namespace StadtBraunschweig\IesResourceWatcher\Index;

/**
 * Identity of one document in an index target.
 *
 * The URL is the stored locator of the document. It is kept separately from
 * the resource path because media and ID-based layouts index a different URL.
 */
final class Document implements \JsonSerializable
{
    public function __construct(
        public readonly string $id,
        public readonly string $index,
        public readonly string $url,
    ) {
        if ($id === '' || $index === '') {
            throw new \InvalidArgumentException('document id and index are required');
        }
    }

    /** @param array{id: string|int, index: string, url?: string|null} $data */
    public static function fromArray(array $data): self
    {
        return new self((string) $data['id'], $data['index'], (string) ($data['url'] ?? ''));
    }

    /**
     * @param list<array{id: string|int, index: string, url?: string|null}>|null $data
     * @return list<self>|null
     */
    public static function listFromArray(?array $data): ?array
    {
        return $data === null ? null : array_map(self::fromArray(...), $data);
    }

    public function sameIdentity(self $other): bool
    {
        return $this->id === $other->id && $this->index === $other->index;
    }

    public function equals(self $other): bool
    {
        return $this->sameIdentity($other) && $this->url === $other->url;
    }

    /** @return array{id: string, index: string, url: string} */
    public function jsonSerialize(): array
    {
        return ['id' => $this->id, 'index' => $this->index, 'url' => $this->url];
    }
}

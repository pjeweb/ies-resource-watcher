<?php

declare(strict_types=1);

namespace StadtBraunschweig\IesResourceWatcher\Queue;

use StadtBraunschweig\IesResourceWatcher\Index\Document;

/** One queued path as selected for a job. */
final class QueuedPath implements \JsonSerializable
{
    /** @param list<Document>|null $documents last known documents; null if unknown */
    public function __construct(
        public readonly string $path,
        public readonly int $rev,
        public readonly int $attempts,
        public readonly string $signature,
        public readonly ?array $documents,
    ) {}

    /** @param array{path: string, rev: int|string, attempts: int|string, sig: string, documents: string|null} $row */
    public static function fromRow(array $row): self
    {
        return new self($row['path'], (int) $row['rev'], (int) $row['attempts'], $row['sig'], QueueStore::decode($row['documents']));
    }

    /** @param array{path: string, rev: int, attempts?: int, signature?: string, documents: list<array{id: string, index: string, url: string}>|null} $data */
    public static function fromArray(array $data): self
    {
        return new self($data['path'], $data['rev'], $data['attempts'] ?? 0, $data['signature'] ?? '', Document::listFromArray($data['documents']));
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'path' => $this->path, 'rev' => $this->rev, 'attempts' => $this->attempts,
            'signature' => $this->signature, 'documents' => $this->documents,
        ];
    }
}

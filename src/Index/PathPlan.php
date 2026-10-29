<?php

declare(strict_types=1);

namespace StadtBraunschweig\IesResourceWatcher\Index;

/**
 * What the target will hold for one resource path after a job.
 *
 * An excluded path holds no document: it was deleted, filtered or is not
 * indexable. Its previous documents are removed.
 */
final class PathPlan implements \JsonSerializable
{
    /**
     * @param list<Document> $documents
     * @param array<string, mixed> $expectations target-specific values checked by verify()
     */
    private function __construct(
        public readonly array $documents,
        public readonly ?string $reason,
        public readonly array $expectations,
    ) {}

    /**
     * @param list<Document> $documents
     * @param array<string, mixed> $expectations
     */
    public static function indexed(array $documents, array $expectations = []): self
    {
        if ($documents === []) {
            throw new \InvalidArgumentException('an indexed path needs at least one document');
        }
        return new self($documents, null, $expectations);
    }

    public static function excluded(string $reason): self
    {
        return new self([], $reason, []);
    }

    public function isExcluded(): bool
    {
        return $this->documents === [];
    }

    public function holds(Document $document): bool
    {
        foreach ($this->documents as $current) {
            if ($current->sameIdentity($document)) {
                return true;
            }
        }
        return false;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->isExcluded()
            ? ['excluded' => true, 'reason' => $this->reason]
            : ['documents' => $this->documents];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        if (!empty($data['excluded'])) {
            return self::excluded((string) ($data['reason'] ?? 'excluded'));
        }
        /** @var list<array{id: string, index: string, url: string}> $documents */
        $documents = $data['documents'] ?? [];
        return self::indexed(array_map(Document::fromArray(...), $documents));
    }
}

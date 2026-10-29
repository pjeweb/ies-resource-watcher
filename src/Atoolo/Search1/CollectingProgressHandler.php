<?php

declare(strict_types=1);

namespace StadtBraunschweig\IesResourceWatcher\Atoolo\Search1;

use Atoolo\Search\Dto\Indexer\IndexerStatus;
use Atoolo\Search\Service\Indexer\IndexerProgressHandler;

/**
 * In-memory progress for a job. The native handler would overwrite the
 * status file of a running full index.
 */
final class CollectingProgressHandler implements IndexerProgressHandler
{
    /** @var list<string> */
    public array $errors = [];

    private IndexerStatus $status;

    public function __construct()
    {
        $this->status = IndexerStatus::empty();
    }

    public function prepare(string $message): void {}

    public function start(int $total): void
    {
        $this->status->total = $total;
    }

    public function startUpdate(int $total): void
    {
        $this->start($total);
    }

    public function advance(int $step): void
    {
        $this->status->processed += $step;
    }

    public function skip(int $step): void
    {
        $this->status->skipped += $step;
    }

    public function error(\Throwable $throwable): void
    {
        $this->errors[] = $throwable->getMessage();
        $this->status->errors++;
    }

    public function finish(): void {}

    public function abort(): void
    {
        throw new \RuntimeException('index update aborted');
    }

    public function getStatus(): IndexerStatus
    {
        return $this->status;
    }
}

<?php

declare(strict_types=1);

namespace StadtBraunschweig\IesResourceWatcher\Runtime;

/** One JSON object per line, for journald and log shippers. */
final class EventLog
{
    /** @param resource|null $stream defaults to STDOUT */
    public function __construct(private $stream = null) {}

    /** @param array<string, mixed> $fields */
    public function write(string $event, array $fields = []): void
    {
        $line = json_encode(
            ['at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.uP'), 'event' => $event] + $fields,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR,
        );
        fwrite($this->stream ?? \STDOUT, $line . "\n");
    }
}

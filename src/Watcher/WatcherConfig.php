<?php

declare(strict_types=1);

namespace StadtBraunschweig\IesResourceWatcher\Watcher;

final class WatcherConfig
{
    /**
     * @param list<string> $scopes directories below the resource root that are watched
     * @param list<string> $prune shell patterns of directory names that are skipped
     * @param list<string> $restartOnChange symlinks (e.g. a release) whose target must stay the same
     */
    public function __construct(
        public readonly string $stateDir,
        public readonly array $scopes = ['/'],
        public readonly array $prune = [],
        public readonly float $quietPeriod = 0.75,
        public readonly int $batchSize = 32,
        public readonly float $reconcileInterval = 3600.0,
        public readonly float $jobTimeout = 120.0,
        public readonly int $identifyBatchSize = 250,
        public readonly array $restartOnChange = [],
    ) {
        if ($batchSize < 1 || $quietPeriod < 0 || $reconcileInterval <= 0 || $jobTimeout <= 0 || $identifyBatchSize < 0) {
            throw new \InvalidArgumentException('invalid watcher configuration');
        }
    }

    /** @param array<string, mixed> $config bundle configuration */
    public static function fromArray(array $config): self
    {
        return new self(
            $config['state_dir'],
            array_values($config['scopes'] ?? ['/']),
            array_values($config['prune'] ?? []),
            (float) ($config['quiet_period'] ?? 0.75),
            (int) ($config['batch_size'] ?? 32),
            (float) ($config['reconcile_interval'] ?? 3600),
            (float) ($config['job_timeout'] ?? 120),
            (int) ($config['identify_batch_size'] ?? 250),
            array_values($config['restart_on_change'] ?? []),
        );
    }

    public function queueFile(): string
    {
        return $this->stateDir . '/queue.sqlite';
    }

    public function statusFile(): string
    {
        return $this->stateDir . '/status.json';
    }
}

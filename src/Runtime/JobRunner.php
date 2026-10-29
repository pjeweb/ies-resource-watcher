<?php

declare(strict_types=1);

namespace StadtBraunschweig\IesResourceWatcher\Runtime;

use StadtBraunschweig\IesResourceWatcher\Index\Document;
use StadtBraunschweig\IesResourceWatcher\Index\IndexTarget;
use StadtBraunschweig\IesResourceWatcher\Index\PathPlan;
use StadtBraunschweig\IesResourceWatcher\Queue\QueuedPath;
use StadtBraunschweig\IesResourceWatcher\Queue\QueueStore;
use StadtBraunschweig\IesResourceWatcher\Watcher\PathPolicy;

/**
 * Executes one job in the job process.
 *
 * The order is fixed here rather than left to each target: every identity a
 * job may write is durable before the first write. A crash at any later
 * point leaves the path queued with enough information to remove what was
 * written, even if the file is deleted while the watcher is down.
 */
final class JobRunner
{
    public function __construct(
        private readonly IndexTarget $target,
        private readonly QueueStore $store,
        private readonly PathPolicy $policy,
    ) {}

    /**
     * @param list<QueuedPath> $items
     * @return array<string, PathPlan>
     */
    public function sync(array $items): array
    {
        $paths = [];
        $previous = [];
        foreach ($items as $item) {
            $this->policy->physical($item->path); // rejects anything outside the root
            $paths[] = $item->path;
            $previous[$item->path] = $item->documents;
        }
        $plan = $this->target->plan($paths);
        $missing = array_diff($paths, array_keys($plan));
        $extra = array_diff(array_keys($plan), $paths);
        if ($missing !== [] || $extra !== []) {
            throw new \LogicException('target plan does not match the batch');
        }
        $intents = [];
        foreach ($plan as $path => $pathPlan) {
            if (!$pathPlan->isExcluded()) {
                $intents[$path] = $pathPlan->documents;
            }
        }
        $this->store->recordIntents($intents);
        $this->target->apply($plan, $previous);
        $this->target->verify($plan, $previous);
        return $plan;
    }

    /**
     * @param list<string> $paths
     * @return array<string, list<Document>>
     */
    public function identify(array $paths): array
    {
        foreach ($paths as $path) {
            $this->policy->physical($path);
        }
        return $this->target->identify($paths);
    }

    /**
     * Execute a request from the watcher and describe the outcome as JSON.
     *
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function handle(array $request): array
    {
        return match ($request['action'] ?? null) {
            'sync' => ['ok' => true, 'paths' => $this->sync(array_map(QueuedPath::fromArray(...), $request['items']))],
            'identify' => ['ok' => true, 'documents' => $this->identify(array_values($request['paths']))],
            default => throw new \InvalidArgumentException('unknown job action'),
        };
    }
}

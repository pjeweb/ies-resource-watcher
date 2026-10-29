<?php

declare(strict_types=1);

namespace StadtBraunschweig\IesResourceWatcher\Index;

use StadtBraunschweig\IesResourceWatcher\FullIndex\FullIndexGuard;

/**
 * Adapter between the watcher and a concrete index.
 *
 * A job calls plan(), records the planned identities durably, then calls
 * apply() and verify(). Nothing may be written to the index during plan().
 * Every method runs in a short-lived job process except layout() and
 * fullIndexGuard(), which the long-running watcher calls.
 */
interface IndexTarget
{
    public function layout(): ResourceLayout;

    /**
     * Read-only view of the native full index run. Must never acquire its lock.
     */
    public function fullIndexGuard(): FullIndexGuard;

    /**
     * Determine the documents for the given paths without writing.
     *
     * Missing paths and resources rejected by the native rules are excluded.
     * The target keeps whatever it loaded so that apply() writes exactly the
     * planned version, even if a newer file arrives in between.
     *
     * @param list<string> $paths
     * @return array<string, PathPlan> keyed by path, one entry per path
     */
    public function plan(array $paths): array;

    /**
     * Write the plan, then remove previous documents the plan no longer holds.
     *
     * Removal must be conditional: a document that was renamed or rewritten
     * by someone else in the meantime must survive.
     *
     * @param array<string, PathPlan> $plan
     * @param array<string, list<Document>|null> $previous last known documents; null if unknown
     */
    public function apply(array $plan, array $previous): void;

    /**
     * Throw unless the index reflects the plan and the removals.
     *
     * @param array<string, PathPlan> $plan
     * @param array<string, list<Document>|null> $previous
     */
    public function verify(array $plan, array $previous): void;

    /**
     * Documents the index currently holds for existing paths.
     *
     * Used once for files recorded as a baseline, so that a later deletion
     * can remove what was indexed before the watcher was installed.
     *
     * A path whose resource cannot be read holds nothing ([]). Throw only if
     * the index itself cannot be queried, so that the job is retried later.
     *
     * @param list<string> $paths
     * @return array<string, list<Document>> keyed by path
     */
    public function identify(array $paths): array;
}

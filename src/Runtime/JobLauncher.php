<?php

declare(strict_types=1);

namespace StadtBraunschweig\IesResourceWatcher\Runtime;

use Symfony\Component\Process\Process;

/**
 * Starts one job in a fresh process of the same application.
 *
 * Each job boots its own container: native loaders and enrichers start
 * without caches, a fatal error or memory leak ends with the job, and a hung
 * job can be killed without stopping the watcher.
 */
final class JobLauncher
{
    public const COMMAND = 'ies-resource-watcher:job';

    /** @param list<string> $console command line of the console, e.g. [PHP_BINARY, 'bin/console'] */
    public function __construct(private readonly array $console) {}

    /** @param list<string> $console empty for the application's own bin/console */
    public static function forConsole(array $console, string $projectDir): self
    {
        return new self($console !== [] ? $console : [\PHP_BINARY, $projectDir . '/bin/console']);
    }

    /** @param array<string, mixed> $request */
    public function start(array $request): Process
    {
        $process = new Process(
            [...$this->console, self::COMMAND, '--no-interaction'],
            null,
            // Console exports SHELL_VERBOSITY; a quiet watcher must not silence the job's result.
            ['SHELL_VERBOSITY' => false],
            json_encode($request, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            null,
        );
        $process->start();
        return $process;
    }
}

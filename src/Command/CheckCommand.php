<?php

declare(strict_types=1);

namespace StadtBraunschweig\IesResourceWatcher\Command;

use StadtBraunschweig\IesResourceWatcher\Index\IndexTarget;
use StadtBraunschweig\IesResourceWatcher\Watcher\PathPolicy;
use StadtBraunschweig\IesResourceWatcher\Watcher\WatcherConfig;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** Validates an installation without watching or writing anything. */
#[AsCommand(name: 'ies-resource-watcher:check', description: 'Check target, index, native lock and paths without starting the watcher')]
final class CheckCommand extends Command
{
    public function __construct(
        private readonly WatcherConfig $config,
        private readonly IndexTarget $target,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $policy = new PathPolicy($this->target->layout(), $this->config->scopes, $this->config->prune);
        $guard = $this->target->fullIndexGuard();
        $report = [
            'root' => $policy->root,
            'scopes' => array_map(fn(string $scope) => [$scope, is_dir($policy->physical($scope))], $policy->scopes),
            'state_dir' => $this->config->stateDir,
            'state_writable' => is_dir($this->config->stateDir) ? is_writable($this->config->stateDir) : is_writable(dirname($this->config->stateDir)),
            'full_index' => method_exists($guard, 'describe') ? $guard->describe() : get_debug_type($guard),
            'full_index_busy' => $guard->isBusy(),
            'full_index_status_found' => $guard->checkpoint() !== null,
        ];
        $output->writeln((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), OutputInterface::OUTPUT_RAW);
        $problems = [];
        if (!$report['state_writable']) {
            $problems[] = 'state directory is not writable';
        }
        foreach ($report['scopes'] as [$scope, $exists]) {
            if (!$exists) {
                $problems[] = 'scope does not exist yet: ' . $scope;
            }
        }
        foreach ($problems as $problem) {
            $output->writeln('<comment>' . $problem . '</comment>');
        }
        return $report['state_writable'] ? self::SUCCESS : self::FAILURE;
    }
}

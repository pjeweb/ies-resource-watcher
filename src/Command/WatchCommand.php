<?php

declare(strict_types=1);

namespace StadtBraunschweig\IesResourceWatcher\Command;

use StadtBraunschweig\IesResourceWatcher\Index\IndexTarget;
use StadtBraunschweig\IesResourceWatcher\Runtime\JobLauncher;
use StadtBraunschweig\IesResourceWatcher\Watcher\Watcher;
use StadtBraunschweig\IesResourceWatcher\Watcher\WatcherConfig;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'ies-resource-watcher:watch', description: 'Watch published resources and index them as they arrive')]
final class WatchCommand extends Command implements SignalableCommandInterface
{
    private ?Watcher $watcher = null;

    public function __construct(
        private readonly WatcherConfig $config,
        private readonly IndexTarget $target,
        private readonly JobLauncher $launcher,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $output->writeln('<error>Run as the user of the native indexer, not as root.</error>');
            return self::FAILURE;
        }
        $this->watcher = new Watcher($this->config, $this->target, $this->launcher);
        $this->watcher->run();
        return self::SUCCESS;
    }

    /**
     * SIGUSR1/2 are subscribed only to be ignored: Symfony's console would
     * otherwise exit with status 0, which systemd does not restart.
     *
     * @return list<int>
     */
    public function getSubscribedSignals(): array
    {
        return \defined('SIGTERM') ? [\SIGTERM, \SIGINT, \SIGUSR1, \SIGUSR2] : [];
    }

    public function handleSignal(int $signal, int|false $previousExitCode = 0): int|false
    {
        if ($signal === \SIGTERM || $signal === \SIGINT) {
            $this->watcher?->stop();
        }
        return false;
    }
}

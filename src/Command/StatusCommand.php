<?php

declare(strict_types=1);

namespace StadtBraunschweig\IesResourceWatcher\Command;

use StadtBraunschweig\IesResourceWatcher\Watcher\WatcherConfig;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** Health check for a timer or monitoring; exit status 1 if unhealthy. */
#[AsCommand(name: 'ies-resource-watcher:status', description: 'Show the watcher status and fail if it is unhealthy')]
final class StatusCommand extends Command
{
    public function __construct(private readonly WatcherConfig $config)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('max-age', null, InputOption::VALUE_REQUIRED, 'Seconds after which the status counts as stale', '30')
            ->addOption('max-pending-age', null, InputOption::VALUE_REQUIRED, 'Seconds a queued path may wait', '300');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $file = $this->config->statusFile();
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (!is_array($data)) {
            $output->writeln('<error>No status at ' . $file . '</error>');
            return self::FAILURE;
        }
        $output->writeln((string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), OutputInterface::OUTPUT_RAW);
        $problems = [];
        if (time() - (int) filemtime($file) >= (int) $input->getOption('max-age')) {
            $problems[] = 'status is stale';
        }
        if (empty($data['initialized'])) {
            $problems[] = 'initial scan not completed';
        }
        if (!empty($data['scan_error'])) {
            $problems[] = 'scan error: ' . $data['scan_error'];
        }
        if (($data['oldest_age_s'] ?? 0) >= (int) $input->getOption('max-pending-age')) {
            $problems[] = sprintf('oldest queued path waits %.0fs%s', $data['oldest_age_s'], empty($data['full_index_busy']) ? '' : ' (full index running)');
        }
        foreach ($problems as $problem) {
            $output->writeln('<error>' . $problem . '</error>');
        }
        return $problems === [] ? self::SUCCESS : self::FAILURE;
    }
}

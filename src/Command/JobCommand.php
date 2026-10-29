<?php

declare(strict_types=1);

namespace StadtBraunschweig\IesResourceWatcher\Command;

use StadtBraunschweig\IesResourceWatcher\Index\IndexTarget;
use StadtBraunschweig\IesResourceWatcher\Queue\QueueStore;
use StadtBraunschweig\IesResourceWatcher\Runtime\JobLauncher;
use StadtBraunschweig\IesResourceWatcher\Runtime\JobRunner;
use StadtBraunschweig\IesResourceWatcher\Watcher\PathPolicy;
use StadtBraunschweig\IesResourceWatcher\Watcher\WatcherConfig;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** Started by the watcher for each batch; reads the request from stdin. */
#[AsCommand(name: JobLauncher::COMMAND, description: 'Internal: run one indexing job of the resource watcher', hidden: true)]
final class JobCommand extends Command
{
    /** The result is the protocol with the watcher: unformatted and written even with --quiet. */
    private const RESULT = OutputInterface::OUTPUT_RAW | OutputInterface::VERBOSITY_QUIET;

    public function __construct(
        private readonly WatcherConfig $config,
        private readonly IndexTarget $target,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $errors = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        try {
            $stream = ($input instanceof StreamableInputInterface ? $input->getStream() : null) ?? \STDIN;
            $request = json_decode((string) stream_get_contents($stream), true, 64, JSON_THROW_ON_ERROR);
            $runner = new JobRunner(
                $this->target,
                new QueueStore($this->config->queueFile(), $this->config->quietPeriod),
                new PathPolicy($this->target->layout(), $this->config->scopes, $this->config->prune),
            );
            $result = $runner->handle($request);
            $output->writeln(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), self::RESULT);
            return self::SUCCESS;
        } catch (\Throwable $error) {
            $errors->writeln(get_class($error) . ': ' . $error->getMessage(), OutputInterface::OUTPUT_RAW);
            if (getenv('IES_RESOURCE_WATCHER_DEBUG')) {
                $errors->writeln($error->getTraceAsString(), OutputInterface::OUTPUT_RAW);
            }
            $output->writeln(json_encode(['ok' => false, 'error' => $error->getMessage()], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), self::RESULT);
            return self::FAILURE;
        }
    }
}

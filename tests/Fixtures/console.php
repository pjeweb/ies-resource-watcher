<?php

declare(strict_types=1);

// Job console for WatcherTest: the real JobCommand with the fake target.

use StadtBraunschweig\IesResourceWatcher\Command\JobCommand;
use StadtBraunschweig\IesResourceWatcher\Tests\Fixtures\FakeTarget;
use StadtBraunschweig\IesResourceWatcher\Watcher\WatcherConfig;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArgvInput;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$fixture = json_decode((string) file_get_contents($argv[1]), true, 16, JSON_THROW_ON_ERROR);
$application = new Application();
$application->add(new JobCommand(WatcherConfig::fromArray($fixture['watcher']), new FakeTarget($fixture['root'], $fixture['control'])));
exit($application->run(new ArgvInput([$argv[0], ...array_slice($argv, 2)])));

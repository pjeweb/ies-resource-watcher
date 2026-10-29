<?php

declare(strict_types=1);

namespace StadtBraunschweig\IesResourceWatcher\Watcher;

/** The file is being replaced right now; its next event will be observed. */
final class FileChangedWhileReading extends \RuntimeException
{
    public function __construct(string $file)
    {
        parent::__construct('file changed while reading: ' . $file);
    }
}

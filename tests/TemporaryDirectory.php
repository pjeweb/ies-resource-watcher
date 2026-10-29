<?php

declare(strict_types=1);

namespace StadtBraunschweig\IesResourceWatcher\Tests;

trait TemporaryDirectory
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/irw-test-' . bin2hex(random_bytes(6));
        mkdir($this->tmp, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->remove($this->tmp);
    }

    private function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $name) {
            if ($name !== '.' && $name !== '..') {
                $this->remove($path . '/' . $name);
            }
        }
        rmdir($path);
    }

    private function write(string $file, string $content): void
    {
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0755, true);
        }
        file_put_contents($file, $content);
    }
}

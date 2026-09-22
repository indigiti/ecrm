<?php
declare(strict_types=1);

namespace Ecrm\Support;

use RuntimeException;

final class ExclusiveLock
{
    public function __construct(
        private string $root,
        private string $name
    ) {}

    public function run(callable $callback): mixed
    {
        if (!is_dir($this->root) && !mkdir($this->root, 0770, true) && !is_dir($this->root)) {
            throw new RuntimeException('Cannot create lock directory');
        }

        $safe = preg_replace('/[^a-zA-Z0-9_.-]+/', '-', $this->name) ?: 'lock';
        $handle = fopen(rtrim($this->root, '/') . '/' . $safe . '.lock', 'c+');
        if (!$handle || !flock($handle, LOCK_EX)) {
            throw new RuntimeException('Could not acquire exclusive lock');
        }

        try {
            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}

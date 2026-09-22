<?php
declare(strict_types=1);

namespace Ecrm\Support;

use RuntimeException;

final class Sequence
{
    public function __construct(private string $root) {}

    public function next(string $name, string $prefix, int $width = 6): string
    {
        if (!preg_match('/^[a-z][a-z0-9_-]*$/', $name)) {
            throw new RuntimeException('Invalid sequence name');
        }

        if (!is_dir($this->root) && !mkdir($this->root, 0770, true) && !is_dir($this->root)) {
            throw new RuntimeException('Cannot create sequence directory');
        }

        $lock = fopen($this->root . '/' . $name . '.lock', 'c+');
        if (!$lock || !flock($lock, LOCK_EX)) {
            throw new RuntimeException('Sequence lock failed');
        }

        try {
            $path = $this->root . '/' . $name . '.txt';
            $value = is_file($path) ? (int) trim((string) file_get_contents($path)) : 0;
            $value++;
            $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
            file_put_contents($tmp, (string) $value, LOCK_EX);
            rename($tmp, $path);
            return $prefix . str_pad((string) $value, $width, '0', STR_PAD_LEFT);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}

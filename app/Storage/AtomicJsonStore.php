<?php
declare(strict_types=1);

namespace Ecrm\Storage;

use RuntimeException;

final class AtomicJsonStore
{
    public function __construct(private string $root) {}

    public function put(string $collection, string $id, array $record): void
    {
        $this->assertId($id);
        $dir = $this->dir($collection);
        $this->ensure($dir);
        $lock = fopen($dir . '/.lock', 'c+');

        if (!$lock || !flock($lock, LOCK_EX)) {
            throw new RuntimeException('Storage lock failed');
        }

        try {
            $path = $dir . '/' . $id . '.json';
            $tmp = $path . '.' . bin2hex(random_bytes(5)) . '.tmp';
            $json = json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $handle = fopen($tmp, 'wb');

            if (!$handle) {
                throw new RuntimeException('Storage write failed');
            }

            try {
                if (fwrite($handle, $json . "\n") === false) {
                    throw new RuntimeException('Storage write failed');
                }
                fflush($handle);
                if (function_exists('fsync')) {
                    fsync($handle);
                }
            } finally {
                fclose($handle);
            }

            if (!rename($tmp, $path)) {
                @unlink($tmp);
                throw new RuntimeException('Atomic publish failed');
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function get(string $collection, string $id): ?array
    {
        $this->assertId($id);
        $path = $this->dir($collection) . '/' . $id . '.json';
        if (!is_file($path)) {
            return null;
        }

        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    public function all(string $collection): array
    {
        $dir = $this->dir($collection);
        if (!is_dir($dir)) {
            return [];
        }

        $out = [];
        foreach (glob($dir . '/*.json') ?: [] as $path) {
            $out[] = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        }

        usort($out, static fn(array $a, array $b): int => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));
        return $out;
    }

    public function where(string $collection, string $field, mixed $value): array
    {
        return array_values(array_filter(
            $this->all($collection),
            static fn(array $row): bool => ($row[$field] ?? null) === $value
        ));
    }

    private function dir(string $collection): string
    {
        if (!preg_match('/^[a-z][a-z0-9_-]*$/', $collection)) {
            throw new RuntimeException('Invalid collection');
        }

        return rtrim($this->root, '/') . '/' . $collection;
    }

    private function assertId(string $id): void
    {
        if (!preg_match('/^[A-Za-z0-9._-]+$/', $id) || str_contains($id, '..')) {
            throw new RuntimeException('Invalid record ID');
        }
    }

    private function ensure(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create storage');
        }
    }
}

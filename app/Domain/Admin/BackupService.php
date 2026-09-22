<?php
declare(strict_types=1);

namespace Ecrm\Domain\Admin;

use Ecrm\Audit\AuditLedger;
use RuntimeException;
use InvalidArgumentException;

final class BackupService
{
    private const SOURCES = ['data','indexes','uploads','audit','users','config','jobs'];

    public function __construct(
        private string $privateRoot,
        private AuditLedger $audit
    ) {}

    public function create(): array
    {
        $backupRoot = $this->backupRoot();
        $id = gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4));
        $tmp = $backupRoot . '/.tmp-' . $id;
        $target = $backupRoot . '/' . $id;

        if (!mkdir($tmp, 0770, true) && !is_dir($tmp)) {
            throw new RuntimeException('Cannot create backup staging directory');
        }

        $manifest = [
            'id' => $id,
            'created_at' => gmdate(DATE_ATOM),
            'sources' => self::SOURCES,
            'files' => [],
        ];

        try {
            foreach (self::SOURCES as $source) {
                $sourcePath = rtrim($this->privateRoot, '/') . '/' . $source;
                if (!is_dir($sourcePath)) continue;
                $this->copyDirectory($sourcePath, $tmp . '/' . $source, $source, $manifest['files']);
            }

            ksort($manifest['files']);
            $manifest['file_count'] = count($manifest['files']);
            $manifest['total_bytes'] = array_sum(array_column($manifest['files'], 'size_bytes'));

            file_put_contents(
                $tmp . '/MANIFEST.json',
                json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
                LOCK_EX
            );

            if (!rename($tmp, $target)) {
                throw new RuntimeException('Could not publish backup snapshot');
            }

            $this->audit->append('backup.created', 'backup', $id, [
                'file_count' => $manifest['file_count'],
                'total_bytes' => $manifest['total_bytes'],
            ]);

            return $this->summary($manifest);
        } catch (\Throwable $e) {
            $this->removeTree($tmp);
            throw $e;
        }
    }

    public function all(): array
    {
        $root = $this->backupRoot();
        $rows = [];
        foreach (scandir($root) ?: [] as $name) {
            if ($name === '.' || $name === '..' || str_starts_with($name, '.tmp-')) continue;
            $manifest = $this->manifest($name, false);
            if ($manifest !== null) $rows[] = $this->summary($manifest);
        }

        usort($rows, static fn(array $a, array $b): int =>
            strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? ''))
        );
        return $rows;
    }

    public function verify(string $id): array
    {
        $manifest = $this->manifest($id, true);
        $snapshot = $this->snapshotPath($id);
        $errors = [];
        $checked = 0;
        $bytes = 0;

        foreach (($manifest['files'] ?? []) as $relative => $expected) {
            $path = $snapshot . '/' . $relative;
            if (!is_file($path)) {
                $errors[] = $relative . ': missing';
                continue;
            }

            $realSnapshot = realpath($snapshot);
            $realPath = realpath($path);
            if ($realSnapshot === false || $realPath === false
                || !str_starts_with($realPath, $realSnapshot . DIRECTORY_SEPARATOR)) {
                $errors[] = $relative . ': invalid path';
                continue;
            }

            $size = filesize($realPath);
            if ($size === false || (int) $size !== (int) ($expected['size_bytes'] ?? -1)) {
                $errors[] = $relative . ': size mismatch';
                continue;
            }

            $hash = hash_file('sha256', $realPath);
            if (!is_string($hash) || !hash_equals((string) ($expected['sha256'] ?? ''), $hash)) {
                $errors[] = $relative . ': checksum mismatch';
                continue;
            }

            $checked++;
            $bytes += (int) $size;
        }

        $result = [
            'id' => $id,
            'ok' => $errors === [],
            'checked_files' => $checked,
            'checked_bytes' => $bytes,
            'errors' => $errors,
            'verified_at' => gmdate(DATE_ATOM),
        ];

        $this->audit->append('backup.verified', 'backup', $id, [
            'ok' => $result['ok'],
            'checked_files' => $checked,
            'error_count' => count($errors),
        ]);

        return $result;
    }

    private function copyDirectory(string $source, string $destination, string $prefix, array &$files): void
    {
        if (!is_dir($destination) && !mkdir($destination, 0770, true) && !is_dir($destination)) {
            throw new RuntimeException('Cannot create backup directory');
        }

        foreach (scandir($source) ?: [] as $name) {
            if ($name === '.' || $name === '..') continue;
            $from = $source . '/' . $name;
            $relative = $prefix . '/' . $name;
            $to = $destination . '/' . $name;

            if (is_link($from)) {
                throw new RuntimeException('Backup source contains a symbolic link: ' . $relative);
            }

            if (is_dir($from)) {
                $this->copyDirectory($from, $to, $relative, $files);
                continue;
            }

            if (!is_file($from) || !copy($from, $to)) {
                throw new RuntimeException('Could not copy backup file: ' . $relative);
            }

            $size = filesize($to);
            $hash = hash_file('sha256', $to);
            if ($size === false || !is_string($hash)) {
                throw new RuntimeException('Could not hash backup file: ' . $relative);
            }

            $files[$relative] = [
                'size_bytes' => (int) $size,
                'sha256' => $hash,
            ];
        }
    }

    private function manifest(string $id, bool $required): ?array
    {
        $path = $this->snapshotPath($id) . '/MANIFEST.json';
        if (!is_file($path)) {
            if ($required) throw new InvalidArgumentException('Backup snapshot not found');
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded) || ($decoded['id'] ?? null) !== $id || !is_array($decoded['files'] ?? null)) {
            if ($required) throw new RuntimeException('Backup manifest is invalid');
            return null;
        }
        return $decoded;
    }

    private function snapshotPath(string $id): string
    {
        if (!preg_match('/^[0-9]{8}-[0-9]{6}-[a-f0-9]{8}$/', $id)) {
            throw new InvalidArgumentException('Invalid backup ID');
        }
        return $this->backupRoot() . '/' . $id;
    }

    private function backupRoot(): string
    {
        $root = rtrim($this->privateRoot, '/') . '/backups';
        if (!is_dir($root) && !mkdir($root, 0770, true) && !is_dir($root)) {
            throw new RuntimeException('Cannot create backups directory');
        }
        return $root;
    }

    private function summary(array $manifest): array
    {
        return [
            'id' => $manifest['id'],
            'created_at' => $manifest['created_at'] ?? null,
            'file_count' => (int) ($manifest['file_count'] ?? count($manifest['files'] ?? [])),
            'total_bytes' => (int) ($manifest['total_bytes'] ?? array_sum(array_column($manifest['files'] ?? [], 'size_bytes'))),
        ];
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..') continue;
            $path = $dir . '/' . $name;
            is_dir($path) && !is_link($path) ? $this->removeTree($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}

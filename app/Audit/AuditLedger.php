<?php
declare(strict_types=1);

namespace Ecrm\Audit;

use Ecrm\Support\UuidV7;
use RuntimeException;

final class AuditLedger
{
    public function __construct(private string $root) {}

    public function append(string $action, string $entityType, string $entityId, array $context = []): void
    {
        if (!is_dir($this->root) && !mkdir($this->root, 0770, true) && !is_dir($this->root)) {
            throw new RuntimeException('Cannot create audit directory');
        }

        $path = $this->root . '/events.jsonl';
        $lock = fopen($this->root . '/.lock', 'c+');
        if (!$lock || !flock($lock, LOCK_EX)) {
            throw new RuntimeException('Audit lock failed');
        }

        try {
            $previousHash = '';
            if (is_file($path) && filesize($path) > 0) {
                $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                $last = $lines ? json_decode((string) end($lines), true) : null;
                $previousHash = (string) ($last['hash'] ?? '');
            }

            $event = [
                'id' => UuidV7::generate(),
                'at' => gmdate(DATE_ATOM),
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'context' => $context,
                'previous_hash' => $previousHash,
            ];
            $event['hash'] = hash('sha256', json_encode($event, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            file_put_contents($path, json_encode($event, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}

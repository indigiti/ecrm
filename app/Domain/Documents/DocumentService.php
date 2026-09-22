<?php
declare(strict_types=1);

namespace Ecrm\Domain\Documents;

use Ecrm\Audit\AuditLedger;
use Ecrm\Search\SearchIndex;
use Ecrm\Storage\AtomicJsonStore;
use Ecrm\Support\UuidV7;
use InvalidArgumentException;
use RuntimeException;

final class DocumentService
{
    private const MAX_BYTES = 25_000_000;

    private const ALLOWED_MIME = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'text/plain' => 'txt',
        'text/csv' => 'csv',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/msword' => 'doc',
    ];

    public function __construct(
        private AtomicJsonStore $store,
        private SearchIndex $search,
        private AuditLedger $audit,
        private string $uploadsRoot
    ) {}

    public function upload(array $file, array $input = []): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException('Document upload failed');
        }

        $tmp = (string) ($file['tmp_name'] ?? '');
        $size = (int) ($file['size'] ?? 0);
        if ($tmp === '' || !is_file($tmp)) throw new InvalidArgumentException('Uploaded document is missing');
        if ($size <= 0 || $size > self::MAX_BYTES) throw new InvalidArgumentException('Document must be between 1 byte and 25 MB');

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($tmp) ?: '';
        $extension = self::ALLOWED_MIME[$mime] ?? null;
        if ($extension === null) throw new InvalidArgumentException('Document type is not allowed');

        $entityType = strtolower(trim((string) ($input['entity_type'] ?? 'general')));
        $entityId = trim((string) ($input['entity_id'] ?? ''));
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $entityType)) throw new InvalidArgumentException('Invalid document entity type');

        $id = UuidV7::generate();
        $datePath = gmdate('Y/m');
        $directory = rtrim($this->uploadsRoot, '/') . '/' . $datePath;
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new RuntimeException('Cannot create document storage directory');
        }

        $relative = $datePath . '/' . $id . '.' . $extension;
        $destination = rtrim($this->uploadsRoot, '/') . '/' . $relative;
        if (!move_uploaded_file($tmp, $destination) && !rename($tmp, $destination)) {
            throw new RuntimeException('Could not store uploaded document');
        }
        @chmod($destination, 0660);

        $original = $this->safeName((string) ($file['name'] ?? ('document.' . $extension)));
        $title = trim((string) ($input['title'] ?? '')) ?: pathinfo($original, PATHINFO_FILENAME);
        $now = gmdate(DATE_ATOM);

        $record = [
            'id' => $id,
            'title' => $title,
            'original_name' => $original,
            'mime_type' => $mime,
            'extension' => $extension,
            'size_bytes' => filesize($destination) ?: $size,
            'sha256' => hash_file('sha256', $destination),
            'relative_path' => $relative,
            'entity_type' => $entityType,
            'entity_id' => $entityId !== '' ? $entityId : null,
            'category' => trim((string) ($input['category'] ?? 'general')) ?: 'general',
            'notes' => trim((string) ($input['notes'] ?? '')),
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $this->store->put('documents', $id, $record);
        $this->index($record);
        $this->audit->append('document.uploaded', 'document', $id, [
            'entity_type' => $entityType,
            'entity_id' => $record['entity_id'],
            'mime_type' => $mime,
            'size_bytes' => $record['size_bytes'],
            'sha256' => $record['sha256'],
        ]);
        return $this->public($record);
    }

    public function archive(string $id, string $reason): array
    {
        $record = $this->raw($id);
        if ($record['status'] === 'archived') return $this->public($record);
        $reason = trim($reason);
        if ($reason === '') throw new InvalidArgumentException('Archive reason is required');

        $record['status'] = 'archived';
        $record['archive_reason'] = $reason;
        $record['archived_at'] = gmdate(DATE_ATOM);
        $record['updated_at'] = gmdate(DATE_ATOM);
        $this->store->put('documents', $id, $record);
        $this->index($record);
        $this->audit->append('document.archived', 'document', $id, ['reason' => $reason]);
        return $this->public($record);
    }

    public function get(string $id): array
    {
        return $this->public($this->raw($id));
    }

    public function all(?string $entityType = null, ?string $entityId = null): array
    {
        return array_values(array_map(
            fn(array $row): array => $this->public($row),
            array_filter($this->store->all('documents'), static function(array $row) use ($entityType, $entityId): bool {
                if ($entityType !== null && ($row['entity_type'] ?? null) !== $entityType) return false;
                if ($entityId !== null && ($row['entity_id'] ?? null) !== $entityId) return false;
                return true;
            })
        ));
    }

    public function file(string $id): array
    {
        $record = $this->raw($id);
        $path = rtrim($this->uploadsRoot, '/') . '/' . ltrim((string) $record['relative_path'], '/');
        if (!is_file($path)) throw new RuntimeException('Document file is missing');

        $realRoot = realpath($this->uploadsRoot);
        $realPath = realpath($path);
        if ($realRoot === false || $realPath === false || !str_starts_with($realPath, $realRoot . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Invalid document storage path');
        }

        return ['record' => $this->public($record), 'path' => $realPath];
    }

    private function raw(string $id): array
    {
        $record = $this->store->get('documents', $id);
        if (!$record) throw new InvalidArgumentException('Document not found');
        return $record;
    }

    private function safeName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[^a-zA-Z0-9._() -]+/', '_', $name) ?: 'document';
        return substr($name, 0, 180);
    }

    private function public(array $record): array
    {
        unset($record['relative_path']);
        return $record;
    }

    private function index(array $record): void
    {
        $this->search->upsert('document', $record['id'], $record['title'], [
            $record['original_name'],
            $record['entity_type'],
            $record['entity_id'] ?? '',
            $record['category'],
            $record['notes'],
            $record['status'],
        ], [
            'entity_type' => $record['entity_type'],
            'entity_id' => $record['entity_id'],
            'category' => $record['category'],
            'status' => $record['status'],
        ]);
    }
}

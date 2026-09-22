<?php
declare(strict_types=1);

namespace Ecrm\Search;

use RuntimeException;

final class SearchIndex
{
    public function __construct(private string $root) {}

    public function upsert(string $type, string $id, string $label, array $terms = [], array $meta = []): void
    {
        $this->ensure();
        $lock = fopen($this->root . '/.lock', 'c+');
        if (!$lock || !flock($lock, LOCK_EX)) {
            throw new RuntimeException('Search index lock failed');
        }

        try {
            $path = $this->root . '/global.json';
            $rows = is_file($path)
                ? (json_decode((string) file_get_contents($path), true) ?: [])
                : [];

            $key = $type . ':' . $id;
            $rows[$key] = [
                'type' => $type,
                'id' => $id,
                'label' => $label,
                'terms' => $this->normalize(implode(' ', array_filter(array_merge([$label], $terms), 'is_scalar'))),
                'meta' => $meta,
                'updated_at' => gmdate(DATE_ATOM),
            ];

            $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
            file_put_contents($tmp, json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            rename($tmp, $path);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function search(string $query, int $limit = 20): array
    {
        $query = $this->normalize($query);
        if ($query === '') {
            return [];
        }

        $path = $this->root . '/global.json';
        if (!is_file($path)) {
            return [];
        }

        $rows = json_decode((string) file_get_contents($path), true) ?: [];
        $needles = array_values(array_filter(explode(' ', $query)));
        $matches = [];

        foreach ($rows as $row) {
            $haystack = (string) ($row['terms'] ?? '');
            $score = 0;
            foreach ($needles as $needle) {
                if (str_contains($haystack, $needle)) {
                    $score += str_starts_with($this->normalize((string) ($row['label'] ?? '')), $needle) ? 4 : 1;
                }
            }
            if ($score > 0) {
                $row['score'] = $score;
                $matches[] = $row;
            }
        }

        usort($matches, static fn(array $a, array $b): int => ($b['score'] <=> $a['score']) ?: strcmp((string) $a['label'], (string) $b['label']));
        return array_slice($matches, 0, max(1, min($limit, 50)));
    }

    private function normalize(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9@.+-]+/i', ' ', $value) ?? '';
        return trim(preg_replace('/\s+/', ' ', $value) ?? '');
    }

    private function ensure(): void
    {
        if (!is_dir($this->root) && !mkdir($this->root, 0770, true) && !is_dir($this->root)) {
            throw new RuntimeException('Cannot create index directory');
        }
    }
}

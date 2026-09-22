<?php
declare(strict_types=1);

namespace Ecrm\Domain\Inventory;

use Ecrm\Audit\AuditLedger;
use Ecrm\Search\SearchIndex;
use Ecrm\Storage\AtomicJsonStore;
use Ecrm\Support\Sequence;
use Ecrm\Support\UuidV7;
use InvalidArgumentException;

final class LocationService
{
    public const TYPES = [
        'showroom','warehouse','zone','rack','workshop_bay',
        'vehicle','customer_site','temporary','transit','other'
    ];

    public function __construct(
        private AtomicJsonStore $store,
        private Sequence $sequence,
        private SearchIndex $search,
        private AuditLedger $audit
    ) {}

    public function create(array $input): array
    {
        $parentId = $this->normalizeParent($input['parent_id'] ?? null);
        $record = [
            'id' => UuidV7::generate(),
            'code' => $this->sequence->next('locations', 'LOC-'),
            'name' => trim((string) ($input['name'] ?? '')),
            'type' => strtolower(trim((string) ($input['type'] ?? 'warehouse'))),
            'parent_id' => $parentId,
            'address' => trim((string) ($input['address'] ?? '')),
            'latitude' => $this->coordinate($input['latitude'] ?? null, -90, 90, 'latitude'),
            'longitude' => $this->coordinate($input['longitude'] ?? null, -180, 180, 'longitude'),
            'contact_name' => trim((string) ($input['contact_name'] ?? '')),
            'contact_mobile' => trim((string) ($input['contact_mobile'] ?? '')),
            'status' => 'active',
            'created_at' => gmdate(DATE_ATOM),
            'updated_at' => gmdate(DATE_ATOM),
        ];

        $this->validate($record);
        $this->store->put('locations', $record['id'], $record);
        $this->index($record);
        $this->audit->append('location.created', 'location', $record['id'], ['code' => $record['code']]);
        return $record;
    }

    public function update(string $id, array $input): array
    {
        $record = $this->get($id);

        foreach (['name','address','contact_name','contact_mobile'] as $field) {
            if (array_key_exists($field, $input)) $record[$field] = trim((string) $input[$field]);
        }
        if (array_key_exists('type', $input)) $record['type'] = strtolower(trim((string) $input['type']));
        if (array_key_exists('status', $input)) $record['status'] = strtolower(trim((string) $input['status']));
        if (array_key_exists('parent_id', $input)) {
            $parentId = $this->normalizeParent($input['parent_id']);
            if ($parentId === $id) throw new InvalidArgumentException('Location cannot be its own parent');
            $this->assertNoCycle($id, $parentId);
            $record['parent_id'] = $parentId;
        }
        if (array_key_exists('latitude', $input)) {
            $record['latitude'] = $this->coordinate($input['latitude'], -90, 90, 'latitude');
        }
        if (array_key_exists('longitude', $input)) {
            $record['longitude'] = $this->coordinate($input['longitude'], -180, 180, 'longitude');
        }

        $this->validate($record);
        $record['updated_at'] = gmdate(DATE_ATOM);
        $this->store->put('locations', $id, $record);
        $this->index($record);
        $this->audit->append('location.updated', 'location', $id);
        return $record;
    }

    public function get(string $id): array
    {
        $record = $this->store->get('locations', $id);
        if (!$record) throw new InvalidArgumentException('Location not found');
        return $record;
    }

    public function all(): array
    {
        return $this->store->all('locations');
    }

    public function tree(): array
    {
        $rows = $this->all();
        $byParent = [];
        foreach ($rows as $row) {
            $key = $row['parent_id'] ?: '__root__';
            $byParent[$key][] = $row;
        }

        $build = function(string $parentKey) use (&$build, $byParent): array {
            $nodes = [];
            foreach ($byParent[$parentKey] ?? [] as $row) {
                $row['children'] = $build($row['id']);
                $nodes[] = $row;
            }
            usort($nodes, static fn(array $a, array $b): int => strcmp($a['name'], $b['name']));
            return $nodes;
        };

        return $build('__root__');
    }

    private function normalizeParent(mixed $id): ?string
    {
        $id = trim((string) ($id ?? ''));
        if ($id === '') return null;
        if (!$this->store->get('locations', $id)) throw new InvalidArgumentException('Parent location not found');
        return $id;
    }

    private function assertNoCycle(string $id, ?string $parentId): void
    {
        $seen = [$id => true];
        $current = $parentId;

        while ($current !== null) {
            if (isset($seen[$current])) throw new InvalidArgumentException('Location hierarchy cycle detected');
            $seen[$current] = true;
            $parent = $this->store->get('locations', $current);
            $current = $parent['parent_id'] ?? null;
        }
    }

    private function coordinate(mixed $value, float $min, float $max, string $name): ?float
    {
        if ($value === null || trim((string) $value) === '') return null;
        if (!is_numeric($value)) throw new InvalidArgumentException('Invalid ' . $name);
        $number = (float) $value;
        if ($number < $min || $number > $max) throw new InvalidArgumentException('Invalid ' . $name);
        return $number;
    }

    private function validate(array $record): void
    {
        if (trim((string) ($record['name'] ?? '')) === '') throw new InvalidArgumentException('Location name is required');
        if (!in_array($record['type'] ?? '', self::TYPES, true)) throw new InvalidArgumentException('Invalid location type');
        if (!in_array($record['status'] ?? '', ['active','inactive','archived'], true)) {
            throw new InvalidArgumentException('Invalid location status');
        }
        if (($record['latitude'] === null) !== ($record['longitude'] === null)) {
            throw new InvalidArgumentException('Latitude and longitude must both be supplied');
        }
    }

    private function index(array $record): void
    {
        $this->search->upsert('location', $record['id'], $record['name'], [
            $record['code'], $record['type'], $record['address'],
            $record['contact_name'], $record['contact_mobile'], $record['status']
        ], [
            'code' => $record['code'],
            'type' => $record['type'],
            'parent_id' => $record['parent_id'],
            'status' => $record['status'],
        ]);
    }
}

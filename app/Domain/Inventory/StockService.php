<?php
declare(strict_types=1);

namespace Ecrm\Domain\Inventory;

use Ecrm\Storage\AtomicJsonStore;
use InvalidArgumentException;

final class StockService
{
    public function __construct(private AtomicJsonStore $store) {}

    public function balanceMilli(string $productId, string $locationId): int
    {
        $balance = 0;
        foreach ($this->store->all('inventory_movements') as $row) {
            if (($row['product_id'] ?? null) !== $productId) continue;
            $qty = (int) ($row['quantity_milli'] ?? 0);
            if (($row['to_location_id'] ?? null) === $locationId) $balance += $qty;
            if (($row['from_location_id'] ?? null) === $locationId) $balance -= $qty;
        }
        return $balance;
    }

    public function product(string $productId): array
    {
        $product = $this->store->get('products', $productId);
        if (!$product) throw new InvalidArgumentException('Product not found');

        $locations = [];
        foreach ($this->store->all('locations') as $location) {
            $milli = $this->balanceMilli($productId, (string) $location['id']);
            if ($milli === 0) continue;
            $locations[] = [
                'location_id' => $location['id'],
                'location_code' => $location['code'] ?? '',
                'location_name' => $location['name'] ?? '',
                'quantity_milli' => $milli,
                'quantity' => $this->fromMilli($milli),
            ];
        }

        usort($locations, static fn(array $a, array $b): int =>
            strcmp((string) $a['location_name'], (string) $b['location_name'])
        );

        return [
            'product_id' => $productId,
            'product_code' => $product['code'] ?? '',
            'product_name' => $product['name'] ?? '',
            'total_quantity_milli' => array_sum(array_column($locations, 'quantity_milli')),
            'total_quantity' => $this->fromMilli(array_sum(array_column($locations, 'quantity_milli'))),
            'locations' => $locations,
        ];
    }

    public function all(): array
    {
        $rows = [];
        foreach ($this->store->all('products') as $product) {
            $rows[] = $this->product((string) $product['id']);
        }
        usort($rows, static fn(array $a, array $b): int =>
            strcmp((string) $a['product_name'], (string) $b['product_name'])
        );
        return $rows;
    }

    public function location(string $locationId): array
    {
        $location = $this->store->get('locations', $locationId);
        if (!$location) throw new InvalidArgumentException('Location not found');

        $rows = [];
        foreach ($this->store->all('products') as $product) {
            $milli = $this->balanceMilli((string) $product['id'], $locationId);
            if ($milli === 0) continue;
            $rows[] = [
                'product_id' => $product['id'],
                'product_code' => $product['code'] ?? '',
                'product_name' => $product['name'] ?? '',
                'quantity_milli' => $milli,
                'quantity' => $this->fromMilli($milli),
            ];
        }

        usort($rows, static fn(array $a, array $b): int =>
            strcmp((string) $a['product_name'], (string) $b['product_name'])
        );

        return [
            'location' => $location,
            'stock' => $rows,
        ];
    }

    public function unitPosition(string $unitId): array
    {
        $unit = $this->store->get('product_units', $unitId);
        if (!$unit) throw new InvalidArgumentException('Serialized product unit not found');

        $locationId = null;
        $latest = null;
        $history = array_values(array_filter(
            $this->store->all('inventory_movements'),
            static fn(array $row): bool => ($row['product_unit_id'] ?? null) === $unitId
        ));

        usort($history, static fn(array $a, array $b): int =>
            strcmp((string) ($a['created_at'] ?? ''), (string) ($b['created_at'] ?? ''))
            ?: strcmp((string) ($a['id'] ?? ''), (string) ($b['id'] ?? ''))
        );

        foreach ($history as $row) {
            $locationId = $row['to_location_id'] ?? null;
            $latest = $row;
        }

        $location = $locationId ? $this->store->get('locations', (string) $locationId) : null;

        return [
            'unit' => $unit,
            'location_id' => $locationId,
            'location' => $location,
            'operational_status' => $this->unitStatus($latest),
            'latest_movement' => $latest,
            'history' => array_reverse($history),
        ];
    }

    private function unitStatus(?array $movement): string
    {
        if ($movement === null) return 'unreceived';
        $type = (string) ($movement['type'] ?? '');

        return match ($type) {
            'sale' => 'sold',
            'issue' => 'issued',
            'damaged' => 'damaged',
            'lost' => 'lost',
            'scrap' => 'scrapped',
            'workshop_in' => 'in_workshop',
            'workshop_out', 'receive', 'transfer', 'return', 'customer_return', 'adjustment', 'reversal' => 'in_stock',
            default => 'unknown',
        };
    }

    private function fromMilli(int $milli): float
    {
        return round($milli / 1000, 3);
    }
}

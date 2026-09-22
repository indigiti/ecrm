<?php
declare(strict_types=1);

namespace Ecrm\Domain\Inventory;

use Ecrm\Audit\AuditLedger;
use Ecrm\Storage\AtomicJsonStore;
use Ecrm\Support\ExclusiveLock;
use Ecrm\Support\UuidV7;
use InvalidArgumentException;

final class MovementService
{
    public const TYPES = [
        'receive','transfer','issue','return','sale','customer_return',
        'workshop_in','workshop_out','adjustment','damaged','lost','scrap'
    ];

    public function __construct(
        private AtomicJsonStore $store,
        private AuditLedger $audit,
        private string $lockRoot
    ) {}

    public function record(array $input): array
    {
        return $this->withLock(fn(): array => $this->recordUnlocked($input));
    }

    public function reverse(string $movementId, string $reason): array
    {
        return $this->withLock(function () use ($movementId, $reason): array {
            $original = $this->store->get('inventory_movements', $movementId);
            if (!$original || ($original['type'] ?? '') === 'reversal') {
                throw new InvalidArgumentException('Inventory movement not found');
            }

            foreach ($this->store->all('inventory_movements') as $row) {
                if (($row['reversal_of'] ?? null) === $movementId) {
                    throw new InvalidArgumentException('Inventory movement is already reversed');
                }
            }

            $reason = trim($reason);
            if ($reason === '') throw new InvalidArgumentException('Reversal reason is required');

            $from = $original['to_location_id'] ?? null;
            $to = $original['from_location_id'] ?? null;
            $quantityMilli = (int) ($original['quantity_milli'] ?? 0);

            $unitId = $original['product_unit_id'] ?? null;
            if ($unitId) {
                $position = (new StockService($this->store))->unitPosition((string) $unitId);
                $currentLocation = $position['location_id'] ?? null;
                if ($currentLocation !== $from) {
                    throw new InvalidArgumentException('Serialized unit moved after original movement; reverse later movements first');
                }
            }

            if ($from !== null) {
                $available = $this->balanceMilli((string) $original['product_id'], (string) $from);
                if ($available < $quantityMilli) {
                    throw new InvalidArgumentException('Insufficient stock to reverse movement');
                }
            }

            $record = [
                'id' => UuidV7::generate(),
                'product_id' => $original['product_id'],
                'product_unit_id' => $original['product_unit_id'] ?? null,
                'from_location_id' => $from,
                'to_location_id' => $to,
                'quantity_milli' => $quantityMilli,
                'quantity' => $this->fromMilli($quantityMilli),
                'type' => 'reversal',
                'reference_type' => 'inventory_movement',
                'reference_id' => $movementId,
                'reversal_of' => $movementId,
                'reason' => $reason,
                'notes' => '',
                'created_at' => gmdate(DATE_ATOM),
            ];

            $this->store->put('inventory_movements', $record['id'], $record);
            $this->audit->append('inventory.movement_reversed', 'inventory_movement', $record['id'], [
                'reversal_of' => $movementId,
                'reason' => $reason,
            ]);
            return $record;
        });
    }

    public function history(?string $productId = null, ?string $locationId = null): array
    {
        return array_values(array_filter(
            $this->store->all('inventory_movements'),
            static function(array $row) use ($productId, $locationId): bool {
                if ($productId !== null && ($row['product_id'] ?? null) !== $productId) return false;
                if ($locationId !== null
                    && ($row['from_location_id'] ?? null) !== $locationId
                    && ($row['to_location_id'] ?? null) !== $locationId) return false;
                return true;
            }
        ));
    }

    private function recordUnlocked(array $input): array
    {
        $productId = trim((string) ($input['product_id'] ?? ''));
        $product = $productId !== '' ? $this->store->get('products', $productId) : null;
        if (!$product) throw new InvalidArgumentException('Product not found');
        if (($product['status'] ?? '') !== 'active') throw new InvalidArgumentException('Product is not active');

        $type = strtolower(trim((string) ($input['type'] ?? 'transfer')));
        if (!in_array($type, self::TYPES, true)) throw new InvalidArgumentException('Invalid inventory movement type');

        $quantityMilli = $this->toMilli($input['quantity'] ?? null);
        if ($quantityMilli <= 0) throw new InvalidArgumentException('Quantity must be greater than zero');

        $from = $this->location($input['from_location_id'] ?? null, 'source');
        $to = $this->location($input['to_location_id'] ?? null, 'destination');

        [$from, $to] = $this->normalizeEndpoints($type, $from, $to, $input);

        if ($from !== null && $to !== null && $from === $to) {
            throw new InvalidArgumentException('Source and destination locations must be different');
        }

        $unitId = trim((string) ($input['product_unit_id'] ?? ''));
        $serialized = (bool) ($product['serial_tracking'] ?? false);

        if ($serialized && $unitId === '') {
            throw new InvalidArgumentException('Serialized product movement requires a product unit');
        }
        if (!$serialized && $unitId !== '') {
            throw new InvalidArgumentException('Product is not configured for serialized tracking');
        }

        if ($unitId !== '') {
            $unit = $this->store->get('product_units', $unitId);
            if (!$unit) throw new InvalidArgumentException('Serialized product unit not found');
            if (($unit['product_id'] ?? null) !== $productId) {
                throw new InvalidArgumentException('Serialized unit does not belong to product');
            }
            if (($unit['lifecycle_status'] ?? '') !== 'active') {
                throw new InvalidArgumentException('Serialized unit is not active');
            }
            if ($quantityMilli !== 1000) {
                throw new InvalidArgumentException('Serialized unit movement quantity must be 1');
            }

            $position = (new StockService($this->store))->unitPosition($unitId);
            $currentLocation = $position['location_id'] ?? null;

            if ($from !== null && $currentLocation !== $from) {
                throw new InvalidArgumentException('Serialized unit is not at source location');
            }
            if ($from === null && $currentLocation !== null) {
                throw new InvalidArgumentException('Serialized unit is already in stock');
            }
        }

        if ($from !== null) {
            $available = $this->balanceMilli($productId, $from);
            if ($available < $quantityMilli) {
                throw new InvalidArgumentException('Insufficient stock at source location');
            }
        }

        $record = [
            'id' => UuidV7::generate(),
            'product_id' => $productId,
            'product_unit_id' => $unitId !== '' ? $unitId : null,
            'from_location_id' => $from,
            'to_location_id' => $to,
            'quantity_milli' => $quantityMilli,
            'quantity' => $this->fromMilli($quantityMilli),
            'type' => $type,
            'reference_type' => trim((string) ($input['reference_type'] ?? '')) ?: null,
            'reference_id' => trim((string) ($input['reference_id'] ?? '')) ?: null,
            'reversal_of' => null,
            'reason' => trim((string) ($input['reason'] ?? '')) ?: null,
            'notes' => trim((string) ($input['notes'] ?? '')),
            'created_at' => gmdate(DATE_ATOM),
        ];

        $this->store->put('inventory_movements', $record['id'], $record);
        $this->audit->append('inventory.movement_created', 'inventory_movement', $record['id'], [
            'type' => $type,
            'product_id' => $productId,
            'quantity_milli' => $quantityMilli,
            'from_location_id' => $from,
            'to_location_id' => $to,
        ]);
        return $record;
    }

    private function normalizeEndpoints(string $type, ?string $from, ?string $to, array $input): array
    {
        if ($type === 'receive' || $type === 'customer_return') {
            if ($to === null) throw new InvalidArgumentException('Destination location is required');
            if ($from !== null) throw new InvalidArgumentException('Receive movement cannot have a source location');
            return [$from, $to];
        }

        if (in_array($type, ['issue','sale','damaged','lost','scrap'], true)) {
            if ($from === null) throw new InvalidArgumentException('Source location is required');
            if ($to !== null) throw new InvalidArgumentException('Issue movement cannot have a destination location');
            return [$from, $to];
        }

        if ($type === 'adjustment') {
            $direction = strtolower(trim((string) ($input['adjustment_direction'] ?? '')));
            if ($direction === 'increase') {
                if ($to === null) throw new InvalidArgumentException('Destination location is required for stock increase');
                return [null, $to];
            }
            if ($direction === 'decrease') {
                if ($from === null) throw new InvalidArgumentException('Source location is required for stock decrease');
                return [$from, null];
            }
            throw new InvalidArgumentException('Adjustment direction must be increase or decrease');
        }

        if ($from === null || $to === null) {
            throw new InvalidArgumentException('Source and destination locations are required');
        }

        return [$from, $to];
    }

    private function location(mixed $id, string $role): ?string
    {
        $id = trim((string) ($id ?? ''));
        if ($id === '') return null;
        $location = $this->store->get('locations', $id);
        if (!$location) throw new InvalidArgumentException(ucfirst($role) . ' location not found');
        if (($location['status'] ?? '') !== 'active') {
            throw new InvalidArgumentException(ucfirst($role) . ' location is not active');
        }
        return $id;
    }

    private function balanceMilli(string $productId, string $locationId): int
    {
        $balance = 0;
        foreach ($this->store->all('inventory_movements') as $row) {
            if (($row['product_id'] ?? null) !== $productId) continue;
            $quantity = (int) ($row['quantity_milli'] ?? 0);
            if (($row['to_location_id'] ?? null) === $locationId) $balance += $quantity;
            if (($row['from_location_id'] ?? null) === $locationId) $balance -= $quantity;
        }
        return $balance;
    }

    private function toMilli(mixed $quantity): int
    {
        if (!is_numeric($quantity)) throw new InvalidArgumentException('Invalid quantity');
        $value = (float) $quantity;
        if ($value > 999999999) throw new InvalidArgumentException('Quantity is too large');
        return (int) round($value * 1000, 0, PHP_ROUND_HALF_UP);
    }

    private function fromMilli(int $quantityMilli): float
    {
        return round($quantityMilli / 1000, 3);
    }

    private function withLock(callable $callback): mixed
    {
        return (new ExclusiveLock($this->lockRoot, 'inventory-ledger'))->run($callback);
    }
}

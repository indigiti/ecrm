<?php
declare(strict_types=1);

namespace Ecrm\Domain\Inventory;

use Ecrm\Audit\AuditLedger;
use Ecrm\Search\SearchIndex;
use Ecrm\Storage\AtomicJsonStore;
use Ecrm\Support\ExclusiveLock;
use Ecrm\Support\UuidV7;
use InvalidArgumentException;

final class ProductUnitService
{
    public function __construct(
        private AtomicJsonStore $store,
        private SearchIndex $search,
        private AuditLedger $audit,
        private string $lockRoot
    ) {}

    public function create(array $input): array
    {
        return (new ExclusiveLock($this->lockRoot, 'inventory-ledger'))->run(function () use ($input): array {
            $productId = trim((string) ($input['product_id'] ?? ''));
            $product = $productId !== '' ? $this->store->get('products', $productId) : null;
            if (!$product) throw new InvalidArgumentException('Product not found');
            if (!($product['serial_tracking'] ?? false)) {
                throw new InvalidArgumentException('Product is not configured for serialized tracking');
            }

            $serial = trim((string) ($input['serial_no'] ?? ''));
            if ($serial === '') throw new InvalidArgumentException('Serial number is required');

            foreach ($this->store->all('product_units') as $row) {
                if (($row['product_id'] ?? null) === $productId
                    && strcasecmp((string) ($row['serial_no'] ?? ''), $serial) === 0) {
                    throw new InvalidArgumentException('Serial number already exists for product');
                }
            }

            $record = [
                'id' => UuidV7::generate(),
                'product_id' => $productId,
                'serial_no' => $serial,
                'batch_no' => trim((string) ($input['batch_no'] ?? '')) ?: null,
                'qr_token' => bin2hex(random_bytes(16)),
                'lifecycle_status' => 'active',
                'notes' => trim((string) ($input['notes'] ?? '')),
                'created_at' => gmdate(DATE_ATOM),
                'updated_at' => gmdate(DATE_ATOM),
            ];

            $this->store->put('product_units', $record['id'], $record);
            $this->index($record, $product);
            $this->audit->append('product_unit.created', 'product_unit', $record['id'], [
                'product_id' => $productId,
                'serial_no' => $serial,
            ]);

            return $record;
        });
    }

    public function retire(string $id, string $reason): array
    {
        return (new ExclusiveLock($this->lockRoot, 'inventory-ledger'))->run(function () use ($id, $reason): array {
            $record = $this->get($id);
            $reason = trim($reason);
            if ($reason === '') throw new InvalidArgumentException('Retirement reason is required');

            $stock = new StockService($this->store);
            $position = $stock->unitPosition($id);
            if (($position['location_id'] ?? null) !== null) {
                throw new InvalidArgumentException('Move serialized unit out of stock before retiring it');
            }

            $record['lifecycle_status'] = 'retired';
            $record['retired_reason'] = $reason;
            $record['retired_at'] = gmdate(DATE_ATOM);
            $record['updated_at'] = gmdate(DATE_ATOM);
            $this->store->put('product_units', $id, $record);

            $product = $this->store->get('products', (string) $record['product_id']) ?: [];
            $this->index($record, $product);
            $this->audit->append('product_unit.retired', 'product_unit', $id, ['reason' => $reason]);
            return $record;
        });
    }

    public function get(string $id): array
    {
        $record = $this->store->get('product_units', $id);
        if (!$record) throw new InvalidArgumentException('Serialized product unit not found');
        return $record;
    }

    public function byQr(string $token): array
    {
        $token = trim($token);
        if ($token === '') throw new InvalidArgumentException('QR token is required');

        foreach ($this->store->all('product_units') as $row) {
            if (hash_equals((string) ($row['qr_token'] ?? ''), $token)) {
                return $row;
            }
        }
        throw new InvalidArgumentException('QR unit not found');
    }

    public function forProduct(string $productId): array
    {
        return $this->store->where('product_units', 'product_id', $productId);
    }

    public function all(): array
    {
        return $this->store->all('product_units');
    }

    private function index(array $record, array $product): void
    {
        $this->search->upsert('product_unit', $record['id'], $record['serial_no'], [
            $product['name'] ?? '',
            $product['code'] ?? '',
            $product['sku'] ?? '',
            $record['batch_no'] ?? '',
            $record['qr_token'] ?? '',
            $record['lifecycle_status'] ?? '',
        ], [
            'product_id' => $record['product_id'],
            'product_code' => $product['code'] ?? null,
            'lifecycle_status' => $record['lifecycle_status'] ?? null,
        ]);
    }
}

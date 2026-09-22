<?php
declare(strict_types=1);

namespace Ecrm\Domain\Products;

use Ecrm\Audit\AuditLedger;
use Ecrm\Search\SearchIndex;
use Ecrm\Storage\AtomicJsonStore;
use Ecrm\Support\Money;
use Ecrm\Support\Sequence;
use Ecrm\Support\UuidV7;
use InvalidArgumentException;

final class ProductService
{
    public function __construct(
        private AtomicJsonStore $store,
        private Sequence $sequence,
        private SearchIndex $search,
        private AuditLedger $audit
    ) {}

    public function create(array $input): array
    {
        $now = gmdate(DATE_ATOM);
        $record = [
            'id' => UuidV7::generate(),
            'code' => $this->sequence->next('products', 'PRD-'),
            'name' => trim((string) ($input['name'] ?? '')),
            'sku' => trim((string) ($input['sku'] ?? '')),
            'category' => trim((string) ($input['category'] ?? '')),
            'subcategory' => trim((string) ($input['subcategory'] ?? '')),
            'brand' => trim((string) ($input['brand'] ?? '')),
            'model' => trim((string) ($input['model'] ?? '')),
            'description' => trim((string) ($input['description'] ?? '')),
            'unit' => trim((string) ($input['unit'] ?? 'Nos')),
            'hsn_sac' => trim((string) ($input['hsn_sac'] ?? '')),
            'gst_bps' => Money::toBps($input['gst_percent'] ?? 0),
            'purchase_price_paise' => Money::toPaise($input['purchase_price'] ?? 0),
            'selling_price_paise' => Money::toPaise($input['selling_price'] ?? 0),
            'mrp_paise' => Money::toPaise($input['mrp'] ?? 0),
            'min_stock' => max(0, (float) ($input['min_stock'] ?? 0)),
            'reorder_level' => max(0, (float) ($input['reorder_level'] ?? 0)),
            'serial_tracking' => (bool) ($input['serial_tracking'] ?? false),
            'batch_tracking' => (bool) ($input['batch_tracking'] ?? false),
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $this->validate($record);
        $this->store->put('products', $record['id'], $record);
        $this->index($record);
        $this->audit->append('product.created', 'product', $record['id'], ['code' => $record['code']]);
        return $record;
    }

    public function update(string $id, array $input): array
    {
        $record = $this->get($id);

        foreach (['name','sku','category','subcategory','brand','model','description','unit','hsn_sac'] as $field) {
            if (array_key_exists($field, $input)) {
                $record[$field] = trim((string) $input[$field]);
            }
        }
        if (array_key_exists('gst_percent', $input)) $record['gst_bps'] = Money::toBps($input['gst_percent']);
        if (array_key_exists('purchase_price', $input)) $record['purchase_price_paise'] = Money::toPaise($input['purchase_price']);
        if (array_key_exists('selling_price', $input)) $record['selling_price_paise'] = Money::toPaise($input['selling_price']);
        if (array_key_exists('mrp', $input)) $record['mrp_paise'] = Money::toPaise($input['mrp']);
        if (array_key_exists('min_stock', $input)) $record['min_stock'] = max(0, (float) $input['min_stock']);
        if (array_key_exists('reorder_level', $input)) $record['reorder_level'] = max(0, (float) $input['reorder_level']);
        if (array_key_exists('serial_tracking', $input)) $record['serial_tracking'] = filter_var($input['serial_tracking'], FILTER_VALIDATE_BOOL);
        if (array_key_exists('batch_tracking', $input)) $record['batch_tracking'] = filter_var($input['batch_tracking'], FILTER_VALIDATE_BOOL);
        if (array_key_exists('status', $input)) $record['status'] = strtolower(trim((string) $input['status']));

        $this->validate($record);
        $record['updated_at'] = gmdate(DATE_ATOM);
        $this->store->put('products', $id, $record);
        $this->index($record);
        $this->audit->append('product.updated', 'product', $id);
        return $record;
    }

    public function get(string $id): array
    {
        $record = $this->store->get('products', $id);
        if (!$record) throw new InvalidArgumentException('Product not found');
        return $record;
    }

    public function all(): array
    {
        return $this->store->all('products');
    }

    private function validate(array $record): void
    {
        if (trim((string) ($record['name'] ?? '')) === '') {
            throw new InvalidArgumentException('Product name is required');
        }
        if (!in_array((string) ($record['status'] ?? ''), ['active','inactive','archived'], true)) {
            throw new InvalidArgumentException('Invalid product status');
        }
        foreach (['purchase_price_paise','selling_price_paise','mrp_paise'] as $field) {
            if (($record[$field] ?? 0) < 0) throw new InvalidArgumentException('Product prices cannot be negative');
        }
    }

    private function index(array $record): void
    {
        $this->search->upsert('product', $record['id'], $record['name'], [
            $record['code'], $record['sku'], $record['category'], $record['subcategory'],
            $record['brand'], $record['model'], $record['description'], $record['hsn_sac']
        ], [
            'code' => $record['code'],
            'status' => $record['status'],
            'selling_price_paise' => $record['selling_price_paise'],
        ]);
    }
}

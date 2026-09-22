<?php
declare(strict_types=1);

$root = sys_get_temp_dir() . '/ecrm-operations-' . bin2hex(random_bytes(6));
mkdir($root, 0770, true);
define('ECRM_PRIVATE_ROOT', $root);
require dirname(__DIR__) . '/app/bootstrap.php';

use Ecrm\Audit\AuditLedger;
use Ecrm\Domain\Inventory\LocationService;
use Ecrm\Domain\Inventory\MovementService;
use Ecrm\Domain\Inventory\ProductUnitService;
use Ecrm\Domain\Inventory\StockService;
use Ecrm\Domain\Products\ProductService;
use Ecrm\Integrity\IntegrityVerifier;
use Ecrm\Search\SearchIndex;
use Ecrm\Search\SearchRebuilder;
use Ecrm\Storage\AtomicJsonStore;
use Ecrm\Support\Sequence;

function expectOperations(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function expectOperationsError(callable $callback, string $expected): void
{
    try {
        $callback();
        throw new RuntimeException('Expected error was not raised: ' . $expected);
    } catch (InvalidArgumentException $e) {
        expectOperations($e->getMessage() === $expected, 'Unexpected error: ' . $e->getMessage());
    }
}

function cleanupOperations(string $dir): void
{
    if (!is_dir($dir)) return;
    foreach (scandir($dir) ?: [] as $file) {
        if ($file === '.' || $file === '..') continue;
        $path = $dir . '/' . $file;
        is_dir($path) ? cleanupOperations($path) : unlink($path);
    }
    rmdir($dir);
}

try {
    $store = new AtomicJsonStore($root . '/data');
    $search = new SearchIndex($root . '/indexes');
    $audit = new AuditLedger($root . '/audit');
    $sequence = new Sequence($root . '/data/sequences');

    $products = new ProductService($store, $sequence, $search, $audit);
    $locations = new LocationService($store, $sequence, $search, $audit);
    $units = new ProductUnitService($store, $search, $audit, $root . '/locks');
    $movements = new MovementService($store, $audit, $root . '/locks');
    $stock = new StockService($store);

    $warehouse = $locations->create([
        'name' => 'Main Warehouse',
        'type' => 'warehouse',
        'address' => 'Industrial Estate',
    ]);
    $rack = $locations->create([
        'name' => 'Rack A1',
        'type' => 'rack',
        'parent_id' => $warehouse['id'],
    ]);
    $workshop = $locations->create([
        'name' => 'Workshop Bay 1',
        'type' => 'workshop_bay',
    ]);

    $tree = $locations->tree();
    expectOperations(count($tree) === 2, 'Location root tree count incorrect');
    $warehouseNode = array_values(array_filter($tree, static fn(array $r): bool => $r['id'] === $warehouse['id']))[0] ?? null;
    expectOperations($warehouseNode !== null && count($warehouseNode['children']) === 1, 'Location child hierarchy incorrect');

    $cable = $products->create([
        'name' => 'Power Cable',
        'sku' => 'CABLE-1',
        'unit' => 'Mtr',
        'selling_price' => 100,
    ]);
    $motor = $products->create([
        'name' => 'Servo Motor',
        'sku' => 'MOTOR-1',
        'serial_tracking' => true,
        'selling_price' => 5000,
    ]);

    $receive = $movements->record([
        'product_id' => $cable['id'],
        'to_location_id' => $warehouse['id'],
        'quantity' => 10,
        'type' => 'receive',
    ]);
    $transfer = $movements->record([
        'product_id' => $cable['id'],
        'from_location_id' => $warehouse['id'],
        'to_location_id' => $rack['id'],
        'quantity' => 4,
        'type' => 'transfer',
    ]);
    $issue = $movements->record([
        'product_id' => $cable['id'],
        'from_location_id' => $rack['id'],
        'quantity' => 1,
        'type' => 'issue',
    ]);

    expectOperations($stock->balanceMilli($cable['id'], $warehouse['id']) === 6000, 'Warehouse cable balance incorrect');
    expectOperations($stock->balanceMilli($cable['id'], $rack['id']) === 3000, 'Rack cable balance incorrect');

    expectOperationsError(
        fn() => $movements->reverse($transfer['id'], 'Too early'),
        'Insufficient stock to reverse movement'
    );

    $movements->reverse($issue['id'], 'Issue was incorrect');
    expectOperations($stock->balanceMilli($cable['id'], $rack['id']) === 4000, 'Issue reversal did not restore stock');

    $movements->reverse($transfer['id'], 'Transfer entered in error');
    expectOperations($stock->balanceMilli($cable['id'], $warehouse['id']) === 10000, 'Transfer reversal did not restore warehouse stock');
    expectOperations($stock->balanceMilli($cable['id'], $rack['id']) === 0, 'Transfer reversal did not clear rack stock');

    expectOperationsError(
        fn() => $locations->update($warehouse['id'], ['status' => 'inactive']),
        'Move all stock out before deactivating location'
    );

    $unit = $units->create([
        'product_id' => $motor['id'],
        'serial_no' => 'SM-0001',
        'batch_no' => 'B-01',
    ]);
    expectOperations(strlen($unit['qr_token']) === 32, 'Serialized QR token format incorrect');

    expectOperationsError(
        fn() => $units->create(['product_id' => $motor['id'], 'serial_no' => 'sm-0001']),
        'Serial number already exists for product'
    );

    expectOperationsError(
        fn() => $movements->record([
            'product_id' => $motor['id'],
            'to_location_id' => $rack['id'],
            'quantity' => 1,
            'type' => 'receive',
        ]),
        'Serialized product movement requires a product unit'
    );

    $unitReceive = $movements->record([
        'product_id' => $motor['id'],
        'product_unit_id' => $unit['id'],
        'to_location_id' => $rack['id'],
        'quantity' => 1,
        'type' => 'receive',
    ]);

    expectOperationsError(
        fn() => $movements->record([
            'product_id' => $motor['id'],
            'product_unit_id' => $unit['id'],
            'from_location_id' => $warehouse['id'],
            'to_location_id' => $workshop['id'],
            'quantity' => 1,
            'type' => 'transfer',
        ]),
        'Serialized unit is not at source location'
    );

    $unitTransfer = $movements->record([
        'product_id' => $motor['id'],
        'product_unit_id' => $unit['id'],
        'from_location_id' => $rack['id'],
        'to_location_id' => $workshop['id'],
        'quantity' => 1,
        'type' => 'workshop_in',
    ]);

    $position = $stock->unitPosition($unit['id']);
    expectOperations($position['location_id'] === $workshop['id'], 'Serialized unit workshop position incorrect');
    expectOperations($position['operational_status'] === 'in_workshop', 'Serialized unit operational status incorrect');

    expectOperationsError(
        fn() => $movements->reverse($unitReceive['id'], 'Cannot reverse out of order'),
        'Serialized unit moved after original movement; reverse later movements first'
    );

    $movements->reverse($unitTransfer['id'], 'Workshop transfer correction');
    $movements->reverse($unitReceive['id'], 'Unit was not received');
    $position = $stock->unitPosition($unit['id']);
    expectOperations($position['location_id'] === null, 'Serialized receive reversal did not clear location');

    $retired = $units->retire($unit['id'], 'Test retirement');
    expectOperations($retired['lifecycle_status'] === 'retired', 'Serialized unit retirement failed');

    $byQr = $units->byQr($unit['qr_token']);
    expectOperations($byQr['id'] === $unit['id'], 'QR token lookup failed');

    $summary = $stock->product($cable['id']);
    expectOperations($summary['total_quantity_milli'] === 10000, 'Product stock summary total incorrect');
    expectOperations(count($summary['locations']) === 1, 'Product stock location count incorrect');

    $rebuilt = (new SearchRebuilder($store, $search))->rebuild();
    expectOperations(($rebuilt['locations'] ?? 0) === 3, 'Location search rebuild count incorrect');
    expectOperations(($rebuilt['product_units'] ?? 0) === 1, 'Product unit search rebuild count incorrect');
    expectOperations(($rebuilt['inventory_movements'] ?? 0) === 9, 'Inventory movement rebuild count incorrect');
    expectOperations(count($search->search('SM-0001')) === 1, 'Serialized unit not searchable');
    expectOperations(count($search->search('Rack A1')) === 1, 'Location not searchable');

    $integrity = (new IntegrityVerifier($store, $root . '/audit'))->verify();
    expectOperations($integrity['ok'] === true, 'Operations integrity failed: ' . implode('; ', $integrity['errors']));
    expectOperations(($integrity['checked']['inventory_movements'] ?? 0) === 9, 'Movement integrity count incorrect');

    echo "Operations inventory test passed\n";
} finally {
    cleanupOperations($root);
}

<?php
declare(strict_types=1);

$root = sys_get_temp_dir() . '/ecrm-workshop-' . bin2hex(random_bytes(6));
mkdir($root, 0770, true);
define('ECRM_PRIVATE_ROOT', $root);
require dirname(__DIR__) . '/app/bootstrap.php';

use Ecrm\Audit\AuditLedger;
use Ecrm\Domain\Customers\CustomerService;
use Ecrm\Domain\Inventory\LocationService;
use Ecrm\Domain\Inventory\MovementService;
use Ecrm\Domain\Inventory\ProductUnitService;
use Ecrm\Domain\Inventory\StockService;
use Ecrm\Domain\Products\ProductService;
use Ecrm\Domain\Workshop\WorkshopJobService;
use Ecrm\Integrity\IntegrityVerifier;
use Ecrm\Search\SearchIndex;
use Ecrm\Search\SearchRebuilder;
use Ecrm\Storage\AtomicJsonStore;
use Ecrm\Support\Sequence;

function expectWorkshop(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function expectWorkshopError(callable $callback, string $expected): void
{
    try {
        $callback();
        throw new RuntimeException('Expected error was not raised: ' . $expected);
    } catch (InvalidArgumentException $e) {
        expectWorkshop($e->getMessage() === $expected, 'Unexpected error: ' . $e->getMessage());
    }
}

function cleanupWorkshop(string $dir): void
{
    if (!is_dir($dir)) return;
    foreach (scandir($dir) ?: [] as $file) {
        if ($file === '.' || $file === '..') continue;
        $path = $dir . '/' . $file;
        is_dir($path) ? cleanupWorkshop($path) : unlink($path);
    }
    rmdir($dir);
}

try {
    $store = new AtomicJsonStore($root . '/data');
    $search = new SearchIndex($root . '/indexes');
    $audit = new AuditLedger($root . '/audit');
    $sequence = new Sequence($root . '/data/sequences');

    $customers = new CustomerService($store, $sequence, $search, $audit);
    $products = new ProductService($store, $sequence, $search, $audit);
    $locations = new LocationService($store, $sequence, $search, $audit);
    $units = new ProductUnitService($store, $search, $audit, $root . '/locks');
    $movements = new MovementService($store, $audit, $root . '/locks', $sequence);
    $stock = new StockService($store);
    $jobs = new WorkshopJobService($store, $sequence, $search, $audit, $movements, $stock, $root . '/locks');

    $customer = $customers->create([
        'name' => 'Workshop Customer',
        'mobile' => '9000000050',
    ]);
    $warehouse = $locations->create(['name' => 'Service Warehouse', 'type' => 'warehouse']);
    $bay = $locations->create(['name' => 'Repair Bay 1', 'type' => 'workshop_bay']);

    $motor = $products->create([
        'name' => 'Workshop Motor',
        'serial_tracking' => true,
        'selling_price' => 5000,
    ]);
    $unit = $units->create([
        'product_id' => $motor['id'],
        'serial_no' => 'WM-0001',
    ]);
    $movements->record([
        'product_id' => $motor['id'],
        'product_unit_id' => $unit['id'],
        'to_location_id' => $warehouse['id'],
        'quantity' => 1,
        'type' => 'receive',
    ]);

    $job = $jobs->create([
        'customer_id' => $customer['id'],
        'product_unit_id' => $unit['id'],
        'workshop_location_id' => $bay['id'],
        'reported_issue' => 'Bearing noise under load',
        'priority' => 'high',
    ]);

    expectWorkshop(str_starts_with($job['number'], 'WJOB-'), 'Workshop job number missing');
    expectWorkshop($job['ownership'] === 'company', 'Serialized company ownership not derived');

    $job = $jobs->checkIn($job['id']);
    expectWorkshop($job['status'] === 'in_progress', 'Workshop check-in should start job');
    expectWorkshop($job['check_in_movement_id'] !== null, 'Company workshop check-in movement missing');
    expectWorkshop($stock->unitPosition($unit['id'])['location_id'] === $bay['id'], 'Company asset did not move to workshop bay');

    $job = $jobs->update($job['id'], [
        'diagnosis' => 'Front bearing worn',
        'work_done' => 'Bearing replaced and motor tested',
        'parts_notes' => 'Bearing 6204 x 1',
    ]);
    expectWorkshop($job['diagnosis'] === 'Front bearing worn', 'Workshop diagnosis update failed');

    $job = $jobs->changeStatus($job['id'], 'waiting_parts');
    $job = $jobs->changeStatus($job['id'], 'in_progress');
    $job = $jobs->changeStatus($job['id'], 'ready');

    expectWorkshopError(
        fn() => $jobs->changeStatus($job['id'], 'completed'),
        'Check asset out before completing workshop job'
    );

    $job = $jobs->checkOut($job['id'], $warehouse['id']);
    expectWorkshop($job['check_out_movement_id'] !== null, 'Company workshop check-out movement missing');
    expectWorkshop($stock->unitPosition($unit['id'])['location_id'] === $warehouse['id'], 'Company asset did not return to warehouse');

    $job = $jobs->changeStatus($job['id'], 'completed');
    expectWorkshop($job['status'] === 'completed' && $job['completed_at'] !== null, 'Workshop completion failed');

    expectWorkshopError(
        fn() => $jobs->update($job['id'], ['notes' => 'must fail']),
        'Final workshop job cannot be edited'
    );

    $customerJob = $jobs->create([
        'customer_id' => $customer['id'],
        'product_id' => $motor['id'],
        'asset_serial' => 'CUSTOMER-MOTOR-77',
        'ownership' => 'customer',
        'workshop_location_id' => $bay['id'],
        'reported_issue' => 'Motor not starting',
    ]);
    $customerJob = $jobs->checkIn($customerJob['id']);
    expectWorkshop($customerJob['check_in_movement_id'] === null, 'Customer asset must not enter company inventory ledger');
    $customerJob = $jobs->checkOut($customerJob['id']);
    expectWorkshop($customerJob['check_out_movement_id'] === null, 'Customer asset checkout must not create inventory movement');
    $customerJob = $jobs->changeStatus($customerJob['id'], 'completed');
    expectWorkshop($customerJob['status'] === 'completed', 'Customer-owned workshop job completion failed');

    expectWorkshop(count($movements->history()) === 3, 'Workshop should create exactly receive, in and out company movements');

    $rebuilt = (new SearchRebuilder($store, $search))->rebuild();
    expectWorkshop(($rebuilt['workshop_jobs'] ?? 0) === 2, 'Workshop search rebuild count incorrect');
    expectWorkshop(count($search->search('Bearing noise')) === 1, 'Workshop issue not searchable');

    $integrity = (new IntegrityVerifier($store, $root . '/audit'))->verify();
    expectWorkshop($integrity['ok'] === true, 'Workshop integrity failed: ' . implode('; ', $integrity['errors']));
    expectWorkshop(($integrity['checked']['workshop_jobs'] ?? 0) === 2, 'Workshop integrity count incorrect');

    echo "Workshop test passed\n";
} finally {
    cleanupWorkshop($root);
}

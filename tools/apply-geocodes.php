<?php
declare(strict_types=1);

$root = dirname(__DIR__);
define('ECRM_PRIVATE_ROOT', $root);
require $root . '/app/bootstrap.php';

use Ecrm\Audit\AuditLedger;
use Ecrm\Domain\Customers\AddressService;
use Ecrm\Search\SearchIndex;
use Ecrm\Storage\AtomicJsonStore;
use Ecrm\Support\Runtime;

$results = Runtime::jobsRoot() . '/results';
$applied = Runtime::jobsRoot() . '/applied';

if (!is_dir($results)) {
    echo "No geocoding results\n";
    exit(0);
}
if (!is_dir($applied) && !mkdir($applied, 0770, true) && !is_dir($applied)) {
    throw new RuntimeException('Cannot create applied geocode directory');
}

$store = new AtomicJsonStore(Runtime::dataRoot());
$service = new AddressService(
    $store,
    new SearchIndex(Runtime::indexRoot()),
    new AuditLedger(Runtime::auditRoot())
);

$count = 0;
foreach (glob($results . '/*.json') ?: [] as $path) {
    $payload = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    $service->applyGeocode(
        (string) $payload['address_id'],
        (float) $payload['latitude'],
        (float) $payload['longitude'],
        isset($payload['confidence']) ? (float) $payload['confidence'] : null
    );

    $target = $applied . '/' . basename($path);
    if (!rename($path, $target)) {
        throw new RuntimeException('Could not archive applied geocode result');
    }
    $count++;
}

echo "Applied {$count} geocoding result(s)\n";

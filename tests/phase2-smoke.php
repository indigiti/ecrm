<?php
declare(strict_types=1);

$root = sys_get_temp_dir() . '/ecrm-' . bin2hex(random_bytes(6));
mkdir($root, 0770, true);

define('ECRM_PRIVATE_ROOT', $root);
require dirname(__DIR__) . '/app/bootstrap.php';

use Ecrm\Audit\AuditLedger;
use Ecrm\Domain\CRM\ActivityService;
use Ecrm\Domain\CRM\LeadService;
use Ecrm\Domain\Customers\AddressService;
use Ecrm\Domain\Customers\ContactService;
use Ecrm\Domain\Customers\CustomerService;
use Ecrm\Search\SearchIndex;
use Ecrm\Storage\AtomicJsonStore;
use Ecrm\Support\Sequence;

function expect(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

function cleanup(string $dir): void {
    if (!is_dir($dir)) return;
    foreach (scandir($dir) ?: [] as $file) {
        if ($file === '.' || $file === '..') continue;
        $path = $dir . '/' . $file;
        is_dir($path) ? cleanup($path) : unlink($path);
    }
    rmdir($dir);
}

try {
    $store = new AtomicJsonStore($root . '/data');
    $search = new SearchIndex($root . '/indexes');
    $audit = new AuditLedger($root . '/audit');
    $sequence = new Sequence($root . '/data/sequences');

    $customers = new CustomerService($store, $sequence, $search, $audit);
    $contacts = new ContactService($store, $search, $audit);
    $addresses = new AddressService($store, $search, $audit);
    $leads = new LeadService($store, $sequence, $search, $audit);
    $activities = new ActivityService($store, $audit);

    $customer = $customers->create(['name' => 'ABC Industries', 'mobile' => '9999999999', 'city' => 'Pune']);
    expect(str_starts_with($customer['number'], 'CUST-'), 'Customer number missing');
    expect(strlen($customer['id']) === 36, 'UUIDv7 format invalid');

    $contacts->create($customer['id'], ['name' => 'Asha', 'mobile' => '8888888888', 'is_primary' => true]);
    $addresses->create($customer['id'], ['type' => 'site', 'address' => 'Industrial Estate', 'city' => 'Pune', 'pin' => '411001', 'latitude' => 18.5204, 'longitude' => 73.8567]);
    $lead = $leads->create(['name' => 'Expansion project', 'company' => 'ABC Industries', 'customer_id' => $customer['id']]);
    $lead = $leads->changeStage($lead['id'], 'requirement');
    expect($lead['stage'] === 'requirement', 'Lead stage update failed');

    $activity = $activities->create(['customer_id' => $customer['id'], 'type' => 'follow_up', 'subject' => 'Call procurement']);
    expect($activity['status'] === 'open', 'Follow-up should be open');
    $activity = $activities->complete($activity['id']);
    expect($activity['status'] === 'completed', 'Follow-up completion failed');

    $results = $search->search('ABC');
    expect(count($results) >= 1, 'Search index failed');
    expect(count($addresses->mapped()) === 1, 'Mapped address query failed');

    echo "Phase 2 smoke test passed\n";
} finally {
    cleanup($root);
}

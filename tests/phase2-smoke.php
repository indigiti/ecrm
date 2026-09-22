<?php
declare(strict_types=1);

$root = sys_get_temp_dir() . '/ecrm-' . bin2hex(random_bytes(6));
mkdir($root, 0770, true);
define('ECRM_PRIVATE_ROOT', $root);
require dirname(__DIR__) . '/app/bootstrap.php';

use Ecrm\Audit\AuditLedger;
use Ecrm\Domain\CRM\ActivityService;
use Ecrm\Domain\CRM\GeocodingQueue;
use Ecrm\Domain\CRM\LeadConversionService;
use Ecrm\Domain\CRM\LeadService;
use Ecrm\Domain\Customers\AddressService;
use Ecrm\Domain\Customers\ContactService;
use Ecrm\Domain\Customers\CustomerService;
use Ecrm\Search\SearchIndex;
use Ecrm\Storage\AtomicJsonStore;
use Ecrm\Support\Sequence;

function expect(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function cleanup(string $dir): void { if (!is_dir($dir)) return; foreach (scandir($dir) ?: [] as $file) { if ($file === '.' || $file === '..') continue; $path=$dir.'/'.$file; is_dir($path)?cleanup($path):unlink($path); } rmdir($dir); }

try {
    $store = new AtomicJsonStore($root . '/data');
    $search = new SearchIndex($root . '/indexes');
    $audit = new AuditLedger($root . '/audit');
    $sequence = new Sequence($root . '/data/sequences');
    $queue = new GeocodingQueue($root . '/jobs');

    $customers = new CustomerService($store, $sequence, $search, $audit);
    $contacts = new ContactService($store, $search, $audit);
    $addresses = new AddressService($store, $search, $audit, $queue);
    $leads = new LeadService($store, $sequence, $search, $audit);
    $conversion = new LeadConversionService($leads, $customers);
    $activities = new ActivityService($store, $audit);

    $customer = $customers->create(['name' => 'ABC Industries', 'mobile' => '9999999999']);
    expect(str_starts_with($customer['number'], 'CUST-'), 'Customer number missing');
    expect(strlen($customer['id']) === 36, 'UUIDv7 format invalid');
    $contacts->create($customer['id'], ['name' => 'Asha', 'mobile' => '8888888888', 'is_primary' => true]);

    $pending = $addresses->create($customer['id'], ['type' => 'site', 'address' => 'Industrial Estate', 'city' => 'Pune', 'pin' => '411001']);
    expect($pending['geocode_status'] === 'pending', 'Address should require geocoding');
    expect(is_file($root . '/jobs/pending/' . $pending['id'] . '.json'), 'Geocoding job was not queued');

    $mapped = $addresses->create($customer['id'], ['type' => 'warehouse', 'address' => 'Warehouse', 'city' => 'Pune', 'latitude' => 18.5204, 'longitude' => 73.8567]);
    expect(count($addresses->mapped()) === 1, 'Mapped address query failed');

    $lead = $leads->create(['name' => 'Expansion project', 'company' => 'Newco Industries', 'mobile' => '7777777777']);
    $lead = $leads->changeStage($lead['id'], 'requirement');
    expect($lead['stage'] === 'requirement', 'Lead stage update failed');

    $converted = $conversion->convert($lead['id']);
    expect($converted['created'] === true, 'Lead conversion did not create a customer');
    expect($converted['lead']['stage'] === 'won', 'Converted lead must be won');
    expect($converted['lead']['customer_id'] === $converted['customer']['id'], 'Lead/customer relationship missing');
    $convertedAgain = $conversion->convert($lead['id']);
    expect($convertedAgain['created'] === false, 'Lead conversion must be idempotent');

    $activity = $activities->create([
        'customer_id' => $customer['id'],
        'type' => 'follow_up',
        'subject' => 'Call procurement',
        'due_at' => '2000-01-01T09:00'
    ]);
    expect($activity['status'] === 'open', 'Follow-up should be open');
    expect($activity['attention'] === 'overdue', 'Past follow-up should be overdue');
    expect(count($activities->attention()['overdue']) === 1, 'Overdue attention bucket failed');
    expect($activities->complete($activity['id'])['status'] === 'completed', 'Follow-up completion failed');

    expect(count($search->search('ABC')) >= 1, 'Search index failed');
    echo "Phase 2 smoke test passed\n";
} finally {
    cleanup($root);
}

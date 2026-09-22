<?php
declare(strict_types=1);

$root = sys_get_temp_dir() . '/ecrm-integrity-' . bin2hex(random_bytes(6));
mkdir($root, 0770, true);
define('ECRM_PRIVATE_ROOT', $root);
require dirname(__DIR__) . '/app/bootstrap.php';

use Ecrm\Audit\AuditLedger;
use Ecrm\Domain\CRM\ActivityService;
use Ecrm\Domain\CRM\GeocodingQueue;
use Ecrm\Domain\CRM\LeadService;
use Ecrm\Domain\Customers\AddressService;
use Ecrm\Domain\Customers\ContactService;
use Ecrm\Domain\Customers\CustomerService;
use Ecrm\Integrity\IntegrityVerifier;
use Ecrm\Search\SearchIndex;
use Ecrm\Search\SearchRebuilder;
use Ecrm\Storage\AtomicJsonStore;
use Ecrm\Support\Sequence;

function expectIntegrity(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function cleanupIntegrity(string $dir): void
{
    if (!is_dir($dir)) return;
    foreach (scandir($dir) ?: [] as $file) {
        if ($file === '.' || $file === '..') continue;
        $path = $dir . '/' . $file;
        is_dir($path) ? cleanupIntegrity($path) : unlink($path);
    }
    rmdir($dir);
}

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
    $activities = new ActivityService($store, $audit);

    $customer = $customers->create([
        'name' => 'Integrity Test Customer',
        'mobile' => '9000000000',
        'gstin' => '27ABCDE1234F1Z5',
    ]);
    $contacts->create($customer['id'], [
        'name' => 'Procurement Contact',
        'email' => 'procurement@example.com',
    ]);
    $addresses->create($customer['id'], [
        'type' => 'site',
        'address' => 'Industrial Area',
        'city' => 'Pune',
        'pin' => '411001',
    ]);
    $leads->create([
        'name' => 'New Plant Requirement',
        'company' => 'Prospect Industries',
    ]);
    $activities->create([
        'customer_id' => $customer['id'],
        'type' => 'follow_up',
        'subject' => 'Confirm requirement',
    ]);

    @unlink($root . '/indexes/global.json');

    $rebuilt = (new SearchRebuilder($store, $search))->rebuild();
    expectIntegrity($rebuilt['indexed'] === 4, 'Unexpected rebuilt search count');
    expectIntegrity(count($search->search('Integrity Test')) >= 1, 'Rebuilt search does not find customer');
    expectIntegrity(count($search->search('Procurement Contact')) >= 1, 'Rebuilt search does not find contact');

    $result = (new IntegrityVerifier($store, $root . '/audit'))->verify();
    expectIntegrity($result['ok'] === true, 'Integrity verification failed: ' . implode('; ', $result['errors']));
    expectIntegrity(($result['checked']['customers'] ?? 0) === 1, 'Customer integrity count incorrect');
    expectIntegrity(($result['checked']['audit_events'] ?? 0) >= 5, 'Audit ledger was not verified');

    echo "Phase 2 integrity test passed\n";
} finally {
    cleanupIntegrity($root);
}

<?php
declare(strict_types=1);

$root = sys_get_temp_dir() . '/ecrm-phase3-' . bin2hex(random_bytes(6));
mkdir($root, 0770, true);
define('ECRM_PRIVATE_ROOT', $root);
require dirname(__DIR__) . '/app/bootstrap.php';

use Ecrm\Audit\AuditLedger;
use Ecrm\Domain\Customers\AddressService;
use Ecrm\Domain\Customers\CustomerService;
use Ecrm\Domain\Products\ProductService;
use Ecrm\Domain\Sales\InvoiceService;
use Ecrm\Domain\Sales\QuotationService;
use Ecrm\Domain\Sales\SalesCalculator;
use Ecrm\Search\SearchIndex;
use Ecrm\Storage\AtomicJsonStore;
use Ecrm\Support\Sequence;
use InvalidArgumentException;

function expectSales(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function cleanupSales(string $dir): void
{
    if (!is_dir($dir)) return;
    foreach (scandir($dir) ?: [] as $file) {
        if ($file === '.' || $file === '..') continue;
        $path = $dir . '/' . $file;
        is_dir($path) ? cleanupSales($path) : unlink($path);
    }
    rmdir($dir);
}

try {
    $store = new AtomicJsonStore($root . '/data');
    $search = new SearchIndex($root . '/indexes');
    $audit = new AuditLedger($root . '/audit');
    $sequence = new Sequence($root . '/data/sequences');
    $calculator = new SalesCalculator();

    $customers = new CustomerService($store, $sequence, $search, $audit);
    $addresses = new AddressService($store, $search, $audit);
    $products = new ProductService($store, $sequence, $search, $audit);
    $quotes = new QuotationService($store, $sequence, $search, $audit, $calculator);
    $invoices = new InvoiceService($store, $sequence, $search, $audit, $calculator);

    $customer = $customers->create([
        'name' => 'Phase 3 Customer',
        'gstin' => '27ABCDE1234F1Z5',
        'mobile' => '9000000001',
    ]);
    $address = $addresses->create($customer['id'], [
        'type' => 'billing',
        'address' => 'Sales Test Road',
        'city' => 'Pune',
        'state' => 'Maharashtra',
        'pin' => '411001',
        'latitude' => 18.5204,
        'longitude' => 73.8567,
    ]);
    $product = $products->create([
        'name' => 'Control Panel',
        'sku' => 'CP-001',
        'unit' => 'Nos',
        'hsn_sac' => '8537',
        'gst_percent' => 18,
        'selling_price' => 1000,
    ]);

    $quote = $quotes->create([
        'customer_id' => $customer['id'],
        'address_id' => $address['id'],
        'tax_mode' => 'intra_state',
        'items' => [[
            'product_id' => $product['id'],
            'description' => $product['name'],
            'hsn_sac' => $product['hsn_sac'],
            'unit' => $product['unit'],
            'quantity' => 2,
            'rate_paise' => $product['selling_price_paise'],
            'discount_percent' => 10,
            'tax_bps' => $product['gst_bps'],
        ]],
        'payment_terms' => '50% advance',
    ]);

    expectSales(str_starts_with($quote['number'], 'QUO-'), 'Quotation number missing');
    expectSales($quote['totals']['gross_paise'] === 200000, 'Quotation gross calculation incorrect');
    expectSales($quote['totals']['discount_paise'] === 20000, 'Quotation discount calculation incorrect');
    expectSales($quote['totals']['taxable_paise'] === 180000, 'Quotation taxable calculation incorrect');
    expectSales($quote['totals']['tax_paise'] === 32400, 'Quotation tax calculation incorrect');
    expectSales($quote['totals']['cgst_paise'] === 16200, 'CGST calculation incorrect');
    expectSales($quote['totals']['sgst_paise'] === 16200, 'SGST calculation incorrect');
    expectSales($quote['totals']['grand_total_paise'] === 212400, 'Quotation grand total incorrect');

    $quote = $quotes->changeStatus($quote['id'], 'sent');
    $quote = $quotes->changeStatus($quote['id'], 'approved');
    expectSales($quote['status'] === 'approved', 'Quotation approval failed');

    try {
        $quotes->update($quote['id'], ['notes' => 'must fail']);
        throw new RuntimeException('Approved quotation was editable');
    } catch (InvalidArgumentException $expected) {
        expectSales($expected->getMessage() === 'Final quotation cannot be edited', 'Unexpected quotation lock error');
    }

    $invoice = $invoices->createFromQuotation($quote['id'], ['due_date' => '2026-10-15']);
    expectSales(str_starts_with($invoice['number'], 'INV-'), 'Invoice number missing');
    expectSales($invoice['quotation_id'] === $quote['id'], 'Quotation link missing');
    expectSales($invoice['totals']['grand_total_paise'] === $quote['totals']['grand_total_paise'], 'Invoice does not preserve quotation totals');

    $sameInvoice = $invoices->createFromQuotation($quote['id']);
    expectSales($sameInvoice['id'] === $invoice['id'], 'Quotation-to-invoice conversion must be idempotent');

    $invoice = $invoices->issue($invoice['id']);
    expectSales($invoice['status'] === 'issued', 'Invoice issue failed');

    $customers->update($customer['id'], ['name' => 'Customer Renamed Later']);
    $issued = $invoices->get($invoice['id']);
    expectSales($issued['customer_snapshot']['name'] === 'Phase 3 Customer', 'Issued invoice snapshot changed with customer master');

    try {
        $invoices->update($invoice['id'], ['notes' => 'must fail']);
        throw new RuntimeException('Issued invoice was editable');
    } catch (InvalidArgumentException $expected) {
        expectSales($expected->getMessage() === 'Issued invoice financial snapshot is immutable', 'Unexpected invoice lock error');
    }

    $standalone = $invoices->create([
        'customer_id' => $customer['id'],
        'items' => [[
            'description' => 'Service charge',
            'quantity' => 1,
            'rate' => 500,
            'tax_percent' => 18,
        ]],
        'tax_mode' => 'inter_state',
    ]);
    expectSales($standalone['quotation_id'] === null, 'Standalone invoice should not require quotation');
    expectSales($standalone['totals']['igst_paise'] === 9000, 'IGST calculation incorrect');

    echo "Phase 3 sales smoke test passed\n";
} finally {
    cleanupSales($root);
}

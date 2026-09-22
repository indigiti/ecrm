<?php
declare(strict_types=1);

$root = sys_get_temp_dir() . '/ecrm-phase4-' . bin2hex(random_bytes(6));
mkdir($root, 0770, true);
define('ECRM_PRIVATE_ROOT', $root);
require dirname(__DIR__) . '/app/bootstrap.php';

use DateTimeImmutable;
use DateTimeZone;
use Ecrm\Audit\AuditLedger;
use Ecrm\Domain\Customers\CustomerService;
use Ecrm\Domain\Finance\AllocationService;
use Ecrm\Domain\Finance\PaymentBatchService;
use Ecrm\Domain\Finance\PaymentService;
use Ecrm\Domain\Finance\ReceivablesService;
use Ecrm\Domain\Sales\InvoiceService;
use Ecrm\Domain\Sales\SalesCalculator;
use Ecrm\Integrity\IntegrityVerifier;
use Ecrm\Search\SearchIndex;
use Ecrm\Search\SearchRebuilder;
use Ecrm\Storage\AtomicJsonStore;
use Ecrm\Support\Sequence;
use InvalidArgumentException;

function expectFinance(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function expectFinanceError(callable $callback, string $expected): void
{
    try {
        $callback();
        throw new RuntimeException('Expected error was not raised: ' . $expected);
    } catch (InvalidArgumentException $e) {
        expectFinance($e->getMessage() === $expected, 'Unexpected error: ' . $e->getMessage());
    }
}

function cleanupFinance(string $dir): void
{
    if (!is_dir($dir)) return;
    foreach (scandir($dir) ?: [] as $file) {
        if ($file === '.' || $file === '..') continue;
        $path = $dir . '/' . $file;
        is_dir($path) ? cleanupFinance($path) : unlink($path);
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
    $invoices = new InvoiceService($store, $sequence, $search, $audit, $calculator, $root . '/locks');
    $batches = new PaymentBatchService($store, $sequence, $search, $audit);
    $payments = new PaymentService($store, $sequence, $search, $audit, $root . '/locks');
    $allocations = new AllocationService($store, $audit, $root . '/locks', $search);
    $receivables = new ReceivablesService($store, $allocations);

    $customer = $customers->create([
        'name' => 'Finance Test Customer',
        'mobile' => '9000000040',
    ]);

    $tz = new DateTimeZone('Asia/Kolkata');
    $today = new DateTimeImmutable('today', $tz);
    $future = $today->modify('+15 days')->format('Y-m-d');
    $past10 = $today->modify('-10 days')->format('Y-m-d');
    $past45 = $today->modify('-45 days')->format('Y-m-d');

    $makeInvoice = function(string $description, float $rate, string $dueDate) use ($invoices, $customer): array {
        $invoice = $invoices->create([
            'customer_id' => $customer['id'],
            'due_date' => $dueDate,
            'tax_mode' => 'intra_state',
            'items' => [[
                'description' => $description,
                'quantity' => 1,
                'rate' => $rate,
                'tax_percent' => 18,
            ]],
        ]);
        return $invoices->issue($invoice['id']);
    };

    $invoice1 = $makeInvoice('Invoice One', 1000, $future);
    $invoice2 = $makeInvoice('Invoice Two', 500, $past10);
    $invoice3 = $makeInvoice('Invoice Three', 1000, $past45);

    expectFinance($invoice1['totals']['grand_total_paise'] === 118000, 'Invoice 1 total incorrect');
    expectFinance($invoice2['totals']['grand_total_paise'] === 59000, 'Invoice 2 total incorrect');

    $batch = $batches->create([
        'customer_id' => $customer['id'],
        'title' => 'September bank receipts',
    ]);

    $payment1 = $payments->create([
        'customer_id' => $customer['id'],
        'batch_id' => $batch['id'],
        'amount' => 1000,
        'method' => 'neft',
        'reference' => 'UTR-ONE',
        'method_meta' => ['utr' => 'UTR-ONE', 'bank' => 'Test Bank'],
    ]);

    $payment2 = $payments->create([
        'customer_id' => $customer['id'],
        'batch_id' => $batch['id'],
        'amount' => 770,
        'method' => 'upi',
        'reference' => 'UPI-TWO',
        'method_meta' => ['upi_ref' => 'UPI-TWO'],
    ]);

    $a11 = $allocations->allocate($payment1['id'], $invoice1['id'], 700);
    $a12 = $allocations->allocate($payment1['id'], $invoice2['id'], 300);
    expectFinance($invoices->get($invoice1['id'])['status'] === 'partially_paid', 'Invoice 1 should be partially paid');
    expectFinance($invoices->get($invoice2['id'])['status'] === 'partially_paid', 'Invoice 2 should be partially paid');

    $a21 = $allocations->allocate($payment2['id'], $invoice1['id'], 480);
    $a22 = $allocations->allocate($payment2['id'], $invoice2['id'], 290);
    expectFinance($invoices->get($invoice1['id'])['status'] === 'paid', 'Invoice 1 should be paid');
    expectFinance($invoices->get($invoice2['id'])['status'] === 'paid', 'Invoice 2 should be paid');

    expectFinance($allocations->netForPayment($payment1['id']) === 100000, 'Payment 1 allocation total incorrect');
    expectFinance($allocations->netForPayment($payment2['id']) === 77000, 'Payment 2 allocation total incorrect');
    expectFinance($allocations->netForInvoice($invoice1['id']) === 118000, 'Invoice 1 allocation total incorrect');
    expectFinance($allocations->netForInvoice($invoice2['id']) === 59000, 'Invoice 2 allocation total incorrect');

    expectFinanceError(
        fn() => $allocations->allocate($payment1['id'], $invoice3['id'], 1),
        'Allocation exceeds unallocated payment balance'
    );

    $payment3 = $payments->create([
        'customer_id' => $customer['id'],
        'amount' => 1000,
        'method' => 'cash',
    ]);
    expectFinanceError(
        fn() => $allocations->allocate($payment3['id'], $invoice1['id'], 1),
        'Allocation exceeds invoice outstanding'
    );

    expectFinanceError(
        fn() => $payments->void($payment1['id'], 'Cannot void yet'),
        'Reverse payment allocations before voiding payment'
    );
    expectFinanceError(
        fn() => $invoices->void($invoice2['id'], 'Cannot void yet'),
        'Reverse payment allocations before voiding invoice'
    );

    $ageing = $receivables->ageing($customer['id']);
    expectFinance($ageing['buckets']['31_60']['count'] === 1, '45-day invoice should be in 31–60 ageing');
    expectFinance($ageing['buckets']['31_60']['amount_paise'] === 118000, '31–60 ageing amount incorrect');

    $reversal22 = $allocations->reverse($a22['id'], 'Allocation correction');
    expectFinance($reversal22['amount_paise'] === -29000, 'Reversal amount incorrect');
    expectFinance($invoices->get($invoice2['id'])['status'] === 'partially_paid', 'Invoice 2 should return to partially paid');

    $ageingAfter = $receivables->ageing($customer['id']);
    expectFinance($ageingAfter['buckets']['1_30']['amount_paise'] === 29000, '1–30 ageing amount incorrect after reversal');
    expectFinance($ageingAfter['buckets']['31_60']['amount_paise'] === 118000, '31–60 ageing changed unexpectedly');

    $summary = $receivables->invoiceSummary($invoice2['id']);
    expectFinance($summary['allocated_paise'] === 30000, 'Invoice 2 allocated amount after reversal incorrect');
    expectFinance($summary['outstanding_paise'] === 29000, 'Invoice 2 outstanding after reversal incorrect');

    $statement = $receivables->customerStatement($customer['id']);
    expectFinance(count($statement['entries']) === 6, 'Statement should contain three invoices and three payments');
    expectFinance($statement['statement_balance_paise'] === 18000, 'Statement balance incorrect');
    expectFinance($statement['invoice_outstanding_paise'] === 147000, 'Invoice outstanding total incorrect');
    expectFinance($statement['unallocated_credit_paise'] === 129000, 'Unallocated credit incorrect');

    $totals = $receivables->totals();
    expectFinance($totals['collected_paise'] === 277000, 'Collected total incorrect');
    expectFinance($totals['outstanding_paise'] === 147000, 'Outstanding total incorrect');
    expectFinance($totals['unallocated_credit_paise'] === 129000, 'Global unallocated credit incorrect');

    expectFinanceError(
        fn() => $allocations->reverse($a22['id'], 'Duplicate reversal'),
        'Allocation is already reversed'
    );

    $allocations->reverse($a12['id'], 'Remove remaining invoice 2 allocation');
    expectFinance($invoices->get($invoice2['id'])['status'] === 'issued', 'Invoice 2 should return to issued');
    $voidedInvoice = $invoices->void($invoice2['id'], 'Cancelled after allocation reversals');
    expectFinance($voidedInvoice['status'] === 'void', 'Invoice void failed after reversals');

    $voidedPayment3 = $payments->void($payment3['id'], 'Unused receipt correction');
    expectFinance($voidedPayment3['status'] === 'void', 'Unallocated payment void failed');

    $closedBatch = $batches->close($batch['id']);
    expectFinance($closedBatch['status'] === 'closed', 'Payment batch close failed');
    expectFinanceError(
        fn() => $payments->create([
            'customer_id' => $customer['id'],
            'batch_id' => $batch['id'],
            'amount' => 10,
            'method' => 'cash',
        ]),
        'Payment batch is closed'
    );

    $rebuilt = (new SearchRebuilder($store, $search))->rebuild();
    expectFinance(($rebuilt['payment_batches'] ?? 0) === 1, 'Payment batch search rebuild count incorrect');
    expectFinance(($rebuilt['payments'] ?? 0) === 3, 'Payment search rebuild count incorrect');
    expectFinance(count($search->search('UTR-ONE')) === 1, 'Payment reference not searchable after rebuild');

    $integrity = (new IntegrityVerifier($store, $root . '/audit'))->verify();
    expectFinance($integrity['ok'] === true, 'Finance integrity verification failed: ' . implode('; ', $integrity['errors']));
    expectFinance(($integrity['checked']['payment_allocations'] ?? 0) === 6, 'Allocation integrity count incorrect');

    echo "Phase 4 finance test passed\n";
} finally {
    cleanupFinance($root);
}

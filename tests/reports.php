<?php
declare(strict_types=1);

$root = sys_get_temp_dir() . '/ecrm-reports-' . bin2hex(random_bytes(6));
mkdir($root, 0770, true);
define('ECRM_PRIVATE_ROOT', $root);
require dirname(__DIR__) . '/app/bootstrap.php';

use Ecrm\Audit\AuditLedger;
use Ecrm\Domain\Customers\CustomerService;
use Ecrm\Domain\Finance\AllocationService;
use Ecrm\Domain\Finance\PaymentService;
use Ecrm\Domain\Finance\ReceivablesService;
use Ecrm\Domain\Reports\ReportingService;
use Ecrm\Domain\Sales\InvoiceService;
use Ecrm\Domain\Sales\QuotationService;
use Ecrm\Domain\Sales\SalesCalculator;
use Ecrm\Search\SearchIndex;
use Ecrm\Storage\AtomicJsonStore;
use Ecrm\Support\Sequence;

function expectReport(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function cleanupReport(string $dir): void
{
    if (!is_dir($dir)) return;
    foreach (scandir($dir) ?: [] as $file) {
        if ($file === '.' || $file === '..') continue;
        $path = $dir . '/' . $file;
        is_dir($path) ? cleanupReport($path) : unlink($path);
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
    $quotes = new QuotationService($store, $sequence, $search, $audit, $calculator);
    $invoices = new InvoiceService($store, $sequence, $search, $audit, $calculator, $root . '/locks');
    $payments = new PaymentService($store, $sequence, $search, $audit, $root . '/locks');
    $allocations = new AllocationService($store, $audit, $root . '/locks', $search);
    $receivables = new ReceivablesService($store, $allocations);
    $reports = new ReportingService($store, $receivables);

    $customerA = $customers->create(['name' => 'Report Customer A']);
    $customerB = $customers->create(['name' => 'Report Customer B']);

    $quote = $quotes->create([
        'customer_id' => $customerA['id'],
        'items' => [[
            'description' => 'Quoted item',
            'quantity' => 1,
            'rate' => 1000,
            'tax_percent' => 18,
        ]],
    ]);
    $quote = $quotes->changeStatus($quote['id'], 'approved');

    $invoice1 = $invoices->createFromQuotation($quote['id'], ['due_date' => gmdate('Y-m-d')]);
    $invoice1 = $invoices->issue($invoice1['id']);

    $invoice2 = $invoices->create([
        'customer_id' => $customerB['id'],
        'due_date' => gmdate('Y-m-d'),
        'tax_mode' => 'inter_state',
        'items' => [[
            'description' => 'Standalone item',
            'quantity' => 2,
            'rate' => 500,
            'tax_percent' => 18,
        ]],
    ]);
    $invoice2 = $invoices->issue($invoice2['id']);

    $payment1 = $payments->create([
        'customer_id' => $customerA['id'],
        'amount' => 500,
        'method' => 'neft',
        'reference' => 'RPT-UTR-1',
    ]);
    $payment2 = $payments->create([
        'customer_id' => $customerB['id'],
        'amount' => 1180,
        'method' => 'upi',
        'reference' => 'RPT-UPI-2',
    ]);

    $allocations->allocate($payment1['id'], $invoice1['id'], 500);
    $allocations->allocate($payment2['id'], $invoice2['id'], 1180);

    $sales = $reports->run('sales');
    expectReport($sales['summary']['invoice_count'] === 2, 'Sales invoice count incorrect');
    expectReport($sales['summary']['total_paise'] === 236000, 'Sales total incorrect');

    $collections = $reports->run('collections');
    expectReport($collections['summary']['receipt_count'] === 2, 'Collection receipt count incorrect');
    expectReport($collections['summary']['total_paise'] === 168000, 'Collection total incorrect');

    $outstanding = $reports->run('outstanding');
    expectReport($outstanding['summary']['invoice_count'] === 1, 'Outstanding invoice count incorrect');
    expectReport($outstanding['summary']['outstanding_paise'] === 68000, 'Outstanding amount incorrect');

    $ageing = $reports->run('ageing');
    expectReport($ageing['summary']['outstanding_paise'] === 68000, 'Ageing outstanding incorrect');

    $invoiceRegister = $reports->run('invoice_register');
    expectReport(count($invoiceRegister['rows']) === 2, 'Invoice register row count incorrect');
    expectReport($invoiceRegister['summary']['outstanding_paise'] === 68000, 'Invoice register outstanding incorrect');

    $paymentRegister = $reports->run('payment_register');
    expectReport($paymentRegister['summary']['total_paise'] === 168000, 'Payment register total incorrect');
    expectReport($paymentRegister['summary']['unallocated_paise'] === 0, 'Payment register unallocated amount incorrect');

    $mode = $reports->run('payment_mode_summary');
    expectReport(count($mode['rows']) === 2, 'Payment mode summary count incorrect');

    $tax = $reports->run('tax_summary');
    expectReport($tax['summary']['taxable_paise'] === 200000, 'Tax taxable total incorrect');
    expectReport($tax['summary']['tax_paise'] === 36000, 'Tax total incorrect');
    expectReport($tax['summary']['cgst_paise'] === 9000, 'CGST total incorrect');
    expectReport($tax['summary']['sgst_paise'] === 9000, 'SGST total incorrect');
    expectReport($tax['summary']['igst_paise'] === 18000, 'IGST total incorrect');

    $conversion = $reports->run('quotation_conversion');
    expectReport($conversion['summary']['quotation_count'] === 1, 'Quotation report count incorrect');
    expectReport($conversion['summary']['approved_count'] === 1, 'Approved quotation count incorrect');
    expectReport($conversion['summary']['invoiced_from_approved'] === 1, 'Quotation invoice conversion count incorrect');

    $customerSales = $reports->run('customer_sales');
    expectReport(count($customerSales['rows']) === 2, 'Customer sales row count incorrect');
    expectReport($customerSales['summary']['total_paise'] === 236000, 'Customer sales total incorrect');

    $monthly = $reports->run('monthly_sales');
    expectReport(count($monthly['rows']) === 1, 'Monthly sales month count incorrect');
    expectReport($monthly['summary']['invoice_count'] === 2, 'Monthly sales invoice count incorrect');

    $filtered = $reports->run('sales', ['customer_id' => $customerA['id']]);
    expectReport($filtered['summary']['invoice_count'] === 1, 'Customer-filtered sales count incorrect');
    expectReport($filtered['summary']['total_paise'] === 118000, 'Customer-filtered sales total incorrect');

    $date = gmdate('Y-m-d');
    $dateFiltered = $reports->run('collections', ['date_from' => $date, 'date_to' => $date]);
    expectReport($dateFiltered['summary']['receipt_count'] === 2, 'Date-filtered collection count incorrect');

    echo "Reports test passed\n";
} finally {
    cleanupReport($root);
}

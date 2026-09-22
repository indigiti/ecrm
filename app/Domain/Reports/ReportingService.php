<?php
declare(strict_types=1);

namespace Ecrm\Domain\Reports;

use DateTimeImmutable;
use Ecrm\Domain\Finance\ReceivablesService;
use Ecrm\Storage\AtomicJsonStore;
use InvalidArgumentException;

final class ReportingService
{
    public const REPORTS = [
        'sales',
        'collections',
        'outstanding',
        'ageing',
        'invoice_register',
        'payment_register',
        'payment_mode_summary',
        'tax_summary',
        'quotation_conversion',
        'customer_sales',
        'monthly_sales',
    ];

    public function __construct(
        private AtomicJsonStore $store,
        private ReceivablesService $receivables
    ) {}

    public function run(string $report, array $filters = []): array
    {
        $report = strtolower(trim($report));
        if (!in_array($report, self::REPORTS, true)) {
            throw new InvalidArgumentException('Unknown report');
        }

        $filters = $this->normalizeFilters($filters);

        return match ($report) {
            'sales' => $this->sales($filters),
            'collections' => $this->collections($filters),
            'outstanding' => $this->outstanding($filters),
            'ageing' => $this->ageing($filters),
            'invoice_register' => $this->invoiceRegister($filters),
            'payment_register' => $this->paymentRegister($filters),
            'payment_mode_summary' => $this->paymentModeSummary($filters),
            'tax_summary' => $this->taxSummary($filters),
            'quotation_conversion' => $this->quotationConversion($filters),
            'customer_sales' => $this->customerSales($filters),
            'monthly_sales' => $this->monthlySales($filters),
        };
    }

    private function sales(array $filters): array
    {
        $invoices = $this->filteredInvoices($filters);
        $rows = [];
        foreach ($invoices as $invoice) {
            $rows[] = [
                'date' => $this->dateOnly($invoice['issued_at'] ?? $invoice['created_at'] ?? null),
                'invoice' => $invoice['number'] ?? '',
                'customer' => $invoice['customer_snapshot']['name'] ?? '',
                'status' => $invoice['status'] ?? '',
                'taxable_paise' => (int) ($invoice['totals']['taxable_paise'] ?? 0),
                'tax_paise' => (int) ($invoice['totals']['tax_paise'] ?? 0),
                'total_paise' => (int) ($invoice['totals']['grand_total_paise'] ?? 0),
            ];
        }

        return $this->result('Sales', $rows, [
            'invoice_count' => count($rows),
            'taxable_paise' => array_sum(array_column($rows, 'taxable_paise')),
            'tax_paise' => array_sum(array_column($rows, 'tax_paise')),
            'total_paise' => array_sum(array_column($rows, 'total_paise')),
        ], $filters);
    }

    private function collections(array $filters): array
    {
        $payments = $this->filteredPayments($filters);
        $rows = [];
        foreach ($payments as $payment) {
            $customer = $this->store->get('customers', (string) ($payment['customer_id'] ?? ''));
            $rows[] = [
                'date' => $this->dateOnly($payment['received_at'] ?? $payment['created_at'] ?? null),
                'receipt' => $payment['number'] ?? '',
                'customer' => $customer['name'] ?? '',
                'method' => $payment['method'] ?? '',
                'reference' => $payment['reference'] ?? '',
                'amount_paise' => (int) ($payment['amount_paise'] ?? 0),
            ];
        }

        return $this->result('Collections', $rows, [
            'receipt_count' => count($rows),
            'total_paise' => array_sum(array_column($rows, 'amount_paise')),
        ], $filters);
    }

    private function outstanding(array $filters): array
    {
        $rows = $this->receivables->outstanding($filters['customer_id']);
        $rows = array_values(array_filter($rows, function(array $row) use ($filters): bool {
            return $this->dateMatches($row['due_date'] ?? null, $filters);
        }));

        return $this->result('Outstanding', $rows, [
            'invoice_count' => count($rows),
            'outstanding_paise' => array_sum(array_column($rows, 'outstanding_paise')),
        ], $filters);
    }

    private function ageing(array $filters): array
    {
        $ageing = $this->receivables->ageing($filters['customer_id']);
        $rows = [];
        foreach ($ageing['buckets'] as $key => $bucket) {
            $rows[] = [
                'bucket' => $bucket['label'],
                'bucket_key' => $key,
                'invoice_count' => $bucket['count'],
                'amount_paise' => $bucket['amount_paise'],
            ];
        }

        return $this->result('Ageing', $rows, [
            'outstanding_paise' => $ageing['total_outstanding_paise'],
            'as_of' => $ageing['as_of'],
        ], $filters);
    }

    private function invoiceRegister(array $filters): array
    {
        $rows = [];
        foreach ($this->filteredInvoices($filters) as $invoice) {
            $summary = $this->receivables->invoiceSummary((string) $invoice['id']);
            $rows[] = [
                'date' => $this->dateOnly($invoice['issued_at'] ?? $invoice['created_at'] ?? null),
                'invoice' => $invoice['number'] ?? '',
                'customer' => $invoice['customer_snapshot']['name'] ?? '',
                'gstin' => $invoice['customer_snapshot']['gstin'] ?? '',
                'status' => $invoice['status'] ?? '',
                'due_date' => $invoice['due_date'] ?? null,
                'total_paise' => (int) ($invoice['totals']['grand_total_paise'] ?? 0),
                'allocated_paise' => $summary['allocated_paise'],
                'outstanding_paise' => $summary['outstanding_paise'],
            ];
        }

        return $this->result('Invoice Register', $rows, [
            'invoice_count' => count($rows),
            'total_paise' => array_sum(array_column($rows, 'total_paise')),
            'outstanding_paise' => array_sum(array_column($rows, 'outstanding_paise')),
        ], $filters);
    }

    private function paymentRegister(array $filters): array
    {
        $rows = [];
        foreach ($this->filteredPayments($filters) as $payment) {
            $customer = $this->store->get('customers', (string) ($payment['customer_id'] ?? ''));
            $allocated = 0;
            foreach ($this->store->all('payment_allocations') as $allocation) {
                if (($allocation['payment_id'] ?? null) === ($payment['id'] ?? null)) {
                    $allocated += (int) ($allocation['amount_paise'] ?? 0);
                }
            }
            $rows[] = [
                'date' => $this->dateOnly($payment['received_at'] ?? $payment['created_at'] ?? null),
                'receipt' => $payment['number'] ?? '',
                'customer' => $customer['name'] ?? '',
                'method' => $payment['method'] ?? '',
                'reference' => $payment['reference'] ?? '',
                'amount_paise' => (int) ($payment['amount_paise'] ?? 0),
                'allocated_paise' => $allocated,
                'unallocated_paise' => max(0, (int) ($payment['amount_paise'] ?? 0) - $allocated),
            ];
        }

        return $this->result('Payment Register', $rows, [
            'receipt_count' => count($rows),
            'total_paise' => array_sum(array_column($rows, 'amount_paise')),
            'unallocated_paise' => array_sum(array_column($rows, 'unallocated_paise')),
        ], $filters);
    }

    private function paymentModeSummary(array $filters): array
    {
        $summary = [];
        foreach ($this->filteredPayments($filters) as $payment) {
            $method = (string) ($payment['method'] ?? 'other');
            $summary[$method] ??= ['method' => $method, 'receipt_count' => 0, 'amount_paise' => 0];
            $summary[$method]['receipt_count']++;
            $summary[$method]['amount_paise'] += (int) ($payment['amount_paise'] ?? 0);
        }
        ksort($summary);
        $rows = array_values($summary);

        return $this->result('Payment Mode Summary', $rows, [
            'receipt_count' => array_sum(array_column($rows, 'receipt_count')),
            'total_paise' => array_sum(array_column($rows, 'amount_paise')),
        ], $filters);
    }

    private function taxSummary(array $filters): array
    {
        $rows = [];
        $byRate = [];

        foreach ($this->filteredInvoices($filters) as $invoice) {
            foreach ($invoice['items'] ?? [] as $line) {
                $bps = (int) ($line['tax_bps'] ?? 0);
                $key = (string) $bps;
                $byRate[$key] ??= [
                    'tax_percent' => $bps / 100,
                    'taxable_paise' => 0,
                    'cgst_paise' => 0,
                    'sgst_paise' => 0,
                    'igst_paise' => 0,
                    'tax_paise' => 0,
                ];
                foreach (['taxable_paise','cgst_paise','sgst_paise','igst_paise','tax_paise'] as $field) {
                    $byRate[$key][$field] += (int) ($line[$field] ?? 0);
                }
            }
        }

        ksort($byRate, SORT_NUMERIC);
        $rows = array_values($byRate);

        return $this->result('Tax Summary', $rows, [
            'taxable_paise' => array_sum(array_column($rows, 'taxable_paise')),
            'cgst_paise' => array_sum(array_column($rows, 'cgst_paise')),
            'sgst_paise' => array_sum(array_column($rows, 'sgst_paise')),
            'igst_paise' => array_sum(array_column($rows, 'igst_paise')),
            'tax_paise' => array_sum(array_column($rows, 'tax_paise')),
        ], $filters);
    }

    private function quotationConversion(array $filters): array
    {
        $quotes = array_values(array_filter($this->store->all('quotations'), function(array $quote) use ($filters): bool {
            if ($filters['customer_id'] && ($quote['customer_id'] ?? null) !== $filters['customer_id']) return false;
            return $this->dateMatches($quote['created_at'] ?? null, $filters);
        }));

        $counts = [];
        foreach ($quotes as $quote) {
            $status = (string) ($quote['status'] ?? 'unknown');
            $counts[$status] = ($counts[$status] ?? 0) + 1;
        }

        $approvedIds = array_column(array_filter($quotes, static fn(array $q): bool => ($q['status'] ?? '') === 'approved'), 'id');
        $invoiced = 0;
        foreach ($this->store->all('invoices') as $invoice) {
            if (!empty($invoice['quotation_id']) && in_array($invoice['quotation_id'], $approvedIds, true) && ($invoice['status'] ?? '') !== 'void') {
                $invoiced++;
            }
        }

        $rows = [];
        foreach ($counts as $status => $count) $rows[] = ['status' => $status, 'count' => $count];

        return $this->result('Quotation Conversion', $rows, [
            'quotation_count' => count($quotes),
            'approved_count' => $counts['approved'] ?? 0,
            'invoiced_from_approved' => $invoiced,
        ], $filters);
    }

    private function customerSales(array $filters): array
    {
        $summary = [];
        foreach ($this->filteredInvoices($filters) as $invoice) {
            $customerId = (string) ($invoice['customer_id'] ?? '');
            $summary[$customerId] ??= [
                'customer_id' => $customerId,
                'customer' => $invoice['customer_snapshot']['name'] ?? '',
                'invoice_count' => 0,
                'total_paise' => 0,
            ];
            $summary[$customerId]['invoice_count']++;
            $summary[$customerId]['total_paise'] += (int) ($invoice['totals']['grand_total_paise'] ?? 0);
        }
        $rows = array_values($summary);
        usort($rows, static fn(array $a, array $b): int => $b['total_paise'] <=> $a['total_paise']);

        return $this->result('Customer Sales', $rows, [
            'customer_count' => count($rows),
            'invoice_count' => array_sum(array_column($rows, 'invoice_count')),
            'total_paise' => array_sum(array_column($rows, 'total_paise')),
        ], $filters);
    }

    private function monthlySales(array $filters): array
    {
        $summary = [];
        foreach ($this->filteredInvoices($filters) as $invoice) {
            $date = $this->dateOnly($invoice['issued_at'] ?? $invoice['created_at'] ?? null);
            $month = $date ? substr($date, 0, 7) : 'unknown';
            $summary[$month] ??= ['month' => $month, 'invoice_count' => 0, 'total_paise' => 0];
            $summary[$month]['invoice_count']++;
            $summary[$month]['total_paise'] += (int) ($invoice['totals']['grand_total_paise'] ?? 0);
        }
        ksort($summary);
        $rows = array_values($summary);

        return $this->result('Monthly Sales', $rows, [
            'month_count' => count($rows),
            'invoice_count' => array_sum(array_column($rows, 'invoice_count')),
            'total_paise' => array_sum(array_column($rows, 'total_paise')),
        ], $filters);
    }

    private function filteredInvoices(array $filters): array
    {
        return array_values(array_filter($this->store->all('invoices'), function(array $invoice) use ($filters): bool {
            if (!in_array($invoice['status'] ?? '', ['issued','partially_paid','paid'], true)) return false;
            if ($filters['customer_id'] && ($invoice['customer_id'] ?? null) !== $filters['customer_id']) return false;
            return $this->dateMatches($invoice['issued_at'] ?? $invoice['created_at'] ?? null, $filters);
        }));
    }

    private function filteredPayments(array $filters): array
    {
        return array_values(array_filter($this->store->all('payments'), function(array $payment) use ($filters): bool {
            if (($payment['status'] ?? '') !== 'posted') return false;
            if ($filters['customer_id'] && ($payment['customer_id'] ?? null) !== $filters['customer_id']) return false;
            return $this->dateMatches($payment['received_at'] ?? $payment['created_at'] ?? null, $filters);
        }));
    }

    private function normalizeFilters(array $filters): array
    {
        $from = trim((string) ($filters['date_from'] ?? ''));
        $to = trim((string) ($filters['date_to'] ?? ''));
        $customerId = trim((string) ($filters['customer_id'] ?? ''));

        foreach (['date_from' => $from, 'date_to' => $to] as $name => $value) {
            if ($value === '') continue;
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
            if (!$date || $date->format('Y-m-d') !== $value) {
                throw new InvalidArgumentException('Invalid ' . str_replace('_', ' ', $name));
            }
        }
        if ($from !== '' && $to !== '' && $from > $to) {
            throw new InvalidArgumentException('Date from cannot be after date to');
        }
        if ($customerId !== '' && !$this->store->get('customers', $customerId)) {
            throw new InvalidArgumentException('Customer not found');
        }

        return [
            'date_from' => $from !== '' ? $from : null,
            'date_to' => $to !== '' ? $to : null,
            'customer_id' => $customerId !== '' ? $customerId : null,
        ];
    }

    private function dateMatches(mixed $value, array $filters): bool
    {
        $date = $this->dateOnly($value);
        if ($date === null) return $filters['date_from'] === null && $filters['date_to'] === null;
        if ($filters['date_from'] && $date < $filters['date_from']) return false;
        if ($filters['date_to'] && $date > $filters['date_to']) return false;
        return true;
    }

    private function dateOnly(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '') return null;
        try {
            return (new DateTimeImmutable($value))->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    private function result(string $title, array $rows, array $summary, array $filters): array
    {
        return [
            'title' => $title,
            'filters' => $filters,
            'summary' => $summary,
            'rows' => $rows,
            'generated_at' => gmdate(DATE_ATOM),
        ];
    }
}

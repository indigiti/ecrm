<?php
declare(strict_types=1);

namespace Ecrm\Domain\Finance;

use DateTimeImmutable;
use DateTimeZone;
use Ecrm\Storage\AtomicJsonStore;
use InvalidArgumentException;

final class ReceivablesService
{
    public function __construct(
        private AtomicJsonStore $store,
        private AllocationService $allocations,
        private string $timezone = 'Asia/Kolkata'
    ) {}

    public function invoiceSummary(string $invoiceId): array
    {
        $invoice = $this->store->get('invoices', $invoiceId);
        if (!$invoice) throw new InvalidArgumentException('Invoice not found');

        $total = (int) ($invoice['totals']['grand_total_paise'] ?? 0);
        $allocated = $this->allocations->netForInvoice($invoiceId);
        $outstanding = max(0, $total - $allocated);

        return [
            'invoice_id' => $invoiceId,
            'number' => $invoice['number'] ?? '',
            'customer_id' => $invoice['customer_id'] ?? null,
            'status' => $invoice['status'] ?? '',
            'due_date' => $invoice['due_date'] ?? null,
            'total_paise' => $total,
            'allocated_paise' => $allocated,
            'outstanding_paise' => $outstanding,
            'ageing_bucket' => $this->ageingBucket($invoice['due_date'] ?? null, $outstanding),
        ];
    }

    public function outstanding(?string $customerId = null): array
    {
        $rows = [];

        foreach ($this->store->all('invoices') as $invoice) {
            if (!in_array($invoice['status'] ?? '', ['issued','partially_paid','paid'], true)) {
                continue;
            }
            if ($customerId !== null && ($invoice['customer_id'] ?? null) !== $customerId) {
                continue;
            }

            $summary = $this->invoiceSummary((string) $invoice['id']);
            if ($summary['outstanding_paise'] > 0) {
                $summary['customer_name'] = $invoice['customer_snapshot']['name'] ?? '';
                $summary['issued_at'] = $invoice['issued_at'] ?? null;
                $rows[] = $summary;
            }
        }

        usort($rows, static fn(array $a, array $b): int =>
            strcmp((string) ($a['due_date'] ?? '9999-12-31'), (string) ($b['due_date'] ?? '9999-12-31'))
        );

        return $rows;
    }

    public function ageing(?string $customerId = null): array
    {
        $buckets = [
            'current' => ['label' => 'Current', 'amount_paise' => 0, 'count' => 0, 'invoices' => []],
            '1_30' => ['label' => '1–30', 'amount_paise' => 0, 'count' => 0, 'invoices' => []],
            '31_60' => ['label' => '31–60', 'amount_paise' => 0, 'count' => 0, 'invoices' => []],
            '61_90' => ['label' => '61–90', 'amount_paise' => 0, 'count' => 0, 'invoices' => []],
            '90_plus' => ['label' => '90+', 'amount_paise' => 0, 'count' => 0, 'invoices' => []],
        ];

        foreach ($this->outstanding($customerId) as $row) {
            $key = $row['ageing_bucket'];
            $buckets[$key]['amount_paise'] += (int) $row['outstanding_paise'];
            $buckets[$key]['count']++;
            $buckets[$key]['invoices'][] = $row;
        }

        $total = array_sum(array_column($buckets, 'amount_paise'));

        return [
            'customer_id' => $customerId,
            'total_outstanding_paise' => $total,
            'buckets' => $buckets,
            'as_of' => $this->today()->format('Y-m-d'),
        ];
    }

    public function customerStatement(string $customerId): array
    {
        $customer = $this->store->get('customers', $customerId);
        if (!$customer) throw new InvalidArgumentException('Customer not found');

        $entries = [];

        foreach ($this->store->all('invoices') as $invoice) {
            if (($invoice['customer_id'] ?? null) !== $customerId) continue;
            if (!in_array($invoice['status'] ?? '', ['issued','partially_paid','paid'], true)) continue;

            $entries[] = [
                'at' => $invoice['issued_at'] ?? $invoice['created_at'] ?? '',
                'type' => 'invoice',
                'reference' => $invoice['number'] ?? '',
                'entity_id' => $invoice['id'],
                'description' => 'Invoice ' . ($invoice['number'] ?? ''),
                'debit_paise' => (int) ($invoice['totals']['grand_total_paise'] ?? 0),
                'credit_paise' => 0,
            ];
        }

        foreach ($this->store->all('payments') as $payment) {
            if (($payment['customer_id'] ?? null) !== $customerId) continue;
            if (($payment['status'] ?? '') !== 'posted') continue;

            $entries[] = [
                'at' => $payment['received_at'] ?? $payment['created_at'] ?? '',
                'type' => 'payment',
                'reference' => $payment['number'] ?? '',
                'entity_id' => $payment['id'],
                'description' => 'Payment ' . strtoupper(str_replace('_', ' ', (string) ($payment['method'] ?? ''))),
                'debit_paise' => 0,
                'credit_paise' => (int) ($payment['amount_paise'] ?? 0),
            ];
        }

        usort($entries, static function(array $a, array $b): int {
            $date = strcmp((string) $a['at'], (string) $b['at']);
            if ($date !== 0) return $date;
            if ($a['type'] === $b['type']) return strcmp((string) $a['reference'], (string) $b['reference']);
            return $a['type'] === 'invoice' ? -1 : 1;
        });

        $balance = 0;
        foreach ($entries as &$entry) {
            $balance += (int) $entry['debit_paise'] - (int) $entry['credit_paise'];
            $entry['balance_paise'] = $balance;
        }
        unset($entry);

        $outstanding = $this->outstanding($customerId);
        $unallocatedCredit = 0;
        foreach ($this->store->all('payments') as $payment) {
            if (($payment['customer_id'] ?? null) !== $customerId || ($payment['status'] ?? '') !== 'posted') continue;
            $unallocatedCredit += max(
                0,
                (int) $payment['amount_paise'] - $this->allocations->netForPayment((string) $payment['id'])
            );
        }

        return [
            'customer' => [
                'id' => $customer['id'],
                'number' => $customer['number'] ?? '',
                'name' => $customer['name'] ?? '',
            ],
            'entries' => $entries,
            'statement_balance_paise' => $balance,
            'invoice_outstanding_paise' => array_sum(array_column($outstanding, 'outstanding_paise')),
            'unallocated_credit_paise' => $unallocatedCredit,
            'as_of' => $this->today()->format('Y-m-d'),
        ];
    }

    public function totals(): array
    {
        $outstanding = $this->outstanding();
        $ageing = $this->ageing();

        $collected = 0;
        $unallocated = 0;
        foreach ($this->store->all('payments') as $payment) {
            if (($payment['status'] ?? '') !== 'posted') continue;
            $collected += (int) ($payment['amount_paise'] ?? 0);
            $unallocated += max(
                0,
                (int) ($payment['amount_paise'] ?? 0) - $this->allocations->netForPayment((string) $payment['id'])
            );
        }

        return [
            'collected_paise' => $collected,
            'outstanding_paise' => array_sum(array_column($outstanding, 'outstanding_paise')),
            'unallocated_credit_paise' => $unallocated,
            'overdue_paise' =>
                (int) $ageing['buckets']['1_30']['amount_paise'] +
                (int) $ageing['buckets']['31_60']['amount_paise'] +
                (int) $ageing['buckets']['61_90']['amount_paise'] +
                (int) $ageing['buckets']['90_plus']['amount_paise'],
        ];
    }

    private function ageingBucket(?string $dueDate, int $outstandingPaise): string
    {
        if ($outstandingPaise <= 0 || !$dueDate) return 'current';

        try {
            $due = new DateTimeImmutable($dueDate, new DateTimeZone($this->timezone));
        } catch (\Throwable) {
            return 'current';
        }

        $days = (int) $due->setTime(0, 0)->diff($this->today())->format('%r%a');
        if ($days <= 0) return 'current';
        if ($days <= 30) return '1_30';
        if ($days <= 60) return '31_60';
        if ($days <= 90) return '61_90';
        return '90_plus';
    }

    private function today(): DateTimeImmutable
    {
        return (new DateTimeImmutable('now', new DateTimeZone($this->timezone)))->setTime(0, 0);
    }
}

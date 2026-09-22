<?php
declare(strict_types=1);

namespace Ecrm\Domain\Finance;

use Ecrm\Audit\AuditLedger;
use Ecrm\Storage\AtomicJsonStore;
use Ecrm\Support\ExclusiveLock;
use Ecrm\Support\Money;
use Ecrm\Support\UuidV7;
use InvalidArgumentException;

final class AllocationService
{
    public function __construct(
        private AtomicJsonStore $store,
        private AuditLedger $audit,
        private string $lockRoot
    ) {}

    public function allocate(string $paymentId, string $invoiceId, mixed $amount): array
    {
        return $this->withLock(function () use ($paymentId, $invoiceId, $amount): array {
            $payment = $this->payment($paymentId);
            $invoice = $this->invoice($invoiceId);

            if (($payment['status'] ?? '') !== 'posted') {
                throw new InvalidArgumentException('Only posted payments can be allocated');
            }
            if (!in_array($invoice['status'] ?? '', ['issued','partially_paid','paid'], true)) {
                throw new InvalidArgumentException('Only issued invoices can receive payments');
            }
            if (($payment['customer_id'] ?? null) !== ($invoice['customer_id'] ?? null)) {
                throw new InvalidArgumentException('Payment and invoice customers do not match');
            }

            $amountPaise = is_array($amount) && array_key_exists('amount_paise', $amount)
                ? (int) $amount['amount_paise']
                : Money::toPaise(is_array($amount) ? ($amount['amount'] ?? 0) : $amount);
            if ($amountPaise <= 0) throw new InvalidArgumentException('Allocation amount must be greater than zero');

            $paymentAllocated = $this->netForPayment($paymentId);
            $paymentAvailable = (int) $payment['amount_paise'] - $paymentAllocated;
            if ($amountPaise > $paymentAvailable) {
                throw new InvalidArgumentException('Allocation exceeds unallocated payment balance');
            }

            $invoiceAllocated = $this->netForInvoice($invoiceId);
            $invoiceTotal = (int) ($invoice['totals']['grand_total_paise'] ?? 0);
            $invoiceOutstanding = $invoiceTotal - $invoiceAllocated;
            if ($amountPaise > $invoiceOutstanding) {
                throw new InvalidArgumentException('Allocation exceeds invoice outstanding');
            }

            $record = [
                'id' => UuidV7::generate(),
                'payment_id' => $paymentId,
                'invoice_id' => $invoiceId,
                'customer_id' => $payment['customer_id'],
                'amount_paise' => $amountPaise,
                'type' => 'allocation',
                'reversal_of' => null,
                'reason' => null,
                'created_at' => gmdate(DATE_ATOM),
            ];

            $this->store->put('payment_allocations', $record['id'], $record);
            $this->syncInvoiceStatus($invoiceId);
            $this->audit->append('payment.allocated', 'payment_allocation', $record['id'], [
                'payment_id' => $paymentId,
                'invoice_id' => $invoiceId,
                'amount_paise' => $amountPaise,
            ]);
            return $record;
        });
    }

    public function reverse(string $allocationId, string $reason): array
    {
        return $this->withLock(function () use ($allocationId, $reason): array {
            $original = $this->store->get('payment_allocations', $allocationId);
            if (!$original || ($original['type'] ?? '') !== 'allocation' || (int) ($original['amount_paise'] ?? 0) <= 0) {
                throw new InvalidArgumentException('Allocation not found');
            }

            foreach ($this->store->all('payment_allocations') as $row) {
                if (($row['reversal_of'] ?? null) === $allocationId) {
                    throw new InvalidArgumentException('Allocation is already reversed');
                }
            }

            $reason = trim($reason);
            if ($reason === '') throw new InvalidArgumentException('Reversal reason is required');

            $record = [
                'id' => UuidV7::generate(),
                'payment_id' => $original['payment_id'],
                'invoice_id' => $original['invoice_id'],
                'customer_id' => $original['customer_id'],
                'amount_paise' => -abs((int) $original['amount_paise']),
                'type' => 'reversal',
                'reversal_of' => $allocationId,
                'reason' => $reason,
                'created_at' => gmdate(DATE_ATOM),
            ];

            $this->store->put('payment_allocations', $record['id'], $record);
            $this->syncInvoiceStatus((string) $record['invoice_id']);
            $this->audit->append('payment.allocation_reversed', 'payment_allocation', $record['id'], [
                'reversal_of' => $allocationId,
                'reason' => $reason,
            ]);
            return $record;
        });
    }

    public function forPayment(string $paymentId): array
    {
        return $this->store->where('payment_allocations', 'payment_id', $paymentId);
    }

    public function forInvoice(string $invoiceId): array
    {
        return $this->store->where('payment_allocations', 'invoice_id', $invoiceId);
    }

    public function netForPayment(string $paymentId): int
    {
        $total = 0;
        foreach ($this->forPayment($paymentId) as $row) {
            $total += (int) ($row['amount_paise'] ?? 0);
        }
        return $total;
    }

    public function netForInvoice(string $invoiceId): int
    {
        $total = 0;
        foreach ($this->forInvoice($invoiceId) as $row) {
            $total += (int) ($row['amount_paise'] ?? 0);
        }
        return $total;
    }

    private function syncInvoiceStatus(string $invoiceId): void
    {
        $invoice = $this->invoice($invoiceId);
        if (($invoice['status'] ?? '') === 'void') return;

        $total = (int) ($invoice['totals']['grand_total_paise'] ?? 0);
        $allocated = $this->netForInvoice($invoiceId);

        if ($allocated <= 0) {
            $status = empty($invoice['issued_at']) ? 'draft' : 'issued';
        } elseif ($allocated >= $total) {
            $status = 'paid';
        } else {
            $status = 'partially_paid';
        }

        if (($invoice['status'] ?? '') !== $status) {
            $invoice['status'] = $status;
            $invoice['updated_at'] = gmdate(DATE_ATOM);
            $this->store->put('invoices', $invoiceId, $invoice);
            $this->audit->append('invoice.receivable_status_changed', 'invoice', $invoiceId, [
                'status' => $status,
                'allocated_paise' => $allocated,
            ]);
        }
    }

    private function payment(string $id): array
    {
        $record = $this->store->get('payments', $id);
        if (!$record) throw new InvalidArgumentException('Payment not found');
        return $record;
    }

    private function invoice(string $id): array
    {
        $record = $this->store->get('invoices', $id);
        if (!$record) throw new InvalidArgumentException('Invoice not found');
        return $record;
    }

    private function withLock(callable $callback): mixed
    {
        return (new ExclusiveLock($this->lockRoot, 'finance-allocation'))->run($callback);
    }
}

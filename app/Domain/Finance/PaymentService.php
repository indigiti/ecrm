<?php
declare(strict_types=1);

namespace Ecrm\Domain\Finance;

use DateTimeImmutable;
use DateTimeZone;
use Ecrm\Audit\AuditLedger;
use Ecrm\Domain\Admin\SettingsService;
use Ecrm\Search\SearchIndex;
use Ecrm\Storage\AtomicJsonStore;
use Ecrm\Support\ExclusiveLock;
use Ecrm\Support\Money;
use Ecrm\Support\Sequence;
use Ecrm\Support\UuidV7;
use InvalidArgumentException;

final class PaymentService
{
    public const METHODS = [
        'cash','upi','neft','rtgs','imps','cheque','card',
        'bank_transfer','credit_note','other'
    ];

    public function __construct(
        private AtomicJsonStore $store,
        private Sequence $sequence,
        private SearchIndex $search,
        private AuditLedger $audit,
        private ?string $lockRoot = null,
        private ?SettingsService $settings = null
    ) {}

    public function create(array $input): array
    {
        return $this->withFinanceLock(fn(): array => $this->createUnlocked($input));
    }

    private function createUnlocked(array $input): array
    {
        $customerId = trim((string) ($input['customer_id'] ?? ''));
        if ($customerId === '' || !$this->store->get('customers', $customerId)) {
            throw new InvalidArgumentException('Customer is required');
        }

        $amountPaise = array_key_exists('amount_paise', $input)
            ? (int) $input['amount_paise']
            : Money::toPaise($input['amount'] ?? 0);
        if ($amountPaise <= 0) throw new InvalidArgumentException('Payment amount must be greater than zero');

        $method = strtolower(trim((string) ($input['method'] ?? '')));
        if (!in_array($method, self::METHODS, true)) {
            throw new InvalidArgumentException('Invalid payment method');
        }

        $batchId = trim((string) ($input['batch_id'] ?? ''));
        if ($batchId !== '') {
            $batch = $this->store->get('payment_batches', $batchId);
            if (!$batch) throw new InvalidArgumentException('Payment batch not found');
            if (($batch['status'] ?? '') !== 'open') throw new InvalidArgumentException('Payment batch is closed');
            if (!empty($batch['customer_id']) && $batch['customer_id'] !== $customerId) {
                throw new InvalidArgumentException('Payment customer does not match batch customer');
            }
        }

        $year = gmdate('Y');
        $settings = $this->settings?->get() ?? [];
        $prefix = (string) (($settings['payment_prefix'] ?? null) ?: 'PAY');
        $now = gmdate(DATE_ATOM);
        $record = [
            'id' => UuidV7::generate(),
            'number' => $this->sequence->next('payments-' . $year, $prefix . '-' . $year . '-'),
            'customer_id' => $customerId,
            'batch_id' => $batchId !== '' ? $batchId : null,
            'amount_paise' => $amountPaise,
            'method' => $method,
            'reference' => trim((string) ($input['reference'] ?? '')),
            'method_meta' => $this->methodMeta($input['method_meta'] ?? []),
            'received_at' => $this->normalizeReceivedAt($input['received_at'] ?? null),
            'notes' => trim((string) ($input['notes'] ?? '')),
            'status' => 'posted',
            'void_reason' => null,
            'voided_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $this->store->put('payments', $record['id'], $record);
        $this->index($record);
        $this->audit->append('payment.created', 'payment', $record['id'], [
            'number' => $record['number'],
            'amount_paise' => $amountPaise,
            'method' => $method,
        ]);
        return $record;
    }

    public function void(string $id, string $reason): array
    {
        return $this->withFinanceLock(function () use ($id, $reason): array {
            $record = $this->get($id);
            if ($record['status'] === 'void') return $record;

            $allocated = 0;
            foreach ($this->store->all('payment_allocations') as $allocation) {
                if (($allocation['payment_id'] ?? null) === $id) {
                    $allocated += (int) ($allocation['amount_paise'] ?? 0);
                }
            }
            if ($allocated !== 0) {
                throw new InvalidArgumentException('Reverse payment allocations before voiding payment');
            }

            $voidReason = trim($reason);
            if ($voidReason === '') throw new InvalidArgumentException('Void reason is required');

            $record['status'] = 'void';
            $record['void_reason'] = $voidReason;
            $record['voided_at'] = gmdate(DATE_ATOM);
            $record['updated_at'] = gmdate(DATE_ATOM);
            $this->store->put('payments', $id, $record);
            $this->index($record);
            $this->audit->append('payment.voided', 'payment', $id, ['reason' => $voidReason]);
            return $record;
        });
    }

    public function get(string $id): array
    {
        $record = $this->store->get('payments', $id);
        if (!$record) throw new InvalidArgumentException('Payment not found');
        return $record;
    }

    public function all(): array
    {
        return $this->store->all('payments');
    }

    private function methodMeta(mixed $input): array
    {
        if (!is_array($input)) return [];

        $meta = [];
        foreach ($input as $key => $value) {
            if (!is_scalar($value) && $value !== null) continue;
            $key = preg_replace('/[^a-zA-Z0-9_-]+/', '_', (string) $key) ?: 'field';
            $meta[$key] = trim((string) ($value ?? ''));
        }
        return $meta;
    }

    private function normalizeReceivedAt(mixed $value): string
    {
        $value = trim((string) ($value ?? ''));
        try {
            $date = $value === ''
                ? new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata'))
                : new DateTimeImmutable($value, new DateTimeZone('Asia/Kolkata'));
        } catch (\Throwable) {
            throw new InvalidArgumentException('Invalid received date/time');
        }

        return $date->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM);
    }

    private function withFinanceLock(callable $callback): mixed
    {
        if ($this->lockRoot === null || $this->lockRoot === '') {
            return $callback();
        }
        return (new ExclusiveLock($this->lockRoot, 'finance-allocation'))->run($callback);
    }

    private function index(array $record): void
    {
        $customer = $this->store->get('customers', (string) $record['customer_id']);
        $meta = implode(' ', array_map('strval', array_filter($record['method_meta'], 'is_scalar')));

        $this->search->upsert('payment', $record['id'], $record['number'], [
            $customer['name'] ?? '',
            $customer['mobile'] ?? '',
            $record['method'],
            $record['reference'],
            $meta,
            $record['status'],
        ], [
            'number' => $record['number'],
            'customer_id' => $record['customer_id'],
            'amount_paise' => $record['amount_paise'],
            'method' => $record['method'],
            'status' => $record['status'],
        ]);
    }
}

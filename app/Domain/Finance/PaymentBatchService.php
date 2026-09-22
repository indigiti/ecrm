<?php
declare(strict_types=1);

namespace Ecrm\Domain\Finance;

use Ecrm\Audit\AuditLedger;
use Ecrm\Search\SearchIndex;
use Ecrm\Storage\AtomicJsonStore;
use Ecrm\Support\Sequence;
use Ecrm\Support\UuidV7;
use InvalidArgumentException;

final class PaymentBatchService
{
    public function __construct(
        private AtomicJsonStore $store,
        private Sequence $sequence,
        private SearchIndex $search,
        private AuditLedger $audit
    ) {}

    public function create(array $input): array
    {
        $customerId = trim((string) ($input['customer_id'] ?? ''));
        if ($customerId !== '' && !$this->store->get('customers', $customerId)) {
            throw new InvalidArgumentException('Customer not found');
        }

        $year = gmdate('Y');
        $now = gmdate(DATE_ATOM);
        $record = [
            'id' => UuidV7::generate(),
            'number' => $this->sequence->next('payment-batches-' . $year, 'PBAT-' . $year . '-'),
            'customer_id' => $customerId !== '' ? $customerId : null,
            'title' => trim((string) ($input['title'] ?? 'Payment Batch')),
            'batch_date' => $input['batch_date'] ?? gmdate('Y-m-d'),
            'notes' => trim((string) ($input['notes'] ?? '')),
            'status' => 'open',
            'closed_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $this->store->put('payment_batches', $record['id'], $record);
        $this->index($record);
        $this->audit->append('payment_batch.created', 'payment_batch', $record['id'], ['number' => $record['number']]);
        return $record;
    }

    public function close(string $id): array
    {
        $record = $this->get($id);
        if ($record['status'] === 'closed') return $record;

        $record['status'] = 'closed';
        $record['closed_at'] = gmdate(DATE_ATOM);
        $record['updated_at'] = gmdate(DATE_ATOM);
        $this->store->put('payment_batches', $id, $record);
        $this->index($record);
        $this->audit->append('payment_batch.closed', 'payment_batch', $id);
        return $record;
    }

    public function get(string $id): array
    {
        $record = $this->store->get('payment_batches', $id);
        if (!$record) throw new InvalidArgumentException('Payment batch not found');
        return $record;
    }

    public function all(): array
    {
        return $this->store->all('payment_batches');
    }

    private function index(array $record): void
    {
        $this->search->upsert('payment_batch', $record['id'], $record['number'], [
            $record['title'], $record['batch_date'], $record['status']
        ], [
            'number' => $record['number'],
            'customer_id' => $record['customer_id'],
            'status' => $record['status'],
        ]);
    }
}

<?php
declare(strict_types=1);

namespace Ecrm\Domain\Customers;

use Ecrm\Audit\AuditLedger;
use Ecrm\Search\SearchIndex;
use Ecrm\Storage\AtomicJsonStore;
use Ecrm\Support\Sequence;
use Ecrm\Support\UuidV7;
use InvalidArgumentException;

final class CustomerService
{
    public function __construct(
        private AtomicJsonStore $store,
        private Sequence $sequence,
        private SearchIndex $search,
        private AuditLedger $audit
    ) {}

    public function create(array $input): array
    {
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('Customer name is required');
        }

        $email = trim((string) ($input['email'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Invalid email address');
        }

        $now = gmdate(DATE_ATOM);
        $record = [
            'id' => UuidV7::generate(),
            'number' => $this->sequence->next('customers', 'CUST-'),
            'name' => $name,
            'contact_person' => trim((string) ($input['contact_person'] ?? '')),
            'mobile' => trim((string) ($input['mobile'] ?? '')),
            'whatsapp' => trim((string) ($input['whatsapp'] ?? '')),
            'email' => $email,
            'gstin' => strtoupper(trim((string) ($input['gstin'] ?? ''))),
            'pan' => strtoupper(trim((string) ($input['pan'] ?? ''))),
            'category' => trim((string) ($input['category'] ?? '')),
            'status' => 'active',
            'tags' => array_values(array_unique(array_filter(array_map('strval', $input['tags'] ?? [])))),
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $this->store->put('customers', $record['id'], $record);
        $this->index($record);
        $this->audit->append('customer.created', 'customer', $record['id'], ['number' => $record['number']]);
        return $record;
    }

    public function update(string $id, array $input): array
    {
        $record = $this->get($id);
        foreach (['name', 'contact_person', 'mobile', 'whatsapp', 'email', 'gstin', 'pan', 'category', 'status'] as $field) {
            if (array_key_exists($field, $input)) {
                $record[$field] = is_string($input[$field]) ? trim($input[$field]) : $input[$field];
            }
        }
        if (isset($input['tags']) && is_array($input['tags'])) {
            $record['tags'] = array_values(array_unique(array_filter(array_map('strval', $input['tags']))));
        }
        if (($record['name'] ?? '') === '') {
            throw new InvalidArgumentException('Customer name is required');
        }
        if (!in_array((string) ($record['status'] ?? 'active'), ['active', 'inactive', 'archived'], true)) {
            throw new InvalidArgumentException('Invalid customer status');
        }
        if (($record['email'] ?? '') !== '' && !filter_var($record['email'], FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Invalid email address');
        }
        $record['updated_at'] = gmdate(DATE_ATOM);
        $this->store->put('customers', $id, $record);
        $this->index($record);
        $this->audit->append('customer.updated', 'customer', $id);
        return $record;
    }

    public function get(string $id): array
    {
        $record = $this->store->get('customers', $id);
        if (!$record) {
            throw new InvalidArgumentException('Customer not found');
        }
        return $record;
    }

    public function all(): array
    {
        return $this->store->all('customers');
    }

    private function index(array $record): void
    {
        $this->search->upsert('customer', $record['id'], $record['name'], [
            $record['number'], $record['contact_person'], $record['mobile'], $record['whatsapp'],
            $record['email'], $record['gstin'], $record['pan'], $record['category'],
            implode(' ', $record['tags'] ?? []),
        ], ['number' => $record['number'], 'status' => $record['status']]);
    }
}

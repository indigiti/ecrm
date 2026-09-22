<?php
declare(strict_types=1);

namespace Ecrm\Domain\Customers;

use Ecrm\Audit\AuditLedger;
use Ecrm\Search\SearchIndex;
use Ecrm\Storage\AtomicJsonStore;
use Ecrm\Support\UuidV7;
use InvalidArgumentException;

final class ContactService
{
    public function __construct(
        private AtomicJsonStore $store,
        private SearchIndex $search,
        private AuditLedger $audit
    ) {}

    public function create(string $customerId, array $input): array
    {
        if (!$this->store->get('customers', $customerId)) {
            throw new InvalidArgumentException('Customer not found');
        }

        $now = gmdate(DATE_ATOM);
        $record = [
            'id' => UuidV7::generate(),
            'customer_id' => $customerId,
            'name' => trim((string) ($input['name'] ?? '')),
            'designation' => trim((string) ($input['designation'] ?? '')),
            'mobile' => trim((string) ($input['mobile'] ?? '')),
            'whatsapp' => trim((string) ($input['whatsapp'] ?? '')),
            'email' => trim((string) ($input['email'] ?? '')),
            'is_primary' => (bool) ($input['is_primary'] ?? false),
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $this->validate($record);
        $this->store->put('contacts', $record['id'], $record);
        $this->index($record);
        $this->audit->append('contact.created', 'contact', $record['id'], ['customer_id' => $customerId]);
        return $record;
    }

    public function update(string $id, array $input): array
    {
        $record = $this->get($id);

        foreach (['name', 'designation', 'mobile', 'whatsapp', 'email'] as $field) {
            if (array_key_exists($field, $input)) {
                $record[$field] = trim((string) $input[$field]);
            }
        }
        if (array_key_exists('is_primary', $input)) {
            $record['is_primary'] = filter_var($input['is_primary'], FILTER_VALIDATE_BOOL);
        }

        $this->validate($record);
        $record['updated_at'] = gmdate(DATE_ATOM);
        $this->store->put('contacts', $id, $record);
        $this->index($record);
        $this->audit->append('contact.updated', 'contact', $id);
        return $record;
    }

    public function get(string $id): array
    {
        $record = $this->store->get('contacts', $id);
        if (!$record) {
            throw new InvalidArgumentException('Contact not found');
        }
        return $record;
    }

    public function forCustomer(string $customerId): array
    {
        return $this->store->where('contacts', 'customer_id', $customerId);
    }

    private function validate(array $record): void
    {
        if (trim((string) ($record['name'] ?? '')) === '') {
            throw new InvalidArgumentException('Contact name is required');
        }
        if (($record['email'] ?? '') !== '' && !filter_var($record['email'], FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Invalid email address');
        }
    }

    private function index(array $record): void
    {
        $this->search->upsert('contact', $record['id'], $record['name'], [
            $record['mobile'], $record['whatsapp'], $record['email'], $record['designation']
        ], ['customer_id' => $record['customer_id']]);
    }
}

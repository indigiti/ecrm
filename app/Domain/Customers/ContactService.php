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

        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('Contact name is required');
        }

        $record = [
            'id' => UuidV7::generate(),
            'customer_id' => $customerId,
            'name' => $name,
            'designation' => trim((string) ($input['designation'] ?? '')),
            'mobile' => trim((string) ($input['mobile'] ?? '')),
            'whatsapp' => trim((string) ($input['whatsapp'] ?? '')),
            'email' => trim((string) ($input['email'] ?? '')),
            'is_primary' => (bool) ($input['is_primary'] ?? false),
            'created_at' => gmdate(DATE_ATOM),
        ];

        $this->store->put('contacts', $record['id'], $record);
        $this->search->upsert('contact', $record['id'], $record['name'], [
            $record['mobile'], $record['whatsapp'], $record['email'], $record['designation']
        ], ['customer_id' => $customerId]);
        $this->audit->append('contact.created', 'contact', $record['id'], ['customer_id' => $customerId]);
        return $record;
    }

    public function forCustomer(string $customerId): array
    {
        return $this->store->where('contacts', 'customer_id', $customerId);
    }
}

<?php
declare(strict_types=1);

namespace Ecrm\Search;

use Ecrm\Storage\AtomicJsonStore;

final class SearchRebuilder
{
    public function __construct(
        private AtomicJsonStore $store,
        private SearchIndex $index
    ) {}

    public function rebuild(): array
    {
        $entries = [];

        foreach ($this->store->all('customers') as $row) {
            $entries[] = [
                'type' => 'customer',
                'id' => $row['id'],
                'label' => $row['name'],
                'terms' => [
                    $row['number'] ?? '',
                    $row['contact_person'] ?? '',
                    $row['mobile'] ?? '',
                    $row['whatsapp'] ?? '',
                    $row['email'] ?? '',
                    $row['gstin'] ?? '',
                    $row['pan'] ?? '',
                    $row['category'] ?? '',
                    implode(' ', $row['tags'] ?? []),
                ],
                'meta' => [
                    'number' => $row['number'] ?? null,
                    'status' => $row['status'] ?? null,
                ],
            ];
        }

        foreach ($this->store->all('contacts') as $row) {
            $entries[] = [
                'type' => 'contact',
                'id' => $row['id'],
                'label' => $row['name'],
                'terms' => [
                    $row['designation'] ?? '',
                    $row['mobile'] ?? '',
                    $row['whatsapp'] ?? '',
                    $row['email'] ?? '',
                ],
                'meta' => ['customer_id' => $row['customer_id'] ?? null],
            ];
        }

        foreach ($this->store->all('addresses') as $row) {
            $entries[] = [
                'type' => 'address',
                'id' => $row['id'],
                'label' => $row['label'] ?: ucfirst((string) ($row['type'] ?? 'Address')),
                'terms' => [
                    $row['address'] ?? '',
                    $row['area'] ?? '',
                    $row['landmark'] ?? '',
                    $row['city'] ?? '',
                    $row['state'] ?? '',
                    $row['pin'] ?? '',
                    $row['country'] ?? '',
                ],
                'meta' => [
                    'customer_id' => $row['customer_id'] ?? null,
                    'type' => $row['type'] ?? null,
                ],
            ];
        }

        foreach ($this->store->all('leads') as $row) {
            $entries[] = [
                'type' => 'lead',
                'id' => $row['id'],
                'label' => $row['name'],
                'terms' => [
                    $row['number'] ?? '',
                    $row['company'] ?? '',
                    $row['mobile'] ?? '',
                    $row['email'] ?? '',
                    $row['source'] ?? '',
                    $row['requirement'] ?? '',
                    $row['stage'] ?? '',
                ],
                'meta' => [
                    'number' => $row['number'] ?? null,
                    'stage' => $row['stage'] ?? null,
                    'customer_id' => $row['customer_id'] ?? null,
                ],
            ];
        }

        $count = $this->index->replaceAll($entries);

        return [
            'indexed' => $count,
            'customers' => count($this->store->all('customers')),
            'contacts' => count($this->store->all('contacts')),
            'addresses' => count($this->store->all('addresses')),
            'leads' => count($this->store->all('leads')),
        ];
    }
}

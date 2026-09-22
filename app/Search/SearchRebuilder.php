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

        foreach ($this->store->all('products') as $row) {
            $entries[] = [
                'type' => 'product',
                'id' => $row['id'],
                'label' => $row['name'],
                'terms' => [
                    $row['code'] ?? '',
                    $row['sku'] ?? '',
                    $row['category'] ?? '',
                    $row['subcategory'] ?? '',
                    $row['brand'] ?? '',
                    $row['model'] ?? '',
                    $row['description'] ?? '',
                    $row['hsn_sac'] ?? '',
                ],
                'meta' => [
                    'code' => $row['code'] ?? null,
                    'status' => $row['status'] ?? null,
                    'selling_price_paise' => $row['selling_price_paise'] ?? 0,
                ],
            ];
        }

        foreach ($this->store->all('quotations') as $row) {
            $entries[] = [
                'type' => 'quotation',
                'id' => $row['id'],
                'label' => $row['number'],
                'terms' => [
                    $row['customer_snapshot']['name'] ?? '',
                    $row['customer_snapshot']['mobile'] ?? '',
                    $row['customer_snapshot']['gstin'] ?? '',
                    $row['status'] ?? '',
                ],
                'meta' => [
                    'number' => $row['number'] ?? null,
                    'status' => $row['status'] ?? null,
                    'customer_id' => $row['customer_id'] ?? null,
                    'grand_total_paise' => $row['totals']['grand_total_paise'] ?? 0,
                ],
            ];
        }

        foreach ($this->store->all('invoices') as $row) {
            $entries[] = [
                'type' => 'invoice',
                'id' => $row['id'],
                'label' => $row['number'],
                'terms' => [
                    $row['customer_snapshot']['name'] ?? '',
                    $row['customer_snapshot']['mobile'] ?? '',
                    $row['customer_snapshot']['gstin'] ?? '',
                    $row['status'] ?? '',
                ],
                'meta' => [
                    'number' => $row['number'] ?? null,
                    'status' => $row['status'] ?? null,
                    'customer_id' => $row['customer_id'] ?? null,
                    'grand_total_paise' => $row['totals']['grand_total_paise'] ?? 0,
                ],
            ];
        }

        foreach ($this->store->all('locations') as $row) {
            $entries[] = [
                'type' => 'location',
                'id' => $row['id'],
                'label' => $row['name'],
                'terms' => [
                    $row['code'] ?? '',
                    $row['type'] ?? '',
                    $row['address'] ?? '',
                    $row['contact_name'] ?? '',
                    $row['contact_mobile'] ?? '',
                    $row['status'] ?? '',
                ],
                'meta' => [
                    'code' => $row['code'] ?? null,
                    'type' => $row['type'] ?? null,
                    'parent_id' => $row['parent_id'] ?? null,
                    'status' => $row['status'] ?? null,
                ],
            ];
        }

        foreach ($this->store->all('product_units') as $row) {
            $product = $this->store->get('products', (string) ($row['product_id'] ?? ''));
            $entries[] = [
                'type' => 'product_unit',
                'id' => $row['id'],
                'label' => $row['serial_no'],
                'terms' => [
                    $product['name'] ?? '',
                    $product['code'] ?? '',
                    $product['sku'] ?? '',
                    $row['batch_no'] ?? '',
                    $row['qr_token'] ?? '',
                    $row['lifecycle_status'] ?? '',
                ],
                'meta' => [
                    'product_id' => $row['product_id'] ?? null,
                    'product_code' => $product['code'] ?? null,
                    'lifecycle_status' => $row['lifecycle_status'] ?? null,
                ],
            ];
        }

        foreach ($this->store->all('payment_batches') as $row) {
            $entries[] = [
                'type' => 'payment_batch',
                'id' => $row['id'],
                'label' => $row['number'],
                'terms' => [
                    $row['title'] ?? '',
                    $row['batch_date'] ?? '',
                    $row['status'] ?? '',
                ],
                'meta' => [
                    'number' => $row['number'] ?? null,
                    'customer_id' => $row['customer_id'] ?? null,
                    'status' => $row['status'] ?? null,
                ],
            ];
        }

        foreach ($this->store->all('payments') as $row) {
            $customer = $this->store->get('customers', (string) ($row['customer_id'] ?? ''));
            $metaTerms = is_array($row['method_meta'] ?? null)
                ? implode(' ', array_map('strval', array_filter($row['method_meta'], 'is_scalar')))
                : '';

            $entries[] = [
                'type' => 'payment',
                'id' => $row['id'],
                'label' => $row['number'],
                'terms' => [
                    $customer['name'] ?? '',
                    $customer['mobile'] ?? '',
                    $row['method'] ?? '',
                    $row['reference'] ?? '',
                    $metaTerms,
                    $row['status'] ?? '',
                ],
                'meta' => [
                    'number' => $row['number'] ?? null,
                    'customer_id' => $row['customer_id'] ?? null,
                    'amount_paise' => $row['amount_paise'] ?? 0,
                    'method' => $row['method'] ?? null,
                    'status' => $row['status'] ?? null,
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
            'products' => count($this->store->all('products')),
            'quotations' => count($this->store->all('quotations')),
            'invoices' => count($this->store->all('invoices')),
            'locations' => count($this->store->all('locations')),
            'product_units' => count($this->store->all('product_units')),
            'inventory_movements' => count($this->store->all('inventory_movements')),
            'payment_batches' => count($this->store->all('payment_batches')),
            'payments' => count($this->store->all('payments')),
        ];
    }
}

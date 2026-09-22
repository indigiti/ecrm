<?php
declare(strict_types=1);

namespace Ecrm\Domain\Sales;

use Ecrm\Audit\AuditLedger;
use Ecrm\Search\SearchIndex;
use Ecrm\Storage\AtomicJsonStore;
use Ecrm\Support\Sequence;
use Ecrm\Support\UuidV7;
use InvalidArgumentException;

final class InvoiceService
{
    public const STATUSES = ['draft','issued','partially_paid','paid','void'];

    public function __construct(
        private AtomicJsonStore $store,
        private Sequence $sequence,
        private SearchIndex $search,
        private AuditLedger $audit,
        private SalesCalculator $calculator
    ) {}

    public function create(array $input): array
    {
        $customer = $this->customer((string) ($input['customer_id'] ?? ''));
        $address = $this->address($input['address_id'] ?? null, $customer['id']);
        $calculated = $this->calculator->calculate(
            is_array($input['items'] ?? null) ? $input['items'] : [],
            (string) ($input['tax_mode'] ?? 'intra_state'),
            $input['round_off'] ?? 0
        );

        return $this->persistNew([
            'quotation_id' => null,
            'customer' => $customer,
            'address' => $address,
            'calculated' => $calculated,
            'due_date' => $input['due_date'] ?? null,
            'terms' => trim((string) ($input['terms'] ?? '')),
            'notes' => trim((string) ($input['notes'] ?? '')),
        ]);
    }

    public function createFromQuotation(string $quotationId, array $input = []): array
    {
        foreach ($this->store->all('invoices') as $invoice) {
            if (($invoice['quotation_id'] ?? null) === $quotationId && ($invoice['status'] ?? '') !== 'void') {
                return $invoice;
            }
        }

        $quote = $this->store->get('quotations', $quotationId);
        if (!$quote) throw new InvalidArgumentException('Quotation not found');
        if (($quote['status'] ?? '') !== 'approved') {
            throw new InvalidArgumentException('Only approved quotation can be invoiced');
        }

        $customer = $this->customer((string) $quote['customer_id']);
        $address = $this->address($quote['address_id'] ?? null, $customer['id']);

        $record = $this->persistNew([
            'quotation_id' => $quotationId,
            'customer' => $customer,
            'address' => $address,
            'calculated' => [
                'tax_mode' => $quote['tax_mode'],
                'items' => $quote['items'],
                'totals' => $quote['totals'],
            ],
            'due_date' => $input['due_date'] ?? null,
            'terms' => trim((string) ($input['terms'] ?? $quote['payment_terms'] ?? '')),
            'notes' => trim((string) ($input['notes'] ?? '')),
        ]);

        $this->audit->append('invoice.created_from_quotation', 'invoice', $record['id'], ['quotation_id' => $quotationId]);
        return $record;
    }

    public function update(string $id, array $input): array
    {
        $record = $this->get($id);
        if (($record['status'] ?? '') !== 'draft') {
            throw new InvalidArgumentException('Issued invoice financial snapshot is immutable');
        }

        if (array_key_exists('customer_id', $input) || array_key_exists('address_id', $input)) {
            $customer = $this->customer((string) ($input['customer_id'] ?? $record['customer_id']));
            $addressId = array_key_exists('address_id', $input) ? $input['address_id'] : $record['address_id'];
            $address = $this->address($addressId, $customer['id']);
            $record['customer_id'] = $customer['id'];
            $record['address_id'] = $address['id'] ?? null;
            $record['customer_snapshot'] = $this->customerSnapshot($customer);
            $record['address_snapshot'] = $address ? $this->addressSnapshot($address) : null;
        }

        if (array_key_exists('items', $input) || array_key_exists('tax_mode', $input) || array_key_exists('round_off', $input)) {
            $sourceItems = is_array($input['items'] ?? null) ? $input['items'] : $record['items'];
            $taxMode = (string) ($input['tax_mode'] ?? $record['tax_mode']);
            $roundOff = array_key_exists('round_off', $input)
                ? $input['round_off']
                : (($record['totals']['round_off_paise'] ?? 0) / 100);
            $calculated = $this->calculator->calculate($sourceItems, $taxMode, $roundOff);
            $record['items'] = $calculated['items'];
            $record['tax_mode'] = $calculated['tax_mode'];
            $record['totals'] = $calculated['totals'];
        }

        if (array_key_exists('due_date', $input)) $record['due_date'] = $input['due_date'] ?: null;
        foreach (['terms','notes'] as $field) {
            if (array_key_exists($field, $input)) $record[$field] = trim((string) $input[$field]);
        }

        $record['updated_at'] = gmdate(DATE_ATOM);
        $this->store->put('invoices', $id, $record);
        $this->index($record);
        $this->audit->append('invoice.updated', 'invoice', $id);
        return $record;
    }

    public function issue(string $id): array
    {
        $record = $this->get($id);
        if (($record['status'] ?? '') !== 'draft') {
            throw new InvalidArgumentException('Only draft invoice can be issued');
        }

        $record['status'] = 'issued';
        $record['issued_at'] = gmdate(DATE_ATOM);
        $record['updated_at'] = gmdate(DATE_ATOM);
        $this->store->put('invoices', $id, $record);
        $this->index($record);
        $this->audit->append('invoice.issued', 'invoice', $id, [
            'number' => $record['number'],
            'grand_total_paise' => $record['totals']['grand_total_paise'] ?? 0,
        ]);
        return $record;
    }

    public function void(string $id, string $reason): array
    {
        $record = $this->get($id);

        $allocated = 0;
        foreach ($this->store->all('payment_allocations') as $allocation) {
            if (($allocation['invoice_id'] ?? null) === $id) {
                $allocated += (int) ($allocation['amount_paise'] ?? 0);
            }
        }
        if ($allocated !== 0) {
            throw new InvalidArgumentException('Reverse payment allocations before voiding invoice');
        }

        if (!in_array($record['status'] ?? '', ['draft','issued'], true)) {
            throw new InvalidArgumentException('Invoice cannot be voided in current status');
        }
        $reason = trim($reason);
        if ($reason === '') throw new InvalidArgumentException('Void reason is required');

        $record['status'] = 'void';
        $record['void_reason'] = $reason;
        $record['voided_at'] = gmdate(DATE_ATOM);
        $record['updated_at'] = gmdate(DATE_ATOM);
        $this->store->put('invoices', $id, $record);
        $this->index($record);
        $this->audit->append('invoice.voided', 'invoice', $id, ['reason' => $reason]);
        return $record;
    }

    public function get(string $id): array
    {
        $record = $this->store->get('invoices', $id);
        if (!$record) throw new InvalidArgumentException('Invoice not found');
        return $record;
    }

    public function all(): array
    {
        return $this->store->all('invoices');
    }

    private function persistNew(array $source): array
    {
        $customer = $source['customer'];
        $address = $source['address'];
        $calculated = $source['calculated'];
        $year = gmdate('Y');
        $now = gmdate(DATE_ATOM);

        $record = [
            'id' => UuidV7::generate(),
            'number' => $this->sequence->next('invoices-' . $year, 'INV-' . $year . '-'),
            'quotation_id' => $source['quotation_id'] ?: null,
            'customer_id' => $customer['id'],
            'address_id' => $address['id'] ?? null,
            'customer_snapshot' => $this->customerSnapshot($customer),
            'address_snapshot' => $address ? $this->addressSnapshot($address) : null,
            'items' => $calculated['items'],
            'tax_mode' => $calculated['tax_mode'],
            'totals' => $calculated['totals'],
            'due_date' => $source['due_date'] ?: null,
            'terms' => $source['terms'],
            'notes' => $source['notes'],
            'status' => 'draft',
            'issued_at' => null,
            'voided_at' => null,
            'void_reason' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $this->store->put('invoices', $record['id'], $record);
        $this->index($record);
        $this->audit->append('invoice.created', 'invoice', $record['id'], ['number' => $record['number']]);
        return $record;
    }

    private function customer(string $id): array
    {
        if ($id === '') throw new InvalidArgumentException('Customer is required');
        $customer = $this->store->get('customers', $id);
        if (!$customer) throw new InvalidArgumentException('Customer not found');
        return $customer;
    }

    private function address(mixed $id, string $customerId): ?array
    {
        $id = trim((string) ($id ?? ''));
        if ($id === '') return null;
        $address = $this->store->get('addresses', $id);
        if (!$address) throw new InvalidArgumentException('Address not found');
        if (($address['customer_id'] ?? null) !== $customerId) {
            throw new InvalidArgumentException('Address does not belong to customer');
        }
        return $address;
    }

    private function customerSnapshot(array $customer): array
    {
        return [
            'number' => $customer['number'] ?? null,
            'name' => $customer['name'] ?? '',
            'contact_person' => $customer['contact_person'] ?? '',
            'mobile' => $customer['mobile'] ?? '',
            'email' => $customer['email'] ?? '',
            'gstin' => $customer['gstin'] ?? '',
            'pan' => $customer['pan'] ?? '',
        ];
    }

    private function addressSnapshot(array $address): array
    {
        return [
            'type' => $address['type'] ?? '',
            'label' => $address['label'] ?? '',
            'address' => $address['address'] ?? '',
            'area' => $address['area'] ?? '',
            'city' => $address['city'] ?? '',
            'state' => $address['state'] ?? '',
            'pin' => $address['pin'] ?? '',
            'country' => $address['country'] ?? '',
        ];
    }

    private function index(array $record): void
    {
        $this->search->upsert('invoice', $record['id'], $record['number'], [
            $record['customer_snapshot']['name'] ?? '',
            $record['customer_snapshot']['mobile'] ?? '',
            $record['customer_snapshot']['gstin'] ?? '',
            $record['status'],
        ], [
            'number' => $record['number'],
            'status' => $record['status'],
            'customer_id' => $record['customer_id'],
            'grand_total_paise' => $record['totals']['grand_total_paise'] ?? 0,
        ]);
    }
}

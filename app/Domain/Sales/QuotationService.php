<?php
declare(strict_types=1);

namespace Ecrm\Domain\Sales;

use Ecrm\Audit\AuditLedger;
use Ecrm\Domain\Admin\SettingsService;
use Ecrm\Search\SearchIndex;
use Ecrm\Storage\AtomicJsonStore;
use Ecrm\Support\Sequence;
use Ecrm\Support\UuidV7;
use InvalidArgumentException;

final class QuotationService
{
    public const STATUSES = ['draft','sent','viewed','revised','approved','rejected','expired'];

    public function __construct(
        private AtomicJsonStore $store,
        private Sequence $sequence,
        private SearchIndex $search,
        private AuditLedger $audit,
        private SalesCalculator $calculator,
        private ?SettingsService $settings = null
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

        $year = gmdate('Y');
        $prefix = (string) (($this->settings?->get()['quote_prefix'] ?? null) ?: 'QUO');
        $now = gmdate(DATE_ATOM);
        $record = [
            'id' => UuidV7::generate(),
            'number' => $this->sequence->next('quotations-' . $year, $prefix . '-' . $year . '-'),
            'customer_id' => $customer['id'],
            'address_id' => $address['id'] ?? null,
            'customer_snapshot' => $this->customerSnapshot($customer),
            'address_snapshot' => $address ? $this->addressSnapshot($address) : null,
            'items' => $calculated['items'],
            'tax_mode' => $calculated['tax_mode'],
            'totals' => $calculated['totals'],
            'terms' => trim((string) ($input['terms'] ?? '')),
            'payment_terms' => trim((string) ($input['payment_terms'] ?? '')),
            'delivery_terms' => trim((string) ($input['delivery_terms'] ?? '')),
            'valid_until' => $input['valid_until'] ?? null,
            'notes' => trim((string) ($input['notes'] ?? '')),
            'status' => 'draft',
            'version' => 1,
            'approved_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $this->store->put('quotations', $record['id'], $record);
        $this->index($record);
        $this->audit->append('quotation.created', 'quotation', $record['id'], ['number' => $record['number']]);
        return $record;
    }

    public function update(string $id, array $input): array
    {
        $record = $this->get($id);
        if (in_array($record['status'], ['approved','rejected','expired'], true)) {
            throw new InvalidArgumentException('Final quotation cannot be edited');
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

        foreach (['terms','payment_terms','delivery_terms','notes'] as $field) {
            if (array_key_exists($field, $input)) $record[$field] = trim((string) $input[$field]);
        }
        if (array_key_exists('valid_until', $input)) $record['valid_until'] = $input['valid_until'] ?: null;

        if ($record['status'] !== 'draft') $record['status'] = 'revised';
        $record['version'] = (int) $record['version'] + 1;
        $record['updated_at'] = gmdate(DATE_ATOM);

        $this->store->put('quotations', $id, $record);
        $this->index($record);
        $this->audit->append('quotation.updated', 'quotation', $id, ['version' => $record['version']]);
        return $record;
    }

    public function changeStatus(string $id, string $status): array
    {
        $record = $this->get($id);
        $status = strtolower(trim($status));
        if (!in_array($status, self::STATUSES, true)) throw new InvalidArgumentException('Invalid quotation status');

        $allowed = [
            'draft' => ['sent','approved','rejected'],
            'sent' => ['viewed','revised','approved','rejected','expired'],
            'viewed' => ['revised','approved','rejected','expired'],
            'revised' => ['sent','approved','rejected'],
            'approved' => [],
            'rejected' => [],
            'expired' => ['revised'],
        ];

        if ($status === $record['status']) return $record;
        if (!in_array($status, $allowed[$record['status']] ?? [], true)) {
            throw new InvalidArgumentException('Invalid quotation status transition');
        }

        $record['status'] = $status;
        if ($status === 'approved') $record['approved_at'] = gmdate(DATE_ATOM);
        $record['updated_at'] = gmdate(DATE_ATOM);

        $this->store->put('quotations', $id, $record);
        $this->index($record);
        $this->audit->append('quotation.status_changed', 'quotation', $id, ['status' => $status]);
        return $record;
    }

    public function get(string $id): array
    {
        $record = $this->store->get('quotations', $id);
        if (!$record) throw new InvalidArgumentException('Quotation not found');
        return $record;
    }

    public function all(): array
    {
        return $this->store->all('quotations');
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
        $this->search->upsert('quotation', $record['id'], $record['number'], [
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

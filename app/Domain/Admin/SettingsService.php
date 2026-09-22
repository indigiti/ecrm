<?php
declare(strict_types=1);

namespace Ecrm\Domain\Admin;

use Ecrm\Audit\AuditLedger;
use Ecrm\Storage\AtomicJsonStore;
use Ecrm\Support\Money;
use InvalidArgumentException;

final class SettingsService
{
    public function __construct(
        private AtomicJsonStore $store,
        private AuditLedger $audit
    ) {}

    public function get(): array
    {
        return $this->store->get('company', 'primary') ?? $this->defaults();
    }

    public function update(array $input): array
    {
        $record = $this->get();

        foreach ([
            'company_name','legal_name','address','city','state','pin','country',
            'phone','email','website','gstin','pan','bank_name','bank_account_name',
            'bank_account_number','bank_ifsc','upi_id','quote_prefix','invoice_prefix',
            'payment_prefix','currency','quotation_terms','invoice_terms','footer_text',
            'logo_document_id','signature_document_id'
        ] as $field) {
            if (array_key_exists($field, $input)) {
                $record[$field] = trim((string) $input[$field]);
            }
        }

        foreach (['quote_prefix','invoice_prefix','payment_prefix'] as $field) {
            if (!array_key_exists($field, $input)) continue;
            $value = strtoupper(trim((string) $input[$field]));
            if (!preg_match('/^[A-Z0-9][A-Z0-9-]{0,11}$/', $value)) {
                throw new InvalidArgumentException('Invalid ' . str_replace('_', ' ', $field));
            }
            $record[$field] = $value;
        }

        if (array_key_exists('financial_year_start_month', $input)) {
            $month = (int) $input['financial_year_start_month'];
            if ($month < 1 || $month > 12) throw new InvalidArgumentException('Financial year start month must be between 1 and 12');
            $record['financial_year_start_month'] = $month;
        }

        if (array_key_exists('default_tax_percent', $input)) {
            $record['default_tax_bps'] = Money::toBps($input['default_tax_percent']);
        }

        if ($record['company_name'] === '') throw new InvalidArgumentException('Company name is required');
        if ($record['email'] !== '' && !filter_var($record['email'], FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Company email is invalid');
        }
        if ($record['currency'] === '') $record['currency'] = 'INR';

        $record['id'] = 'primary';
        $record['updated_at'] = gmdate(DATE_ATOM);
        if (empty($record['created_at'])) $record['created_at'] = $record['updated_at'];

        $this->store->put('company', 'primary', $record);
        $this->audit->append('settings.company_updated', 'settings', 'primary');
        return $record;
    }

    private function defaults(): array
    {
        return [
            'id' => 'primary',
            'company_name' => 'eCRM',
            'legal_name' => '',
            'address' => '',
            'city' => '',
            'state' => '',
            'pin' => '',
            'country' => 'India',
            'phone' => '',
            'email' => '',
            'website' => '',
            'gstin' => '',
            'pan' => '',
            'bank_name' => '',
            'bank_account_name' => '',
            'bank_account_number' => '',
            'bank_ifsc' => '',
            'upi_id' => '',
            'quote_prefix' => 'QUO',
            'invoice_prefix' => 'INV',
            'payment_prefix' => 'PAY',
            'financial_year_start_month' => 4,
            'currency' => 'INR',
            'default_tax_bps' => 1800,
            'quotation_terms' => '',
            'invoice_terms' => '',
            'footer_text' => '',
            'logo_document_id' => '',
            'signature_document_id' => '',
            'created_at' => null,
            'updated_at' => null,
        ];
    }
}

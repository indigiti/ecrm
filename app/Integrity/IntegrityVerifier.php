<?php
declare(strict_types=1);

namespace Ecrm\Integrity;

use Ecrm\Storage\AtomicJsonStore;

final class IntegrityVerifier
{
    public function __construct(
        private AtomicJsonStore $store,
        private string $auditRoot
    ) {}

    public function verify(): array
    {
        $errors = [];
        $warnings = [];
        $checked = [];

        $collections = [
            'customers' => $this->map('customers'),
            'contacts' => $this->map('contacts'),
            'addresses' => $this->map('addresses'),
            'leads' => $this->map('leads'),
            'activities' => $this->map('activities'),
            'products' => $this->map('products'),
            'quotations' => $this->map('quotations'),
            'invoices' => $this->map('invoices'),
        ];

        foreach ($collections as $name => $rows) {
            $checked[$name] = count($rows);
            foreach ($rows as $id => $row) {
                if (!$this->isUuidV7($id)) $errors[] = "{$name}: invalid UUIDv7 {$id}";
                if (($row['id'] ?? null) !== $id) $errors[] = "{$name}: record ID mismatch {$id}";
            }
        }

        $customers = $collections['customers'];
        $contacts = $collections['contacts'];
        $addresses = $collections['addresses'];
        $leads = $collections['leads'];
        $activities = $collections['activities'];
        $products = $collections['products'];
        $quotations = $collections['quotations'];
        $invoices = $collections['invoices'];

        $this->checkUniqueField($customers, 'number', 'customers', $errors);
        $this->checkUniqueField($leads, 'number', 'leads', $errors);
        $this->checkUniqueField($products, 'code', 'products', $errors);
        $this->checkUniqueField($quotations, 'number', 'quotations', $errors);
        $this->checkUniqueField($invoices, 'number', 'invoices', $errors);

        foreach ($contacts as $id => $row) {
            $customerId = (string) ($row['customer_id'] ?? '');
            if ($customerId === '' || !isset($customers[$customerId])) {
                $errors[] = "contacts: {$id} references missing customer {$customerId}";
            }
        }

        foreach ($addresses as $id => $row) {
            $customerId = (string) ($row['customer_id'] ?? '');
            if ($customerId === '' || !isset($customers[$customerId])) {
                $errors[] = "addresses: {$id} references missing customer {$customerId}";
            }

            $lat = $row['latitude'] ?? null;
            $lon = $row['longitude'] ?? null;
            $status = (string) ($row['geocode_status'] ?? '');
            $token = $row['geocode_token'] ?? null;

            if (($lat === null) !== ($lon === null)) $errors[] = "addresses: {$id} has partial coordinates";
            if ($status === 'resolved' && ($lat === null || $lon === null)) $errors[] = "addresses: {$id} is resolved without coordinates";
            if ($status === 'pending' && ($lat !== null || $lon !== null)) $errors[] = "addresses: {$id} is pending with coordinates";
            if ($status === 'pending' && ($token === null || $token === '')) $errors[] = "addresses: {$id} is pending without geocode token";
            if ($status === 'resolved' && $token !== null) $warnings[] = "addresses: {$id} is resolved but still has a geocode token";
        }

        foreach ($leads as $id => $row) {
            $customerId = $row['customer_id'] ?? null;
            if ($customerId !== null && $customerId !== '' && !isset($customers[(string) $customerId])) {
                $errors[] = "leads: {$id} references missing customer {$customerId}";
            }
            if ($customerId && ($row['stage'] ?? '') !== 'won') {
                $errors[] = "leads: {$id} is converted but not in Won stage";
            }
            if (($row['stage'] ?? '') === 'won' && !$customerId) {
                $warnings[] = "leads: {$id} is Won without a linked customer";
            }
        }

        foreach ($activities as $id => $row) {
            $customerId = $row['customer_id'] ?? null;
            $leadId = $row['lead_id'] ?? null;
            if (!$customerId && !$leadId) {
                $errors[] = "activities: {$id} has no customer or lead";
                continue;
            }
            if ($customerId && !isset($customers[(string) $customerId])) {
                $errors[] = "activities: {$id} references missing customer {$customerId}";
            }
            if ($leadId && !isset($leads[(string) $leadId])) {
                $errors[] = "activities: {$id} references missing lead {$leadId}";
            }
        }

        foreach ($quotations as $id => $row) {
            $this->checkSalesRecord('quotations', $id, $row, $customers, $addresses, $products, $errors);
            if (($row['status'] ?? '') === 'approved' && empty($row['approved_at'])) {
                $errors[] = "quotations: {$id} is approved without approved_at";
            }
        }

        foreach ($invoices as $id => $row) {
            $this->checkSalesRecord('invoices', $id, $row, $customers, $addresses, $products, $errors);
            $quotationId = $row['quotation_id'] ?? null;
            if ($quotationId && !isset($quotations[(string) $quotationId])) {
                $errors[] = "invoices: {$id} references missing quotation {$quotationId}";
            }
            if (in_array($row['status'] ?? '', ['issued','partially_paid','paid'], true) && empty($row['issued_at'])) {
                $errors[] = "invoices: {$id} is issued/paid without issued_at";
            }
            if (($row['status'] ?? '') === 'void' && trim((string) ($row['void_reason'] ?? '')) === '') {
                $errors[] = "invoices: {$id} is void without reason";
            }
        }

        $audit = $this->verifyAudit();
        $checked['audit_events'] = $audit['count'];
        array_push($errors, ...$audit['errors']);

        return [
            'ok' => $errors === [],
            'checked' => $checked,
            'errors' => $errors,
            'warnings' => $warnings,
            'verified_at' => gmdate(DATE_ATOM),
        ];
    }

    private function checkSalesRecord(
        string $collection,
        string $id,
        array $row,
        array $customers,
        array $addresses,
        array $products,
        array &$errors
    ): void {
        $customerId = (string) ($row['customer_id'] ?? '');
        if ($customerId === '' || !isset($customers[$customerId])) {
            $errors[] = "{$collection}: {$id} references missing customer {$customerId}";
        }

        $addressId = $row['address_id'] ?? null;
        if ($addressId) {
            if (!isset($addresses[(string) $addressId])) {
                $errors[] = "{$collection}: {$id} references missing address {$addressId}";
            } elseif (($addresses[(string) $addressId]['customer_id'] ?? null) !== $customerId) {
                $errors[] = "{$collection}: {$id} address does not belong to customer";
            }
        }

        $sum = [
            'gross_paise' => 0,
            'discount_paise' => 0,
            'taxable_paise' => 0,
            'cgst_paise' => 0,
            'sgst_paise' => 0,
            'igst_paise' => 0,
            'tax_paise' => 0,
        ];

        $items = is_array($row['items'] ?? null) ? $row['items'] : [];
        if ($items === []) $errors[] = "{$collection}: {$id} has no line items";

        foreach ($items as $line) {
            $productId = $line['product_id'] ?? null;
            if ($productId && !isset($products[(string) $productId])) {
                $errors[] = "{$collection}: {$id} line references missing product {$productId}";
            }

            $taxable = (int) ($line['taxable_paise'] ?? 0);
            $tax = (int) ($line['tax_paise'] ?? 0);
            $splitTax = (int) ($line['cgst_paise'] ?? 0) + (int) ($line['sgst_paise'] ?? 0) + (int) ($line['igst_paise'] ?? 0);
            if ($splitTax !== $tax) $errors[] = "{$collection}: {$id} line tax split mismatch";
            if ((int) ($line['line_total_paise'] ?? 0) !== $taxable + $tax) {
                $errors[] = "{$collection}: {$id} line total mismatch";
            }

            $gross = (int) round((float) ($line['quantity'] ?? 0) * (int) ($line['rate_paise'] ?? 0), 0, PHP_ROUND_HALF_UP);
            $sum['gross_paise'] += $gross;
            foreach (['discount_paise','taxable_paise','cgst_paise','sgst_paise','igst_paise','tax_paise'] as $field) {
                $sum[$field] += (int) ($line[$field] ?? 0);
            }
        }

        $totals = is_array($row['totals'] ?? null) ? $row['totals'] : [];
        foreach ($sum as $field => $expected) {
            if ((int) ($totals[$field] ?? 0) !== $expected) {
                $errors[] = "{$collection}: {$id} total {$field} mismatch";
            }
        }

        $expectedGrand = $sum['taxable_paise'] + $sum['tax_paise'] + (int) ($totals['round_off_paise'] ?? 0);
        if ((int) ($totals['grand_total_paise'] ?? 0) !== $expectedGrand) {
            $errors[] = "{$collection}: {$id} grand total mismatch";
        }
    }

    private function map(string $collection): array
    {
        $mapped = [];
        foreach ($this->store->all($collection) as $row) {
            $id = (string) ($row['id'] ?? '');
            if ($id !== '') $mapped[$id] = $row;
        }
        return $mapped;
    }

    private function isUuidV7(string $id): bool
    {
        return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $id);
    }

    private function checkUniqueField(array $rows, string $field, string $collection, array &$errors): void
    {
        $seen = [];
        foreach ($rows as $id => $row) {
            $value = trim((string) ($row[$field] ?? ''));
            if ($value === '') {
                $errors[] = "{$collection}: {$id} has no {$field}";
                continue;
            }
            if (isset($seen[$value])) $errors[] = "{$collection}: duplicate {$field} {$value}";
            $seen[$value] = true;
        }
    }

    private function verifyAudit(): array
    {
        $path = rtrim($this->auditRoot, '/') . '/events.jsonl';
        if (!is_file($path)) return ['count' => 0, 'errors' => []];

        $errors = [];
        $previousHash = '';
        $count = 0;

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $lineNumber => $line) {
            $count++;
            $event = json_decode($line, true);
            if (!is_array($event)) {
                $errors[] = 'audit: invalid JSON at line ' . ($lineNumber + 1);
                continue;
            }
            if (($event['previous_hash'] ?? '') !== $previousHash) {
                $errors[] = 'audit: broken previous_hash at line ' . ($lineNumber + 1);
            }

            $storedHash = (string) ($event['hash'] ?? '');
            $payload = $event;
            unset($payload['hash']);
            $expectedHash = hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            if ($storedHash === '' || !hash_equals($expectedHash, $storedHash)) {
                $errors[] = 'audit: hash mismatch at line ' . ($lineNumber + 1);
            }
            $previousHash = $storedHash;
        }

        return ['count' => $count, 'errors' => $errors];
    }
}

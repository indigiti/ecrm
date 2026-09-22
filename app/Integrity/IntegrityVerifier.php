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

        $customers = $this->map('customers');
        $contacts = $this->map('contacts');
        $addresses = $this->map('addresses');
        $leads = $this->map('leads');
        $activities = $this->map('activities');

        foreach ([
            'customers' => $customers,
            'contacts' => $contacts,
            'addresses' => $addresses,
            'leads' => $leads,
            'activities' => $activities,
        ] as $name => $rows) {
            $checked[$name] = count($rows);
            foreach ($rows as $id => $row) {
                if (!$this->isUuidV7($id)) {
                    $errors[] = "{$name}: invalid UUIDv7 {$id}";
                }
                if (($row['id'] ?? null) !== $id) {
                    $errors[] = "{$name}: record ID mismatch {$id}";
                }
            }
        }

        $this->checkUniqueNumbers($customers, 'customers', $errors);
        $this->checkUniqueNumbers($leads, 'leads', $errors);

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

            if (($lat === null) !== ($lon === null)) {
                $errors[] = "addresses: {$id} has partial coordinates";
            }
            if ($status === 'resolved' && ($lat === null || $lon === null)) {
                $errors[] = "addresses: {$id} is resolved without coordinates";
            }
            if ($status === 'pending' && ($lat !== null || $lon !== null)) {
                $errors[] = "addresses: {$id} is pending with coordinates";
            }
            if ($status === 'pending' && ($token === null || $token === '')) {
                $errors[] = "addresses: {$id} is pending without geocode token";
            }
            if ($status === 'resolved' && $token !== null) {
                $warnings[] = "addresses: {$id} is resolved but still has a geocode token";
            }
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

    private function map(string $collection): array
    {
        $mapped = [];
        foreach ($this->store->all($collection) as $row) {
            $id = (string) ($row['id'] ?? '');
            if ($id !== '') {
                $mapped[$id] = $row;
            }
        }
        return $mapped;
    }

    private function isUuidV7(string $id): bool
    {
        return (bool) preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $id
        );
    }

    private function checkUniqueNumbers(array $rows, string $collection, array &$errors): void
    {
        $seen = [];
        foreach ($rows as $id => $row) {
            $number = trim((string) ($row['number'] ?? ''));
            if ($number === '') {
                $errors[] = "{$collection}: {$id} has no business number";
                continue;
            }
            if (isset($seen[$number])) {
                $errors[] = "{$collection}: duplicate business number {$number}";
            }
            $seen[$number] = true;
        }
    }

    private function verifyAudit(): array
    {
        $path = rtrim($this->auditRoot, '/') . '/events.jsonl';
        if (!is_file($path)) {
            return ['count' => 0, 'errors' => []];
        }

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

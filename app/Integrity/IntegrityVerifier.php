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
            'locations' => $this->map('locations'),
            'product_units' => $this->map('product_units'),
            'inventory_movements' => $this->map('inventory_movements'),
            'workshop_jobs' => $this->map('workshop_jobs'),
            'quotations' => $this->map('quotations'),
            'invoices' => $this->map('invoices'),
            'payment_batches' => $this->map('payment_batches'),
            'payments' => $this->map('payments'),
            'payment_allocations' => $this->map('payment_allocations'),
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
        $locations = $collections['locations'];
        $productUnits = $collections['product_units'];
        $inventoryMovements = $collections['inventory_movements'];
        $workshopJobs = $collections['workshop_jobs'];
        $quotations = $collections['quotations'];
        $invoices = $collections['invoices'];
        $paymentBatches = $collections['payment_batches'];
        $payments = $collections['payments'];
        $paymentAllocations = $collections['payment_allocations'];

        $this->checkUniqueField($customers, 'number', 'customers', $errors);
        $this->checkUniqueField($leads, 'number', 'leads', $errors);
        $this->checkUniqueField($products, 'code', 'products', $errors);
        $this->checkUniqueField($locations, 'code', 'locations', $errors);
        $this->checkUniqueField($workshopJobs, 'number', 'workshop_jobs', $errors);
        $this->checkUniqueField($quotations, 'number', 'quotations', $errors);
        $this->checkUniqueField($invoices, 'number', 'invoices', $errors);
        $this->checkUniqueField($paymentBatches, 'number', 'payment_batches', $errors);
        $this->checkUniqueField($payments, 'number', 'payments', $errors);

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

        foreach ($paymentBatches as $id => $row) {
            $customerId = $row['customer_id'] ?? null;
            if ($customerId && !isset($customers[(string) $customerId])) {
                $errors[] = "payment_batches: {$id} references missing customer {$customerId}";
            }
            if (!in_array($row['status'] ?? '', ['open','closed'], true)) {
                $errors[] = "payment_batches: {$id} has invalid status";
            }
            if (($row['status'] ?? '') === 'closed' && empty($row['closed_at'])) {
                $errors[] = "payment_batches: {$id} is closed without closed_at";
            }
        }

        $paymentNet = array_fill_keys(array_keys($payments), 0);
        $invoiceNet = array_fill_keys(array_keys($invoices), 0);
        $reversed = [];

        foreach ($paymentAllocations as $id => $row) {
            $paymentId = (string) ($row['payment_id'] ?? '');
            $invoiceId = (string) ($row['invoice_id'] ?? '');
            $customerId = (string) ($row['customer_id'] ?? '');
            $amount = (int) ($row['amount_paise'] ?? 0);
            $type = (string) ($row['type'] ?? '');

            if (!isset($payments[$paymentId])) {
                $errors[] = "payment_allocations: {$id} references missing payment {$paymentId}";
            }
            if (!isset($invoices[$invoiceId])) {
                $errors[] = "payment_allocations: {$id} references missing invoice {$invoiceId}";
            }
            if (!isset($customers[$customerId])) {
                $errors[] = "payment_allocations: {$id} references missing customer {$customerId}";
            }

            if (isset($payments[$paymentId]) && ($payments[$paymentId]['customer_id'] ?? null) !== $customerId) {
                $errors[] = "payment_allocations: {$id} customer does not match payment";
            }
            if (isset($invoices[$invoiceId]) && ($invoices[$invoiceId]['customer_id'] ?? null) !== $customerId) {
                $errors[] = "payment_allocations: {$id} customer does not match invoice";
            }

            if ($type === 'allocation') {
                if ($amount <= 0) $errors[] = "payment_allocations: {$id} allocation amount must be positive";
                if (!empty($row['reversal_of'])) $errors[] = "payment_allocations: {$id} allocation must not have reversal_of";
            } elseif ($type === 'reversal') {
                if ($amount >= 0) $errors[] = "payment_allocations: {$id} reversal amount must be negative";
                $originalId = (string) ($row['reversal_of'] ?? '');
                $original = $paymentAllocations[$originalId] ?? null;
                if (!$original || ($original['type'] ?? '') !== 'allocation') {
                    $errors[] = "payment_allocations: {$id} references invalid original allocation {$originalId}";
                } else {
                    if (isset($reversed[$originalId])) {
                        $errors[] = "payment_allocations: allocation {$originalId} has multiple reversals";
                    }
                    $reversed[$originalId] = true;
                    if ($amount !== -abs((int) ($original['amount_paise'] ?? 0))) {
                        $errors[] = "payment_allocations: {$id} reversal amount does not match original";
                    }
                    foreach (['payment_id','invoice_id','customer_id'] as $field) {
                        if (($row[$field] ?? null) !== ($original[$field] ?? null)) {
                            $errors[] = "payment_allocations: {$id} reversal {$field} does not match original";
                        }
                    }
                }
            } else {
                $errors[] = "payment_allocations: {$id} has invalid type";
            }

            if (isset($paymentNet[$paymentId])) $paymentNet[$paymentId] += $amount;
            if (isset($invoiceNet[$invoiceId])) $invoiceNet[$invoiceId] += $amount;
        }

        $validMethods = ['cash','upi','neft','rtgs','imps','cheque','card','bank_transfer','credit_note','other'];
        foreach ($payments as $id => $row) {
            $customerId = (string) ($row['customer_id'] ?? '');
            if ($customerId === '' || !isset($customers[$customerId])) {
                $errors[] = "payments: {$id} references missing customer {$customerId}";
            }
            if ((int) ($row['amount_paise'] ?? 0) <= 0) {
                $errors[] = "payments: {$id} amount must be positive";
            }
            if (!in_array($row['method'] ?? '', $validMethods, true)) {
                $errors[] = "payments: {$id} has invalid method";
            }
            if (!in_array($row['status'] ?? '', ['posted','void'], true)) {
                $errors[] = "payments: {$id} has invalid status";
            }

            $batchId = $row['batch_id'] ?? null;
            if ($batchId) {
                if (!isset($paymentBatches[(string) $batchId])) {
                    $errors[] = "payments: {$id} references missing batch {$batchId}";
                } elseif (!empty($paymentBatches[(string) $batchId]['customer_id'])
                    && $paymentBatches[(string) $batchId]['customer_id'] !== $customerId) {
                    $errors[] = "payments: {$id} customer does not match batch";
                }
            }

            $net = (int) ($paymentNet[$id] ?? 0);
            if ($net < 0) $errors[] = "payments: {$id} has negative net allocation";
            if ($net > (int) ($row['amount_paise'] ?? 0)) {
                $errors[] = "payments: {$id} is over-allocated";
            }
            if (($row['status'] ?? '') === 'void') {
                if ($net !== 0) $errors[] = "payments: {$id} is void with active allocations";
                if (trim((string) ($row['void_reason'] ?? '')) === '') $errors[] = "payments: {$id} is void without reason";
            }
        }

        foreach ($invoices as $id => $row) {
            $net = (int) ($invoiceNet[$id] ?? 0);
            $total = (int) ($row['totals']['grand_total_paise'] ?? 0);

            if ($net < 0) $errors[] = "invoices: {$id} has negative net allocation";
            if ($net > $total) $errors[] = "invoices: {$id} is over-allocated";
            if (($row['status'] ?? '') === 'void' && $net !== 0) {
                $errors[] = "invoices: {$id} is void with active allocations";
            }

            if (($row['status'] ?? '') !== 'void') {
                $expectedStatus = empty($row['issued_at'])
                    ? 'draft'
                    : ($net <= 0 ? 'issued' : ($net >= $total ? 'paid' : 'partially_paid'));

                if (($row['status'] ?? '') !== $expectedStatus) {
                    $errors[] = "invoices: {$id} status does not match allocation balance; expected {$expectedStatus}";
                }
            }
        }

        $locationTypes = [
            'showroom','warehouse','zone','rack','workshop_bay',
            'vehicle','customer_site','temporary','transit','other'
        ];

        foreach ($locations as $id => $row) {
            $parentId = $row['parent_id'] ?? null;
            if ($parentId && !isset($locations[(string) $parentId])) {
                $errors[] = "locations: {$id} references missing parent {$parentId}";
            }
            if (!in_array($row['type'] ?? '', $locationTypes, true)) {
                $errors[] = "locations: {$id} has invalid type";
            }
            if (!in_array($row['status'] ?? '', ['active','inactive','archived'], true)) {
                $errors[] = "locations: {$id} has invalid status";
            }
            if (($row['latitude'] ?? null) === null xor ($row['longitude'] ?? null) === null) {
                $errors[] = "locations: {$id} has partial coordinates";
            }

            $seen = [$id => true];
            $current = $parentId;
            while ($current) {
                if (isset($seen[(string) $current])) {
                    $errors[] = "locations: {$id} hierarchy cycle detected";
                    break;
                }
                $seen[(string) $current] = true;
                $parent = $locations[(string) $current] ?? null;
                $current = $parent['parent_id'] ?? null;
            }
        }

        $serialSeen = [];
        $qrSeen = [];
        foreach ($productUnits as $id => $row) {
            $productId = (string) ($row['product_id'] ?? '');
            if ($productId === '' || !isset($products[$productId])) {
                $errors[] = "product_units: {$id} references missing product {$productId}";
            } elseif (!($products[$productId]['serial_tracking'] ?? false)) {
                $errors[] = "product_units: {$id} belongs to product without serial tracking";
            }

            $serial = trim((string) ($row['serial_no'] ?? ''));
            if ($serial === '') {
                $errors[] = "product_units: {$id} has no serial number";
            } else {
                $serialKey = strtolower($productId . ':' . $serial);
                if (isset($serialSeen[$serialKey])) {
                    $errors[] = "product_units: duplicate serial {$serial} for product {$productId}";
                }
                $serialSeen[$serialKey] = true;
            }

            $token = trim((string) ($row['qr_token'] ?? ''));
            if ($token === '') {
                $errors[] = "product_units: {$id} has no QR token";
            } elseif (isset($qrSeen[$token])) {
                $errors[] = "product_units: duplicate QR token";
            } else {
                $qrSeen[$token] = true;
            }

            if (!in_array($row['lifecycle_status'] ?? '', ['active','retired'], true)) {
                $errors[] = "product_units: {$id} has invalid lifecycle status";
            }
        }

        $inventoryTypes = [
            'receive','transfer','issue','return','sale','customer_return',
            'workshop_in','workshop_out','adjustment','damaged','lost','scrap','reversal'
        ];
        $movementReversals = [];
        $ledgerSeqSeen = [];
        $stock = [];
        $unitLocation = [];

        $orderedMovements = array_values($inventoryMovements);
        usort($orderedMovements, static function(array $a, array $b): int {
            $aSeq = (int) ($a['ledger_seq'] ?? 0);
            $bSeq = (int) ($b['ledger_seq'] ?? 0);
            if ($aSeq > 0 && $bSeq > 0 && $aSeq !== $bSeq) {
                return $aSeq <=> $bSeq;
            }
            return strcmp((string) ($a['created_at'] ?? ''), (string) ($b['created_at'] ?? ''))
                ?: strcmp((string) ($a['id'] ?? ''), (string) ($b['id'] ?? ''));
        });

        foreach ($orderedMovements as $row) {
            $id = (string) ($row['id'] ?? '');
            $productId = (string) ($row['product_id'] ?? '');
            $from = $row['from_location_id'] ?? null;
            $to = $row['to_location_id'] ?? null;
            $quantity = (int) ($row['quantity_milli'] ?? 0);
            $type = (string) ($row['type'] ?? '');
            $ledgerSeq = (int) ($row['ledger_seq'] ?? 0);

            if ($ledgerSeq <= 0) {
                $warnings[] = "inventory_movements: {$id} has no ledger sequence";
            } elseif (isset($ledgerSeqSeen[$ledgerSeq])) {
                $errors[] = "inventory_movements: duplicate ledger sequence {$ledgerSeq}";
            } else {
                $ledgerSeqSeen[$ledgerSeq] = true;
            }

            if ($productId === '' || !isset($products[$productId])) {
                $errors[] = "inventory_movements: {$id} references missing product {$productId}";
            }
            if ($from && !isset($locations[(string) $from])) {
                $errors[] = "inventory_movements: {$id} references missing source location {$from}";
            }
            if ($to && !isset($locations[(string) $to])) {
                $errors[] = "inventory_movements: {$id} references missing destination location {$to}";
            }
            if ($from && $to && $from === $to) {
                $errors[] = "inventory_movements: {$id} source and destination are identical";
            }
            if ($quantity <= 0) {
                $errors[] = "inventory_movements: {$id} quantity must be positive";
            }
            if ((int) round((float) ($row['quantity'] ?? 0) * 1000, 0, PHP_ROUND_HALF_UP) !== $quantity) {
                $errors[] = "inventory_movements: {$id} quantity and quantity_milli disagree";
            }
            if (!in_array($type, $inventoryTypes, true)) {
                $errors[] = "inventory_movements: {$id} has invalid type";
            }

            if (in_array($type, ['receive','customer_return'], true) && ($from !== null || $to === null)) {
                $errors[] = "inventory_movements: {$id} has invalid {$type} endpoints";
            }
            if (in_array($type, ['issue','sale','damaged','lost','scrap'], true) && ($from === null || $to !== null)) {
                $errors[] = "inventory_movements: {$id} has invalid {$type} endpoints";
            }
            if (in_array($type, ['transfer','return','workshop_in','workshop_out'], true)
                && ($from === null || $to === null)) {
                $errors[] = "inventory_movements: {$id} has invalid {$type} endpoints";
            }
            if ($type === 'adjustment' && (($from === null) === ($to === null))) {
                $errors[] = "inventory_movements: {$id} adjustment must have exactly one endpoint";
            }

            if ($type === 'reversal') {
                $originalId = (string) ($row['reversal_of'] ?? '');
                $original = $inventoryMovements[$originalId] ?? null;
                if (!$original || ($original['type'] ?? '') === 'reversal') {
                    $errors[] = "inventory_movements: {$id} references invalid original movement {$originalId}";
                } else {
                    if (isset($movementReversals[$originalId])) {
                        $errors[] = "inventory_movements: movement {$originalId} has multiple reversals";
                    }
                    $movementReversals[$originalId] = true;
                    if ($quantity !== (int) ($original['quantity_milli'] ?? 0)) {
                        $errors[] = "inventory_movements: {$id} reversal quantity does not match original";
                    }
                    if ($from !== ($original['to_location_id'] ?? null)
                        || $to !== ($original['from_location_id'] ?? null)
                        || ($row['product_id'] ?? null) !== ($original['product_id'] ?? null)
                        || ($row['product_unit_id'] ?? null) !== ($original['product_unit_id'] ?? null)) {
                        $errors[] = "inventory_movements: {$id} reversal does not mirror original movement";
                    }
                    if (trim((string) ($row['reason'] ?? '')) === '') {
                        $errors[] = "inventory_movements: {$id} reversal has no reason";
                    }
                }
            }

            $unitId = $row['product_unit_id'] ?? null;
            if ($unitId) {
                if (!isset($productUnits[(string) $unitId])) {
                    $errors[] = "inventory_movements: {$id} references missing product unit {$unitId}";
                } else {
                    if (($productUnits[(string) $unitId]['product_id'] ?? null) !== $productId) {
                        $errors[] = "inventory_movements: {$id} unit does not belong to product";
                    }
                    if ($quantity !== 1000) {
                        $errors[] = "inventory_movements: {$id} serialized movement quantity must be 1";
                    }
                    $current = $unitLocation[(string) $unitId] ?? null;
                    if ($from !== $current) {
                        $errors[] = "inventory_movements: {$id} serialized unit source does not match ledger position";
                    }
                    $unitLocation[(string) $unitId] = $to;
                }
            } elseif (isset($products[$productId]) && ($products[$productId]['serial_tracking'] ?? false)) {
                $errors[] = "inventory_movements: {$id} serialized product movement has no unit";
            }

            if ($from) {
                $key = $productId . ':' . $from;
                $stock[$key] = ($stock[$key] ?? 0) - $quantity;
                if ($stock[$key] < 0) {
                    $errors[] = "inventory_movements: {$id} drives stock negative at location {$from}";
                }
            }
            if ($to) {
                $key = $productId . ':' . $to;
                $stock[$key] = ($stock[$key] ?? 0) + $quantity;
            }
        }

        foreach ($locations as $id => $row) {
            if (($row['status'] ?? '') === 'active') continue;
            foreach ($products as $productId => $_product) {
                if (($stock[$productId . ':' . $id] ?? 0) > 0) {
                    $warnings[] = "locations: {$id} is not active but still holds stock";
                    break;
                }
            }
        }

        $workshopStatuses = ['new','in_progress','waiting_parts','ready','completed','cancelled'];
        $workshopPriorities = ['low','normal','high','urgent'];

        foreach ($workshopJobs as $id => $row) {
            $customerId = $row['customer_id'] ?? null;
            $productId = $row['product_id'] ?? null;
            $unitId = $row['product_unit_id'] ?? null;
            $bayId = $row['workshop_location_id'] ?? null;
            $ownership = (string) ($row['ownership'] ?? '');

            if ($customerId && !isset($customers[(string) $customerId])) {
                $errors[] = "workshop_jobs: {$id} references missing customer {$customerId}";
            }
            if ($productId && !isset($products[(string) $productId])) {
                $errors[] = "workshop_jobs: {$id} references missing product {$productId}";
            }
            if ($unitId) {
                if (!isset($productUnits[(string) $unitId])) {
                    $errors[] = "workshop_jobs: {$id} references missing product unit {$unitId}";
                } elseif (($productUnits[(string) $unitId]['product_id'] ?? null) !== $productId) {
                    $errors[] = "workshop_jobs: {$id} unit does not match product";
                }
            }

            if (!in_array($ownership, ['customer','company'], true)) {
                $errors[] = "workshop_jobs: {$id} has invalid ownership";
            }
            if ($ownership === 'company' && !$unitId) {
                $errors[] = "workshop_jobs: {$id} company asset has no serialized unit";
            }
            if (!in_array($row['status'] ?? '', $workshopStatuses, true)) {
                $errors[] = "workshop_jobs: {$id} has invalid status";
            }
            if (!in_array($row['priority'] ?? '', $workshopPriorities, true)) {
                $errors[] = "workshop_jobs: {$id} has invalid priority";
            }
            if (trim((string) ($row['reported_issue'] ?? '')) === '') {
                $errors[] = "workshop_jobs: {$id} has no reported issue";
            }

            if ($bayId) {
                if (!isset($locations[(string) $bayId])) {
                    $errors[] = "workshop_jobs: {$id} references missing workshop bay {$bayId}";
                } elseif (($locations[(string) $bayId]['type'] ?? '') !== 'workshop_bay') {
                    $errors[] = "workshop_jobs: {$id} location is not a workshop bay";
                }
            }

            $checkedIn = !empty($row['checked_in_at']);
            $checkedOut = !empty($row['checked_out_at']);
            if ($checkedOut && !$checkedIn) {
                $errors[] = "workshop_jobs: {$id} is checked out without check-in";
            }
            if (($row['status'] ?? '') === 'completed' && empty($row['completed_at'])) {
                $errors[] = "workshop_jobs: {$id} is completed without completed_at";
            }
            if (($row['status'] ?? '') === 'cancelled' && empty($row['cancelled_at'])) {
                $errors[] = "workshop_jobs: {$id} is cancelled without cancelled_at";
            }
            if (in_array($row['status'] ?? '', ['completed','cancelled'], true) && $checkedIn && !$checkedOut) {
                $errors[] = "workshop_jobs: {$id} is final while asset remains checked in";
            }

            if ($ownership === 'company') {
                $checkInMovementId = $row['check_in_movement_id'] ?? null;
                $checkOutMovementId = $row['check_out_movement_id'] ?? null;

                if ($checkedIn) {
                    $movement = $checkInMovementId ? ($inventoryMovements[(string) $checkInMovementId] ?? null) : null;
                    if (!$movement
                        || ($movement['type'] ?? '') !== 'workshop_in'
                        || ($movement['reference_id'] ?? null) !== $id
                        || ($movement['product_unit_id'] ?? null) !== $unitId
                        || ($movement['to_location_id'] ?? null) !== $bayId) {
                        $errors[] = "workshop_jobs: {$id} has invalid company asset check-in movement";
                    }
                }

                if ($checkedOut) {
                    $movement = $checkOutMovementId ? ($inventoryMovements[(string) $checkOutMovementId] ?? null) : null;
                    if (!$movement
                        || ($movement['type'] ?? '') !== 'workshop_out'
                        || ($movement['reference_id'] ?? null) !== $id
                        || ($movement['product_unit_id'] ?? null) !== $unitId
                        || ($movement['from_location_id'] ?? null) !== $bayId) {
                        $errors[] = "workshop_jobs: {$id} has invalid company asset check-out movement";
                    }
                } elseif ($checkedIn && $unitId && ($unitLocation[(string) $unitId] ?? null) !== $bayId) {
                    $errors[] = "workshop_jobs: {$id} checked-in unit is not at assigned workshop bay";
                }
            } else {
                if (!empty($row['check_in_movement_id']) || !empty($row['check_out_movement_id'])) {
                    $errors[] = "workshop_jobs: {$id} customer asset must not use company inventory movements";
                }
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

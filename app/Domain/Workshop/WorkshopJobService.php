<?php
declare(strict_types=1);

namespace Ecrm\Domain\Workshop;

use Ecrm\Audit\AuditLedger;
use Ecrm\Domain\Inventory\MovementService;
use Ecrm\Domain\Inventory\StockService;
use Ecrm\Search\SearchIndex;
use Ecrm\Storage\AtomicJsonStore;
use Ecrm\Support\ExclusiveLock;
use Ecrm\Support\Sequence;
use Ecrm\Support\UuidV7;
use InvalidArgumentException;

final class WorkshopJobService
{
    public const STATUSES = ['new','in_progress','waiting_parts','ready','completed','cancelled'];
    public const PRIORITIES = ['low','normal','high','urgent'];
    public const OWNERSHIP = ['customer','company'];

    public function __construct(
        private AtomicJsonStore $store,
        private Sequence $sequence,
        private SearchIndex $search,
        private AuditLedger $audit,
        private MovementService $movements,
        private StockService $stock,
        private string $lockRoot
    ) {}

    public function create(array $input): array
    {
        $customerId = trim((string) ($input['customer_id'] ?? ''));
        if ($customerId !== '' && !$this->store->get('customers', $customerId)) {
            throw new InvalidArgumentException('Customer not found');
        }

        $unitId = trim((string) ($input['product_unit_id'] ?? ''));
        $productId = trim((string) ($input['product_id'] ?? ''));
        $ownership = strtolower(trim((string) ($input['ownership'] ?? ($unitId !== '' ? 'company' : 'customer'))));

        if ($unitId !== '') {
            $unit = $this->store->get('product_units', $unitId);
            if (!$unit) throw new InvalidArgumentException('Serialized product unit not found');
            $productId = (string) $unit['product_id'];
            $ownership = 'company';
        }

        if ($productId !== '' && !$this->store->get('products', $productId)) {
            throw new InvalidArgumentException('Product not found');
        }
        if (!in_array($ownership, self::OWNERSHIP, true)) {
            throw new InvalidArgumentException('Invalid asset ownership');
        }
        if ($ownership === 'company' && $unitId === '') {
            throw new InvalidArgumentException('Company-owned workshop asset requires a serialized unit');
        }

        $bayId = $this->workshopBay($input['workshop_location_id'] ?? null, false);
        $priority = strtolower(trim((string) ($input['priority'] ?? 'normal')));
        if (!in_array($priority, self::PRIORITIES, true)) {
            throw new InvalidArgumentException('Invalid workshop priority');
        }

        $issue = trim((string) ($input['reported_issue'] ?? ''));
        if ($issue === '') throw new InvalidArgumentException('Reported issue is required');

        $year = gmdate('Y');
        $now = gmdate(DATE_ATOM);
        $record = [
            'id' => UuidV7::generate(),
            'number' => $this->sequence->next('workshop-jobs-' . $year, 'WJOB-' . $year . '-'),
            'customer_id' => $customerId !== '' ? $customerId : null,
            'product_id' => $productId !== '' ? $productId : null,
            'product_unit_id' => $unitId !== '' ? $unitId : null,
            'asset_serial' => trim((string) ($input['asset_serial'] ?? '')),
            'ownership' => $ownership,
            'workshop_location_id' => $bayId,
            'reported_issue' => $issue,
            'diagnosis' => trim((string) ($input['diagnosis'] ?? '')),
            'work_done' => trim((string) ($input['work_done'] ?? '')),
            'parts_notes' => trim((string) ($input['parts_notes'] ?? '')),
            'notes' => trim((string) ($input['notes'] ?? '')),
            'priority' => $priority,
            'status' => 'new',
            'checked_in_at' => null,
            'checked_out_at' => null,
            'check_in_movement_id' => null,
            'check_out_movement_id' => null,
            'completed_at' => null,
            'cancelled_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $this->store->put('workshop_jobs', $record['id'], $record);
        $this->index($record);
        $this->audit->append('workshop_job.created', 'workshop_job', $record['id'], [
            'number' => $record['number'],
            'ownership' => $ownership,
        ]);
        return $record;
    }

    public function update(string $id, array $input): array
    {
        $record = $this->get($id);
        if (in_array($record['status'], ['completed','cancelled'], true)) {
            throw new InvalidArgumentException('Final workshop job cannot be edited');
        }

        foreach (['reported_issue','diagnosis','work_done','parts_notes','notes','asset_serial'] as $field) {
            if (array_key_exists($field, $input)) $record[$field] = trim((string) $input[$field]);
        }
        if (array_key_exists('priority', $input)) {
            $priority = strtolower(trim((string) $input['priority']));
            if (!in_array($priority, self::PRIORITIES, true)) throw new InvalidArgumentException('Invalid workshop priority');
            $record['priority'] = $priority;
        }
        if (array_key_exists('workshop_location_id', $input)) {
            if ($record['checked_in_at'] && !$record['checked_out_at']) {
                throw new InvalidArgumentException('Check asset out before changing workshop bay');
            }
            $record['workshop_location_id'] = $this->workshopBay($input['workshop_location_id'], false);
        }

        if (trim((string) $record['reported_issue']) === '') throw new InvalidArgumentException('Reported issue is required');

        $record['updated_at'] = gmdate(DATE_ATOM);
        $this->store->put('workshop_jobs', $id, $record);
        $this->index($record);
        $this->audit->append('workshop_job.updated', 'workshop_job', $id);
        return $record;
    }

    public function changeStatus(string $id, string $status): array
    {
        return (new ExclusiveLock($this->lockRoot, 'workshop-jobs'))->run(function () use ($id, $status): array {
            $record = $this->get($id);
            $status = strtolower(trim($status));
            if (!in_array($status, self::STATUSES, true)) throw new InvalidArgumentException('Invalid workshop status');
            if ($status === $record['status']) return $record;

            $allowed = [
                'new' => ['in_progress','cancelled'],
                'in_progress' => ['waiting_parts','ready','cancelled'],
                'waiting_parts' => ['in_progress','ready','cancelled'],
                'ready' => ['in_progress','completed','cancelled'],
                'completed' => [],
                'cancelled' => [],
            ];
            if (!in_array($status, $allowed[$record['status']] ?? [], true)) {
                throw new InvalidArgumentException('Invalid workshop status transition');
            }

            if ($status === 'completed' && $record['checked_in_at'] && !$record['checked_out_at']) {
                throw new InvalidArgumentException('Check asset out before completing workshop job');
            }

            $record['status'] = $status;
            if ($status === 'completed') $record['completed_at'] = gmdate(DATE_ATOM);
            if ($status === 'cancelled') $record['cancelled_at'] = gmdate(DATE_ATOM);
            $record['updated_at'] = gmdate(DATE_ATOM);
            $this->store->put('workshop_jobs', $id, $record);
            $this->index($record);
            $this->audit->append('workshop_job.status_changed', 'workshop_job', $id, ['status' => $status]);
            return $record;
        });
    }

    public function checkIn(string $id): array
    {
        return (new ExclusiveLock($this->lockRoot, 'workshop-jobs'))->run(function () use ($id): array {
            $record = $this->get($id);
            if (in_array($record['status'], ['completed','cancelled'], true)) {
                throw new InvalidArgumentException('Final workshop job cannot be checked in');
            }
            if ($record['checked_in_at'] && !$record['checked_out_at']) return $record;

            $bayId = $this->workshopBay($record['workshop_location_id'] ?? null, true);
            $movementId = null;

            if ($record['ownership'] === 'company') {
                $unitId = (string) ($record['product_unit_id'] ?? '');
                $position = $this->stock->unitPosition($unitId);
                $from = $position['location_id'] ?? null;
                if ($from === null) throw new InvalidArgumentException('Company asset is not currently in stock');
                if ($from === $bayId) throw new InvalidArgumentException('Company asset is already at workshop bay');

                $movement = $this->movements->record([
                    'product_id' => $record['product_id'],
                    'product_unit_id' => $unitId,
                    'from_location_id' => $from,
                    'to_location_id' => $bayId,
                    'quantity' => 1,
                    'type' => 'workshop_in',
                    'reference_type' => 'workshop_job',
                    'reference_id' => $id,
                ]);
                $movementId = $movement['id'];
            }

            $record['checked_in_at'] = gmdate(DATE_ATOM);
            $record['checked_out_at'] = null;
            $record['check_in_movement_id'] = $movementId;
            $record['check_out_movement_id'] = null;
            if ($record['status'] === 'new') $record['status'] = 'in_progress';
            $record['updated_at'] = gmdate(DATE_ATOM);
            $this->store->put('workshop_jobs', $id, $record);
            $this->index($record);
            $this->audit->append('workshop_job.checked_in', 'workshop_job', $id, [
                'workshop_location_id' => $bayId,
                'movement_id' => $movementId,
            ]);
            return $record;
        });
    }

    public function checkOut(string $id, ?string $toLocationId = null): array
    {
        return (new ExclusiveLock($this->lockRoot, 'workshop-jobs'))->run(function () use ($id, $toLocationId): array {
            $record = $this->get($id);
            if (!$record['checked_in_at'] || $record['checked_out_at']) {
                throw new InvalidArgumentException('Workshop job is not currently checked in');
            }

            $movementId = null;
            if ($record['ownership'] === 'company') {
                $destination = trim((string) ($toLocationId ?? ''));
                if ($destination === '') throw new InvalidArgumentException('Destination location is required for company asset');

                $unitId = (string) $record['product_unit_id'];
                $position = $this->stock->unitPosition($unitId);
                $from = $position['location_id'] ?? null;
                if ($from !== $record['workshop_location_id']) {
                    throw new InvalidArgumentException('Company asset is not at assigned workshop bay');
                }

                $movement = $this->movements->record([
                    'product_id' => $record['product_id'],
                    'product_unit_id' => $unitId,
                    'from_location_id' => $from,
                    'to_location_id' => $destination,
                    'quantity' => 1,
                    'type' => 'workshop_out',
                    'reference_type' => 'workshop_job',
                    'reference_id' => $id,
                ]);
                $movementId = $movement['id'];
            }

            $record['checked_out_at'] = gmdate(DATE_ATOM);
            $record['check_out_movement_id'] = $movementId;
            if ($record['status'] === 'in_progress' || $record['status'] === 'waiting_parts') $record['status'] = 'ready';
            $record['updated_at'] = gmdate(DATE_ATOM);
            $this->store->put('workshop_jobs', $id, $record);
            $this->index($record);
            $this->audit->append('workshop_job.checked_out', 'workshop_job', $id, [
                'movement_id' => $movementId,
                'destination_location_id' => $toLocationId,
            ]);
            return $record;
        });
    }

    public function get(string $id): array
    {
        $record = $this->store->get('workshop_jobs', $id);
        if (!$record) throw new InvalidArgumentException('Workshop job not found');
        return $record;
    }

    public function all(): array
    {
        return $this->store->all('workshop_jobs');
    }

    private function workshopBay(mixed $id, bool $required): ?string
    {
        $id = trim((string) ($id ?? ''));
        if ($id === '') {
            if ($required) throw new InvalidArgumentException('Workshop bay is required');
            return null;
        }

        $location = $this->store->get('locations', $id);
        if (!$location) throw new InvalidArgumentException('Workshop bay not found');
        if (($location['type'] ?? '') !== 'workshop_bay') throw new InvalidArgumentException('Location is not a workshop bay');
        if (($location['status'] ?? '') !== 'active') throw new InvalidArgumentException('Workshop bay is not active');
        return $id;
    }

    private function index(array $record): void
    {
        $customer = $record['customer_id'] ? $this->store->get('customers', (string) $record['customer_id']) : null;
        $product = $record['product_id'] ? $this->store->get('products', (string) $record['product_id']) : null;
        $unit = $record['product_unit_id'] ? $this->store->get('product_units', (string) $record['product_unit_id']) : null;

        $this->search->upsert('workshop_job', $record['id'], $record['number'], [
            $customer['name'] ?? '',
            $customer['mobile'] ?? '',
            $product['name'] ?? '',
            $product['code'] ?? '',
            $unit['serial_no'] ?? '',
            $record['asset_serial'] ?? '',
            $record['reported_issue'],
            $record['diagnosis'],
            $record['status'],
            $record['priority'],
        ], [
            'number' => $record['number'],
            'customer_id' => $record['customer_id'],
            'product_id' => $record['product_id'],
            'status' => $record['status'],
            'priority' => $record['priority'],
        ]);
    }
}

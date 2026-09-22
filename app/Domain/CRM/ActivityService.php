<?php
declare(strict_types=1);

namespace Ecrm\Domain\CRM;

use Ecrm\Audit\AuditLedger;
use Ecrm\Storage\AtomicJsonStore;
use Ecrm\Support\UuidV7;
use InvalidArgumentException;

final class ActivityService
{
    private const TYPES = ['call', 'whatsapp', 'email', 'meeting', 'note', 'task', 'follow_up'];

    public function __construct(private AtomicJsonStore $store, private AuditLedger $audit) {}

    public function create(array $input): array
    {
        $type = strtolower(trim((string) ($input['type'] ?? 'note')));
        if (!in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException('Invalid activity type');
        }

        $subject = trim((string) ($input['subject'] ?? ''));
        if ($subject === '') {
            throw new InvalidArgumentException('Activity subject is required');
        }

        $record = [
            'id' => UuidV7::generate(),
            'customer_id' => $input['customer_id'] ?? null,
            'lead_id' => $input['lead_id'] ?? null,
            'type' => $type,
            'subject' => $subject,
            'notes' => trim((string) ($input['notes'] ?? '')),
            'due_at' => $input['due_at'] ?? null,
            'status' => in_array($type, ['task', 'follow_up'], true) ? 'open' : 'completed',
            'created_at' => gmdate(DATE_ATOM),
            'completed_at' => in_array($type, ['task', 'follow_up'], true) ? null : gmdate(DATE_ATOM),
        ];

        if (!$record['customer_id'] && !$record['lead_id']) {
            throw new InvalidArgumentException('Activity requires a customer or lead');
        }

        $this->store->put('activities', $record['id'], $record);
        $this->audit->append('activity.created', 'activity', $record['id'], ['type' => $type]);
        return $record;
    }

    public function complete(string $id): array
    {
        $record = $this->store->get('activities', $id);
        if (!$record) {
            throw new InvalidArgumentException('Activity not found');
        }
        $record['status'] = 'completed';
        $record['completed_at'] = gmdate(DATE_ATOM);
        $this->store->put('activities', $id, $record);
        $this->audit->append('activity.completed', 'activity', $id);
        return $record;
    }

    public function all(?string $customerId = null): array
    {
        return $customerId ? $this->store->where('activities', 'customer_id', $customerId) : $this->store->all('activities');
    }
}

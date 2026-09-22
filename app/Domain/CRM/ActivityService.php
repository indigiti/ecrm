<?php
declare(strict_types=1);

namespace Ecrm\Domain\CRM;

use DateTimeImmutable;
use DateTimeZone;
use Ecrm\Audit\AuditLedger;
use Ecrm\Storage\AtomicJsonStore;
use Ecrm\Support\UuidV7;
use InvalidArgumentException;

final class ActivityService
{
    private const TYPES = ['call', 'whatsapp', 'email', 'meeting', 'note', 'task', 'follow_up'];

    public function __construct(
        private AtomicJsonStore $store,
        private AuditLedger $audit,
        private string $timezone = 'Asia/Kolkata'
    ) {}

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

        $dueAt = $this->normalizeDueAt($input['due_at'] ?? null);
        $open = in_array($type, ['task', 'follow_up'], true);

        $record = [
            'id' => UuidV7::generate(),
            'customer_id' => $input['customer_id'] ?? null,
            'lead_id' => $input['lead_id'] ?? null,
            'type' => $type,
            'subject' => $subject,
            'notes' => trim((string) ($input['notes'] ?? '')),
            'due_at' => $dueAt,
            'status' => $open ? 'open' : 'completed',
            'created_at' => gmdate(DATE_ATOM),
            'completed_at' => $open ? null : gmdate(DATE_ATOM),
        ];

        if (!$record['customer_id'] && !$record['lead_id']) {
            throw new InvalidArgumentException('Activity requires a customer or lead');
        }

        $this->store->put('activities', $record['id'], $record);
        $this->audit->append('activity.created', 'activity', $record['id'], ['type' => $type]);
        return $this->decorate($record);
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
        return $this->decorate($record);
    }

    public function all(?string $customerId = null): array
    {
        $rows = $customerId
            ? $this->store->where('activities', 'customer_id', $customerId)
            : $this->store->all('activities');

        return array_map(fn(array $row): array => $this->decorate($row), $rows);
    }

    public function attention(): array
    {
        $buckets = ['overdue' => [], 'today' => [], 'upcoming' => [], 'unscheduled' => []];
        foreach ($this->all() as $row) {
            if (($row['status'] ?? '') !== 'open') {
                continue;
            }
            $bucket = $row['attention'] ?? 'unscheduled';
            $buckets[$bucket][] = $row;
        }

        foreach ($buckets as &$rows) {
            usort($rows, static fn(array $a, array $b): int => strcmp((string) ($a['due_at'] ?? '9999'), (string) ($b['due_at'] ?? '9999')));
        }

        return $buckets;
    }

    private function normalizeDueAt(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '') {
            return null;
        }

        try {
            $local = new DateTimeImmutable($value, new DateTimeZone($this->timezone));
        } catch (\Throwable) {
            throw new InvalidArgumentException('Invalid due date');
        }

        return $local->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM);
    }

    private function decorate(array $record): array
    {
        if (($record['status'] ?? '') !== 'open') {
            $record['attention'] = 'completed';
            return $record;
        }

        if (empty($record['due_at'])) {
            $record['attention'] = 'unscheduled';
            return $record;
        }

        $tz = new DateTimeZone($this->timezone);
        $now = new DateTimeImmutable('now', $tz);
        $due = (new DateTimeImmutable((string) $record['due_at']))->setTimezone($tz);

        if ($due < $now) {
            $record['attention'] = 'overdue';
        } elseif ($due->format('Y-m-d') === $now->format('Y-m-d')) {
            $record['attention'] = 'today';
        } else {
            $record['attention'] = 'upcoming';
        }

        return $record;
    }
}

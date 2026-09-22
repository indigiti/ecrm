<?php
declare(strict_types=1);

namespace Ecrm\Domain\CRM;

use Ecrm\Audit\AuditLedger;
use Ecrm\Search\SearchIndex;
use Ecrm\Storage\AtomicJsonStore;
use Ecrm\Support\Sequence;
use Ecrm\Support\UuidV7;
use InvalidArgumentException;

final class LeadService
{
    public const STAGES = ['new', 'contacted', 'requirement', 'quotation', 'negotiation', 'approved', 'won', 'lost', 'on_hold'];

    public function __construct(
        private AtomicJsonStore $store,
        private Sequence $sequence,
        private SearchIndex $search,
        private AuditLedger $audit
    ) {}

    public function create(array $input): array
    {
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('Lead name is required');
        }

        $record = [
            'id' => UuidV7::generate(),
            'number' => $this->sequence->next('leads', 'LEAD-'),
            'name' => $name,
            'company' => trim((string) ($input['company'] ?? '')),
            'mobile' => trim((string) ($input['mobile'] ?? '')),
            'email' => trim((string) ($input['email'] ?? '')),
            'source' => trim((string) ($input['source'] ?? '')),
            'requirement' => trim((string) ($input['requirement'] ?? '')),
            'customer_id' => $input['customer_id'] ?? null,
            'stage' => 'new',
            'value' => (float) ($input['value'] ?? 0),
            'created_at' => gmdate(DATE_ATOM),
            'updated_at' => gmdate(DATE_ATOM),
        ];

        $this->store->put('leads', $record['id'], $record);
        $this->index($record);
        $this->audit->append('lead.created', 'lead', $record['id']);
        return $record;
    }

    public function changeStage(string $id, string $stage): array
    {
        $stage = strtolower(trim($stage));
        if (!in_array($stage, self::STAGES, true)) {
            throw new InvalidArgumentException('Invalid lead stage');
        }

        $record = $this->store->get('leads', $id);
        if (!$record) {
            throw new InvalidArgumentException('Lead not found');
        }

        $record['stage'] = $stage;
        $record['updated_at'] = gmdate(DATE_ATOM);
        $this->store->put('leads', $id, $record);
        $this->index($record);
        $this->audit->append('lead.stage_changed', 'lead', $id, ['stage' => $stage]);
        return $record;
    }

    public function all(): array
    {
        return $this->store->all('leads');
    }

    private function index(array $record): void
    {
        $this->search->upsert('lead', $record['id'], $record['name'], [
            $record['number'], $record['company'], $record['mobile'], $record['email'],
            $record['source'], $record['requirement'], $record['stage']
        ], ['number' => $record['number'], 'stage' => $record['stage']]);
    }
}

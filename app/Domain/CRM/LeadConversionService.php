<?php
declare(strict_types=1);

namespace Ecrm\Domain\CRM;

use Ecrm\Domain\Customers\CustomerService;
use InvalidArgumentException;

final class LeadConversionService
{
    public function __construct(
        private LeadService $leads,
        private CustomerService $customers
    ) {}

    public function convert(string $leadId, array $overrides = []): array
    {
        $lead = $this->leads->get($leadId);
        if (!empty($lead['customer_id'])) {
            return [
                'lead' => $lead,
                'customer' => $this->customers->get((string) $lead['customer_id']),
                'created' => false,
            ];
        }

        if (in_array($lead['stage'], ['lost'], true)) {
            throw new InvalidArgumentException('Lost lead cannot be converted without reopening it');
        }

        $name = trim((string) ($overrides['name'] ?? $lead['company'] ?? ''));
        if ($name === '') {
            $name = trim((string) $lead['name']);
        }

        $customer = $this->customers->create([
            'name' => $name,
            'contact_person' => trim((string) ($overrides['contact_person'] ?? $lead['name'])),
            'mobile' => trim((string) ($overrides['mobile'] ?? $lead['mobile'])),
            'email' => trim((string) ($overrides['email'] ?? $lead['email'])),
            'category' => trim((string) ($overrides['category'] ?? '')),
            'tags' => ['lead-converted'],
        ]);

        $lead = $this->leads->linkCustomer($leadId, $customer['id']);

        return ['lead' => $lead, 'customer' => $customer, 'created' => true];
    }
}

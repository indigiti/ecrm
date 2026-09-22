<?php
declare(strict_types=1);

namespace Ecrm\Http;

use Ecrm\Audit\AuditLedger;
use Ecrm\Domain\CRM\ActivityService;
use Ecrm\Domain\CRM\LeadService;
use Ecrm\Domain\Customers\AddressService;
use Ecrm\Domain\Customers\ContactService;
use Ecrm\Domain\Customers\CustomerService;
use Ecrm\Search\SearchIndex;
use Ecrm\Storage\AtomicJsonStore;
use Ecrm\Support\Runtime;
use Ecrm\Support\Sequence;
use InvalidArgumentException;
use Throwable;

final class ApiController
{
    private AtomicJsonStore $store;
    private CustomerService $customers;
    private ContactService $contacts;
    private AddressService $addresses;
    private LeadService $leads;
    private ActivityService $activities;
    private SearchIndex $search;

    public function __construct()
    {
        $this->store = new AtomicJsonStore(Runtime::dataRoot());
        $this->search = new SearchIndex(Runtime::indexRoot());
        $audit = new AuditLedger(Runtime::auditRoot());
        $sequence = new Sequence(Runtime::dataRoot() . '/sequences');

        $this->customers = new CustomerService($this->store, $sequence, $this->search, $audit);
        $this->contacts = new ContactService($this->store, $this->search, $audit);
        $this->addresses = new AddressService($this->store, $this->search, $audit);
        $this->leads = new LeadService($this->store, $sequence, $this->search, $audit);
        $this->activities = new ActivityService($this->store, $audit);
    }

    public function handle(): void
    {
        try {
            $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
            $path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
            $marker = '/api/';
            $position = strpos($path, $marker);

            if ($position === false) {
                $this->json(['error' => 'API route not found'], 404);
                return;
            }

            $route = trim(substr($path, $position + strlen($marker)), '/');
            $segments = $route === '' ? [] : explode('/', $route);
            $input = $this->input();

            if ($segments === ['dashboard'] && $method === 'GET') {
                $activities = $this->activities->all();
                $this->json([
                    'customers' => count($this->customers->all()),
                    'leads' => count($this->leads->all()),
                    'open_followups' => count(array_filter($activities, static fn(array $a): bool => ($a['status'] ?? '') === 'open')),
                    'mapped_addresses' => count($this->addresses->mapped()),
                    'recent_activity' => array_slice($activities, 0, 8),
                ]);
                return;
            }

            if ($segments === ['customers']) {
                if ($method === 'GET') {
                    $this->json(['data' => $this->customers->all()]);
                    return;
                }
                if ($method === 'POST') {
                    $this->json(['data' => $this->customers->create($input)], 201);
                    return;
                }
            }

            if (($segments[0] ?? '') === 'customers' && isset($segments[1])) {
                $id = $segments[1];

                if (count($segments) === 2 && $method === 'GET') {
                    $this->json(['data' => $this->customers->get($id)]);
                    return;
                }

                if (count($segments) === 2 && in_array($method, ['PUT', 'PATCH'], true)) {
                    $this->json(['data' => $this->customers->update($id, $input)]);
                    return;
                }

                if (($segments[2] ?? '') === 'overview' && $method === 'GET') {
                    $this->json(['data' => [
                        'customer' => $this->customers->get($id),
                        'contacts' => $this->contacts->forCustomer($id),
                        'addresses' => $this->addresses->forCustomer($id),
                        'activities' => $this->activities->all($id),
                    ]]);
                    return;
                }

                if (($segments[2] ?? '') === 'contacts' && $method === 'POST') {
                    $this->json(['data' => $this->contacts->create($id, $input)], 201);
                    return;
                }

                if (($segments[2] ?? '') === 'addresses' && $method === 'POST') {
                    $this->json(['data' => $this->addresses->create($id, $input)], 201);
                    return;
                }
            }

            if ($segments === ['leads']) {
                if ($method === 'GET') {
                    $this->json(['data' => $this->leads->all(), 'stages' => LeadService::STAGES]);
                    return;
                }
                if ($method === 'POST') {
                    $this->json(['data' => $this->leads->create($input)], 201);
                    return;
                }
            }

            if (($segments[0] ?? '') === 'leads' && isset($segments[1]) && ($segments[2] ?? '') === 'stage' && in_array($method, ['PUT', 'PATCH'], true)) {
                $this->json(['data' => $this->leads->changeStage($segments[1], (string) ($input['stage'] ?? ''))]);
                return;
            }

            if ($segments === ['activities']) {
                if ($method === 'GET') {
                    $customerId = isset($_GET['customer_id']) ? (string) $_GET['customer_id'] : null;
                    $this->json(['data' => $this->activities->all($customerId)]);
                    return;
                }
                if ($method === 'POST') {
                    $this->json(['data' => $this->activities->create($input)], 201);
                    return;
                }
            }

            if (($segments[0] ?? '') === 'activities' && isset($segments[1]) && ($segments[2] ?? '') === 'complete' && in_array($method, ['POST', 'PATCH'], true)) {
                $this->json(['data' => $this->activities->complete($segments[1])]);
                return;
            }

            if ($segments === ['search'] && $method === 'GET') {
                $this->json(['data' => $this->search->search((string) ($_GET['q'] ?? ''))]);
                return;
            }

            if ($segments === ['map', 'addresses'] && $method === 'GET') {
                $this->json(['data' => $this->addresses->mapped()]);
                return;
            }

            $this->json(['error' => 'API route not found'], 404);
        } catch (InvalidArgumentException $e) {
            $this->json(['error' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            error_log('eCRM API: ' . $e->getMessage());
            $this->json(['error' => 'Internal application error'], 500);
        }
    }

    private function input(): array
    {
        $raw = (string) file_get_contents('php://input');
        if ($raw === '') {
            return $_POST ?: [];
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function json(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}

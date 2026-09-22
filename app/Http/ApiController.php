<?php
declare(strict_types=1);

namespace Ecrm\Http;

use Ecrm\Audit\AuditLedger;
use Ecrm\Domain\CRM\ActivityService;
use Ecrm\Domain\CRM\GeocodingQueue;
use Ecrm\Domain\CRM\LeadConversionService;
use Ecrm\Domain\CRM\LeadService;
use Ecrm\Domain\Customers\AddressService;
use Ecrm\Domain\Customers\ContactService;
use Ecrm\Domain\Customers\CustomerService;
use Ecrm\Domain\Finance\AllocationService;
use Ecrm\Domain\Finance\PaymentBatchService;
use Ecrm\Domain\Finance\PaymentService;
use Ecrm\Domain\Finance\ReceivablesService;
use Ecrm\Domain\Products\ProductService;
use Ecrm\Domain\Sales\InvoiceService;
use Ecrm\Domain\Sales\QuotationService;
use Ecrm\Domain\Sales\SalesCalculator;
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
    private LeadConversionService $leadConversion;
    private ActivityService $activities;
    private ProductService $products;
    private QuotationService $quotations;
    private InvoiceService $invoices;
    private PaymentBatchService $paymentBatches;
    private PaymentService $payments;
    private AllocationService $allocations;
    private ReceivablesService $receivables;
    private SearchIndex $search;

    public function __construct()
    {
        $this->store = new AtomicJsonStore(Runtime::dataRoot());
        $this->search = new SearchIndex(Runtime::indexRoot());
        $audit = new AuditLedger(Runtime::auditRoot());
        $sequence = new Sequence(Runtime::dataRoot() . '/sequences');
        $geocoding = new GeocodingQueue(Runtime::jobsRoot());
        $calculator = new SalesCalculator();
        $locks = Runtime::locksRoot();

        $this->customers = new CustomerService($this->store, $sequence, $this->search, $audit);
        $this->contacts = new ContactService($this->store, $this->search, $audit);
        $this->addresses = new AddressService($this->store, $this->search, $audit, $geocoding);
        $this->leads = new LeadService($this->store, $sequence, $this->search, $audit);
        $this->leadConversion = new LeadConversionService($this->leads, $this->customers);
        $this->activities = new ActivityService($this->store, $audit);
        $this->products = new ProductService($this->store, $sequence, $this->search, $audit);
        $this->quotations = new QuotationService($this->store, $sequence, $this->search, $audit, $calculator);
        $this->invoices = new InvoiceService($this->store, $sequence, $this->search, $audit, $calculator, $locks);
        $this->paymentBatches = new PaymentBatchService($this->store, $sequence, $this->search, $audit, $locks);
        $this->payments = new PaymentService($this->store, $sequence, $this->search, $audit, $locks);
        $this->allocations = new AllocationService($this->store, $audit, $locks, $this->search);
        $this->receivables = new ReceivablesService($this->store, $this->allocations);
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
                $attention = $this->activities->attention();
                $openLeads = array_filter(
                    $this->leads->all(),
                    static fn(array $lead): bool => !in_array($lead['stage'] ?? '', ['won', 'lost'], true)
                );
                $quotesAwaiting = array_filter(
                    $this->quotations->all(),
                    static fn(array $quote): bool => in_array($quote['status'] ?? '', ['sent','viewed','revised'], true)
                );
                $issuedInvoices = array_filter(
                    $this->invoices->all(),
                    static fn(array $invoice): bool => in_array($invoice['status'] ?? '', ['issued','partially_paid'], true)
                );
                $finance = $this->receivables->totals();

                $this->json([
                    'customers' => count($this->customers->all()),
                    'leads' => count($openLeads),
                    'open_followups' => count(array_filter($activities, static fn(array $a): bool => ($a['status'] ?? '') === 'open')),
                    'overdue_followups' => count($attention['overdue']),
                    'due_today' => count($attention['today']),
                    'mapped_addresses' => count($this->addresses->mapped()),
                    'quotes_awaiting' => count($quotesAwaiting),
                    'issued_invoices' => count($issuedInvoices),
                    'collected_paise' => $finance['collected_paise'],
                    'outstanding_paise' => $finance['outstanding_paise'],
                    'overdue_paise' => $finance['overdue_paise'],
                    'unallocated_credit_paise' => $finance['unallocated_credit_paise'],
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
                        'quotations' => array_values(array_filter($this->quotations->all(), static fn(array $q): bool => ($q['customer_id'] ?? null) === $id)),
                        'invoices' => array_values(array_filter($this->invoices->all(), static fn(array $i): bool => ($i['customer_id'] ?? null) === $id)),
                        'payments' => array_values(array_filter($this->payments->all(), static fn(array $p): bool => ($p['customer_id'] ?? null) === $id)),
                        'receivables' => $this->receivables->ageing($id),
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
                if (($segments[2] ?? '') === 'statement' && $method === 'GET') {
                    $this->json(['data' => $this->receivables->customerStatement($id)]);
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

            if (($segments[0] ?? '') === 'leads' && isset($segments[1])) {
                if (count($segments) === 2 && in_array($method, ['PUT', 'PATCH'], true)) {
                    $this->json(['data' => $this->leads->update($segments[1], $input)]);
                    return;
                }
                if (($segments[2] ?? '') === 'stage' && in_array($method, ['PUT', 'PATCH'], true)) {
                    $this->json(['data' => $this->leads->changeStage($segments[1], (string) ($input['stage'] ?? ''))]);
                    return;
                }
                if (($segments[2] ?? '') === 'convert' && $method === 'POST') {
                    $this->json(['data' => $this->leadConversion->convert($segments[1], $input)], 201);
                    return;
                }
            }

            if (($segments[0] ?? '') === 'contacts' && isset($segments[1]) && count($segments) === 2 && in_array($method, ['PUT', 'PATCH'], true)) {
                $this->json(['data' => $this->contacts->update($segments[1], $input)]);
                return;
            }

            if (($segments[0] ?? '') === 'addresses' && isset($segments[1]) && count($segments) === 2 && in_array($method, ['PUT', 'PATCH'], true)) {
                $this->json(['data' => $this->addresses->update($segments[1], $input)]);
                return;
            }

            if ($segments === ['activities']) {
                if ($method === 'GET') {
                    $this->json(['data' => $this->activities->all(isset($_GET['customer_id']) ? (string) $_GET['customer_id'] : null)]);
                    return;
                }
                if ($method === 'POST') {
                    $this->json(['data' => $this->activities->create($input)], 201);
                    return;
                }
            }

            if ($segments === ['activities', 'attention'] && $method === 'GET') {
                $this->json(['data' => $this->activities->attention()]);
                return;
            }

            if (($segments[0] ?? '') === 'activities' && isset($segments[1]) && ($segments[2] ?? '') === 'complete' && in_array($method, ['POST', 'PATCH'], true)) {
                $this->json(['data' => $this->activities->complete($segments[1])]);
                return;
            }

            if ($segments === ['products']) {
                if ($method === 'GET') {
                    $this->json(['data' => $this->products->all()]);
                    return;
                }
                if ($method === 'POST') {
                    $this->json(['data' => $this->products->create($input)], 201);
                    return;
                }
            }

            if (($segments[0] ?? '') === 'products' && isset($segments[1]) && count($segments) === 2) {
                if ($method === 'GET') {
                    $this->json(['data' => $this->products->get($segments[1])]);
                    return;
                }
                if (in_array($method, ['PUT','PATCH'], true)) {
                    $this->json(['data' => $this->products->update($segments[1], $input)]);
                    return;
                }
            }

            if ($segments === ['quotations']) {
                if ($method === 'GET') {
                    $this->json(['data' => $this->quotations->all(), 'statuses' => QuotationService::STATUSES]);
                    return;
                }
                if ($method === 'POST') {
                    $this->json(['data' => $this->quotations->create($input)], 201);
                    return;
                }
            }

            if (($segments[0] ?? '') === 'quotations' && isset($segments[1])) {
                $id = $segments[1];
                if (count($segments) === 2 && $method === 'GET') {
                    $this->json(['data' => $this->quotations->get($id)]);
                    return;
                }
                if (count($segments) === 2 && in_array($method, ['PUT','PATCH'], true)) {
                    $this->json(['data' => $this->quotations->update($id, $input)]);
                    return;
                }
                if (($segments[2] ?? '') === 'status' && in_array($method, ['PUT','PATCH'], true)) {
                    $this->json(['data' => $this->quotations->changeStatus($id, (string) ($input['status'] ?? ''))]);
                    return;
                }
                if (($segments[2] ?? '') === 'invoice' && $method === 'POST') {
                    $this->json(['data' => $this->invoices->createFromQuotation($id, $input)], 201);
                    return;
                }
            }

            if ($segments === ['invoices']) {
                if ($method === 'GET') {
                    $this->json(['data' => $this->invoices->all(), 'statuses' => InvoiceService::STATUSES]);
                    return;
                }
                if ($method === 'POST') {
                    $this->json(['data' => $this->invoices->create($input)], 201);
                    return;
                }
            }

            if (($segments[0] ?? '') === 'invoices' && isset($segments[1])) {
                $id = $segments[1];
                if (count($segments) === 2 && $method === 'GET') {
                    $this->json(['data' => $this->invoices->get($id)]);
                    return;
                }
                if (count($segments) === 2 && in_array($method, ['PUT','PATCH'], true)) {
                    $this->json(['data' => $this->invoices->update($id, $input)]);
                    return;
                }
                if (($segments[2] ?? '') === 'issue' && $method === 'POST') {
                    $this->json(['data' => $this->invoices->issue($id)]);
                    return;
                }
                if (($segments[2] ?? '') === 'void' && $method === 'POST') {
                    $this->json(['data' => $this->invoices->void($id, (string) ($input['reason'] ?? ''))]);
                    return;
                }
            }

            if ($segments === ['payment-batches']) {
                if ($method === 'GET') {
                    $this->json(['data' => $this->paymentBatches->all()]);
                    return;
                }
                if ($method === 'POST') {
                    $this->json(['data' => $this->paymentBatches->create($input)], 201);
                    return;
                }
            }

            if (($segments[0] ?? '') === 'payment-batches' && isset($segments[1])) {
                if (count($segments) === 2 && $method === 'GET') {
                    $this->json(['data' => $this->paymentBatches->get($segments[1])]);
                    return;
                }
                if (($segments[2] ?? '') === 'close' && $method === 'POST') {
                    $this->json(['data' => $this->paymentBatches->close($segments[1])]);
                    return;
                }
            }

            if ($segments === ['payments']) {
                if ($method === 'GET') {
                    $this->json(['data' => $this->payments->all(), 'methods' => PaymentService::METHODS]);
                    return;
                }
                if ($method === 'POST') {
                    $this->json(['data' => $this->payments->create($input)], 201);
                    return;
                }
            }

            if (($segments[0] ?? '') === 'payments' && isset($segments[1])) {
                $paymentId = $segments[1];

                if (count($segments) === 2 && $method === 'GET') {
                    $payment = $this->payments->get($paymentId);
                    $allocated = $this->allocations->netForPayment($paymentId);
                    $this->json(['data' => [
                        'payment' => $payment,
                        'allocations' => $this->allocations->forPayment($paymentId),
                        'allocated_paise' => $allocated,
                        'available_paise' => max(0, (int) $payment['amount_paise'] - $allocated),
                    ]]);
                    return;
                }

                if (($segments[2] ?? '') === 'void' && $method === 'POST') {
                    $this->json(['data' => $this->payments->void($paymentId, (string) ($input['reason'] ?? ''))]);
                    return;
                }

                if (($segments[2] ?? '') === 'allocate' && $method === 'POST') {
                    $this->json(['data' => $this->allocations->allocate(
                        $paymentId,
                        (string) ($input['invoice_id'] ?? ''),
                        $input
                    )], 201);
                    return;
                }
            }

            if (($segments[0] ?? '') === 'allocations' && isset($segments[1])
                && ($segments[2] ?? '') === 'reverse' && $method === 'POST') {
                $this->json(['data' => $this->allocations->reverse(
                    $segments[1],
                    (string) ($input['reason'] ?? '')
                )], 201);
                return;
            }

            if ($segments === ['receivables', 'outstanding'] && $method === 'GET') {
                $customerId = isset($_GET['customer_id']) && $_GET['customer_id'] !== ''
                    ? (string) $_GET['customer_id']
                    : null;
                $this->json(['data' => $this->receivables->outstanding($customerId)]);
                return;
            }

            if ($segments === ['receivables', 'ageing'] && $method === 'GET') {
                $customerId = isset($_GET['customer_id']) && $_GET['customer_id'] !== ''
                    ? (string) $_GET['customer_id']
                    : null;
                $this->json(['data' => $this->receivables->ageing($customerId)]);
                return;
            }

            if ($segments === ['receivables', 'totals'] && $method === 'GET') {
                $this->json(['data' => $this->receivables->totals()]);
                return;
            }

            if (($segments[0] ?? '') === 'invoices' && isset($segments[1])
                && ($segments[2] ?? '') === 'receivable' && $method === 'GET') {
                $this->json(['data' => $this->receivables->invoiceSummary($segments[1])]);
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
        if ($raw === '') return $_POST ?: [];
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

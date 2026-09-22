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
use Ecrm\Domain\Inventory\LocationService;
use Ecrm\Domain\Inventory\MovementService;
use Ecrm\Domain\Inventory\ProductUnitService;
use Ecrm\Domain\Inventory\StockService;
use Ecrm\Domain\Products\ProductService;
use Ecrm\Domain\Reports\ReportingService;
use Ecrm\Domain\Sales\InvoiceService;
use Ecrm\Domain\Sales\QuotationService;
use Ecrm\Domain\Sales\SalesCalculator;
use Ecrm\Domain\Workshop\WorkshopJobService;
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
    private ReportingService $reports;
    private QuotationService $quotations;
    private InvoiceService $invoices;
    private PaymentBatchService $paymentBatches;
    private PaymentService $payments;
    private AllocationService $allocations;
    private ReceivablesService $receivables;
    private LocationService $locations;
    private MovementService $movements;
    private ProductUnitService $productUnits;
    private StockService $stock;
    private WorkshopJobService $workshopJobs;
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
        $this->reports = new ReportingService($this->store, $this->receivables);
        $this->locations = new LocationService($this->store, $sequence, $this->search, $audit);
        $this->movements = new MovementService($this->store, $audit, $locks, $sequence);
        $this->productUnits = new ProductUnitService($this->store, $this->search, $audit, $locks);
        $this->stock = new StockService($this->store);
        $this->workshopJobs = new WorkshopJobService($this->store, $sequence, $this->search, $audit, $this->movements, $this->stock, $locks);
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

            if (($segments[0] ?? '') === 'products' && isset($segments[1])) {
                $productId = $segments[1];
                if (count($segments) === 2 && $method === 'GET') {
                    $this->json(['data' => $this->products->get($productId)]);
                    return;
                }
                if (count($segments) === 2 && in_array($method, ['PUT','PATCH'], true)) {
                    $this->json(['data' => $this->products->update($productId, $input)]);
                    return;
                }
                if (($segments[2] ?? '') === 'overview' && $method === 'GET') {
                    $this->json(['data' => [
                        'product' => $this->products->get($productId),
                        'stock' => $this->stock->product($productId),
                        'units' => $this->productUnits->forProduct($productId),
                        'movements' => $this->movements->history($productId),
                    ]]);
                    return;
                }
            }

            if ($segments === ['locations']) {
                if ($method === 'GET') {
                    $this->json(['data' => $this->locations->all(), 'types' => LocationService::TYPES]);
                    return;
                }
                if ($method === 'POST') {
                    $this->json(['data' => $this->locations->create($input)], 201);
                    return;
                }
            }

            if ($segments === ['locations', 'tree'] && $method === 'GET') {
                $this->json(['data' => $this->locations->tree()]);
                return;
            }

            if (($segments[0] ?? '') === 'locations' && isset($segments[1])) {
                $locationId = $segments[1];
                if (count($segments) === 2 && $method === 'GET') {
                    $this->json(['data' => $this->locations->get($locationId)]);
                    return;
                }
                if (count($segments) === 2 && in_array($method, ['PUT','PATCH'], true)) {
                    $this->json(['data' => $this->locations->update($locationId, $input)]);
                    return;
                }
                if (($segments[2] ?? '') === 'stock' && $method === 'GET') {
                    $this->json(['data' => $this->stock->location($locationId)]);
                    return;
                }
            }

            if ($segments === ['product-units']) {
                if ($method === 'GET') {
                    $productId = isset($_GET['product_id']) && $_GET['product_id'] !== ''
                        ? (string) $_GET['product_id']
                        : null;
                    $this->json(['data' => $productId
                        ? $this->productUnits->forProduct($productId)
                        : $this->productUnits->all()
                    ]);
                    return;
                }
                if ($method === 'POST') {
                    $this->json(['data' => $this->productUnits->create($input)], 201);
                    return;
                }
            }

            if (($segments[0] ?? '') === 'product-units' && isset($segments[1])) {
                $unitId = $segments[1];
                if (count($segments) === 2 && $method === 'GET') {
                    $this->json(['data' => $this->stock->unitPosition($unitId)]);
                    return;
                }
                if (($segments[2] ?? '') === 'retire' && $method === 'POST') {
                    $this->json(['data' => $this->productUnits->retire(
                        $unitId,
                        (string) ($input['reason'] ?? '')
                    )]);
                    return;
                }
            }

            if ($segments === ['inventory', 'stock'] && $method === 'GET') {
                $this->json(['data' => $this->stock->all()]);
                return;
            }

            if (($segments[0] ?? '') === 'inventory' && ($segments[1] ?? '') === 'stock'
                && isset($segments[2]) && $method === 'GET') {
                $this->json(['data' => $this->stock->product($segments[2])]);
                return;
            }

            if ($segments === ['inventory', 'movements']) {
                if ($method === 'GET') {
                    $productId = isset($_GET['product_id']) && $_GET['product_id'] !== ''
                        ? (string) $_GET['product_id']
                        : null;
                    $locationId = isset($_GET['location_id']) && $_GET['location_id'] !== ''
                        ? (string) $_GET['location_id']
                        : null;
                    $this->json(['data' => $this->movements->history($productId, $locationId)]);
                    return;
                }
                if ($method === 'POST') {
                    $this->json(['data' => $this->movements->record($input)], 201);
                    return;
                }
            }

            if (($segments[0] ?? '') === 'inventory' && ($segments[1] ?? '') === 'movements'
                && isset($segments[2]) && ($segments[3] ?? '') === 'reverse' && $method === 'POST') {
                $this->json(['data' => $this->movements->reverse(
                    $segments[2],
                    (string) ($input['reason'] ?? '')
                )], 201);
                return;
            }

            if (($segments[0] ?? '') === 'qr' && ($segments[1] ?? '') === 'unit'
                && isset($segments[2]) && $method === 'GET') {
                $unit = $this->productUnits->byQr($segments[2]);
                $this->json(['data' => $this->stock->unitPosition((string) $unit['id'])]);
                return;
            }

            if ($segments === ['workshop', 'jobs']) {
                if ($method === 'GET') {
                    $this->json([
                        'data' => $this->workshopJobs->all(),
                        'statuses' => WorkshopJobService::STATUSES,
                        'priorities' => WorkshopJobService::PRIORITIES,
                    ]);
                    return;
                }
                if ($method === 'POST') {
                    $this->json(['data' => $this->workshopJobs->create($input)], 201);
                    return;
                }
            }

            if (($segments[0] ?? '') === 'workshop' && ($segments[1] ?? '') === 'jobs' && isset($segments[2])) {
                $jobId = $segments[2];

                if (count($segments) === 3 && $method === 'GET') {
                    $job = $this->workshopJobs->get($jobId);
                    $this->json(['data' => [
                        'job' => $job,
                        'customer' => !empty($job['customer_id']) ? $this->store->get('customers', (string) $job['customer_id']) : null,
                        'product' => !empty($job['product_id']) ? $this->store->get('products', (string) $job['product_id']) : null,
                        'unit' => !empty($job['product_unit_id']) ? $this->stock->unitPosition((string) $job['product_unit_id']) : null,
                        'workshop_location' => !empty($job['workshop_location_id']) ? $this->store->get('locations', (string) $job['workshop_location_id']) : null,
                    ]]);
                    return;
                }

                if (count($segments) === 3 && in_array($method, ['PUT','PATCH'], true)) {
                    $this->json(['data' => $this->workshopJobs->update($jobId, $input)]);
                    return;
                }

                if (($segments[3] ?? '') === 'status' && in_array($method, ['PUT','PATCH'], true)) {
                    $this->json(['data' => $this->workshopJobs->changeStatus(
                        $jobId,
                        (string) ($input['status'] ?? '')
                    )]);
                    return;
                }

                if (($segments[3] ?? '') === 'check-in' && $method === 'POST') {
                    $this->json(['data' => $this->workshopJobs->checkIn($jobId)]);
                    return;
                }

                if (($segments[3] ?? '') === 'check-out' && $method === 'POST') {
                    $this->json(['data' => $this->workshopJobs->checkOut(
                        $jobId,
                        isset($input['destination_location_id']) ? (string) $input['destination_location_id'] : null
                    )]);
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

            if ($segments === ['reports'] && $method === 'GET') {
                $this->json(['data' => ReportingService::REPORTS]);
                return;
            }

            if (($segments[0] ?? '') === 'reports' && isset($segments[1]) && $method === 'GET') {
                $this->json(['data' => $this->reports->run($segments[1], [
                    'date_from' => $_GET['date_from'] ?? null,
                    'date_to' => $_GET['date_to'] ?? null,
                    'customer_id' => $_GET['customer_id'] ?? null,
                ])]);
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

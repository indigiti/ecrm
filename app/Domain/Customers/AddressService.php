<?php
declare(strict_types=1);

namespace Ecrm\Domain\Customers;

use Ecrm\Audit\AuditLedger;
use Ecrm\Domain\CRM\GeocodingQueue;
use Ecrm\Search\SearchIndex;
use Ecrm\Storage\AtomicJsonStore;
use Ecrm\Support\UuidV7;
use InvalidArgumentException;

final class AddressService
{
    private const TYPES = ['registered', 'billing', 'shipping', 'site', 'warehouse', 'office', 'other'];

    public function __construct(
        private AtomicJsonStore $store,
        private SearchIndex $search,
        private AuditLedger $audit,
        private ?GeocodingQueue $geocoding = null
    ) {}

    public function create(string $customerId, array $input): array
    {
        if (!$this->store->get('customers', $customerId)) {
            throw new InvalidArgumentException('Customer not found');
        }

        $type = strtolower(trim((string) ($input['type'] ?? 'site')));
        if (!in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException('Invalid address type');
        }

        $lines = trim((string) ($input['address'] ?? ''));
        if ($lines === '') {
            throw new InvalidArgumentException('Address is required');
        }

        $lat = isset($input['latitude']) && $input['latitude'] !== '' ? (float) $input['latitude'] : null;
        $lon = isset($input['longitude']) && $input['longitude'] !== '' ? (float) $input['longitude'] : null;
        if ($lat !== null && ($lat < -90 || $lat > 90)) throw new InvalidArgumentException('Invalid latitude');
        if ($lon !== null && ($lon < -180 || $lon > 180)) throw new InvalidArgumentException('Invalid longitude');

        $record = [
            'id' => UuidV7::generate(),
            'customer_id' => $customerId,
            'type' => $type,
            'label' => trim((string) ($input['label'] ?? ucfirst($type))),
            'address' => $lines,
            'area' => trim((string) ($input['area'] ?? '')),
            'landmark' => trim((string) ($input['landmark'] ?? '')),
            'city' => trim((string) ($input['city'] ?? '')),
            'state' => trim((string) ($input['state'] ?? '')),
            'pin' => trim((string) ($input['pin'] ?? '')),
            'country' => trim((string) ($input['country'] ?? 'India')),
            'latitude' => $lat,
            'longitude' => $lon,
            'geocode_status' => $lat !== null && $lon !== null ? 'resolved' : 'pending',
            'geocode_confidence' => $lat !== null && $lon !== null ? 1.0 : null,
            'created_at' => gmdate(DATE_ATOM),
            'updated_at' => gmdate(DATE_ATOM),
        ];

        $this->store->put('addresses', $record['id'], $record);
        $this->search->upsert('address', $record['id'], $record['label'], [
            $record['address'], $record['area'], $record['landmark'], $record['city'],
            $record['state'], $record['pin'], $record['country']
        ], ['customer_id' => $customerId, 'type' => $type]);
        $this->audit->append('address.created', 'address', $record['id'], ['customer_id' => $customerId]);

        if ($record['geocode_status'] === 'pending' && $this->geocoding) {
            $this->geocoding->enqueue($record);
        }

        return $record;
    }

    public function applyGeocode(string $id, float $latitude, float $longitude, ?float $confidence = null): array
    {
        if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
            throw new InvalidArgumentException('Invalid geocode coordinates');
        }
        $record = $this->store->get('addresses', $id);
        if (!$record) throw new InvalidArgumentException('Address not found');

        $record['latitude'] = $latitude;
        $record['longitude'] = $longitude;
        $record['geocode_status'] = 'resolved';
        $record['geocode_confidence'] = $confidence;
        $record['updated_at'] = gmdate(DATE_ATOM);
        $this->store->put('addresses', $id, $record);
        $this->audit->append('address.geocoded', 'address', $id, ['confidence' => $confidence]);
        return $record;
    }

    public function forCustomer(string $customerId): array
    {
        return $this->store->where('addresses', 'customer_id', $customerId);
    }

    public function mapped(): array
    {
        return array_values(array_filter($this->store->all('addresses'), static function(array $row): bool {
            return $row['latitude'] !== null && $row['longitude'] !== null;
        }));
    }
}

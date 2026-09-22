<?php
declare(strict_types=1);

namespace Ecrm\Domain\CRM;

use RuntimeException;

final class GeocodingQueue
{
    public function __construct(private string $jobsRoot) {}

    public function enqueue(array $address): void
    {
        $pending = rtrim($this->jobsRoot, '/') . '/pending';
        if (!is_dir($pending) && !mkdir($pending, 0770, true) && !is_dir($pending)) {
            throw new RuntimeException('Cannot create geocoding queue');
        }

        $job = [
            'type' => 'geocode_address',
            'address_id' => $address['id'],
            'customer_id' => $address['customer_id'],
            'geocode_token' => $address['geocode_token'] ?? null,
            'query' => trim(implode(', ', array_filter([
                $address['address'] ?? '',
                $address['area'] ?? '',
                $address['city'] ?? '',
                $address['state'] ?? '',
                $address['pin'] ?? '',
                $address['country'] ?? '',
            ]))),
            'created_at' => gmdate(DATE_ATOM),
            'attempts' => 0,
        ];

        $path = $pending . '/' . $address['id'] . '.json';
        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        file_put_contents($tmp, json_encode($job, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n", LOCK_EX);
        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('Could not queue geocoding job');
        }
    }
}

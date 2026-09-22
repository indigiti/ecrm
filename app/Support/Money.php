<?php
declare(strict_types=1);

namespace Ecrm\Support;

use InvalidArgumentException;

final class Money
{
    public static function toPaise(mixed $rupees): int
    {
        if ($rupees === null || $rupees === '') return 0;
        if (!is_numeric($rupees)) throw new InvalidArgumentException('Invalid monetary amount');
        return (int) round((float) $rupees * 100, 0, PHP_ROUND_HALF_UP);
    }

    public static function toBps(mixed $percent): int
    {
        if ($percent === null || $percent === '') return 0;
        if (!is_numeric($percent)) throw new InvalidArgumentException('Invalid percentage');
        $bps = (int) round((float) $percent * 100, 0, PHP_ROUND_HALF_UP);
        if ($bps < 0 || $bps > 10000) throw new InvalidArgumentException('Percentage must be between 0 and 100');
        return $bps;
    }

    public static function applyBps(int $amountPaise, int $bps): int
    {
        return (int) round($amountPaise * $bps / 10000, 0, PHP_ROUND_HALF_UP);
    }

    public static function rupees(int $paise): float
    {
        return round($paise / 100, 2);
    }
}

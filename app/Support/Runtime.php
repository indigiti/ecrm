<?php
declare(strict_types=1);

namespace Ecrm\Support;

final class Runtime
{
    public static function privateRoot(): string
    {
        if (defined('ECRM_PRIVATE_ROOT')) {
            return rtrim((string) ECRM_PRIVATE_ROOT, '/');
        }

        return dirname(__DIR__, 2);
    }

    public static function publicRoot(): string
    {
        if (defined('ECRM_PUBLIC_ROOT')) {
            return rtrim((string) ECRM_PUBLIC_ROOT, '/');
        }

        return dirname(__DIR__, 2) . '/public';
    }

    public static function dataRoot(): string
    {
        return self::privateRoot() . '/data';
    }

    public static function indexRoot(): string
    {
        return self::privateRoot() . '/indexes';
    }

    public static function auditRoot(): string
    {
        return self::privateRoot() . '/audit';
    }

    public static function jobsRoot(): string
    {
        return self::privateRoot() . '/jobs';
    }
}

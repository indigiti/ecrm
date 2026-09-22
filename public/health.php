<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$appRoot = dirname(__DIR__, 2);
$private = getenv('ECRM_PRIVATE_ROOT') ?: $appRoot . '/private_html/ecrm';
if (!is_dir($private) && is_dir(dirname(__DIR__) . '/app')) {
    $private = dirname(__DIR__);
}

$checks = [];
foreach (['data', 'indexes', 'uploads', 'audit', 'users', 'config', 'jobs', 'locks', 'backups', 'sessions'] as $name) {
    $path = rtrim($private, '/') . '/' . $name;
    if (!is_dir($path)) {
        @mkdir($path, 0770, true);
    }
    $checks[$name] = is_dir($path) && is_readable($path) && is_writable($path) ? 'ok' : 'attention';
}

$status = count(array_filter($checks, static fn(string $value): bool => $value !== 'ok')) === 0 ? 'ok' : 'attention';
http_response_code($status === 'ok' ? 200 : 503);

echo json_encode([
    'status' => $status,
    'app' => 'ecrm',
    'version' => '0.5.0',
    'checks' => $checks,
], JSON_UNESCAPED_SLASHES);

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

$manifestPath = __DIR__ . '/manifest.json';
$frontend = ['ok' => false, 'manifest' => false, 'entry' => null, 'missing' => []];
if (is_file($manifestPath)) {
    $frontend['manifest'] = true;
    $manifest = json_decode((string) file_get_contents($manifestPath), true);
    $entry = is_array($manifest) ? ($manifest['index.html'] ?? null) : null;
    if (is_array($entry) && !empty($entry['file'])) {
        $frontend['entry'] = (string) $entry['file'];
        $files = array_merge([(string) $entry['file']], array_values($entry['css'] ?? []));
        foreach ($files as $relative) {
            if (!is_file(__DIR__ . '/' . ltrim((string) $relative, '/'))) {
                $frontend['missing'][] = (string) $relative;
            }
        }
        $frontend['ok'] = $frontend['missing'] === [];
    }
}
$checks['frontend'] = $frontend;
$storageOk = true;
foreach (['data', 'indexes', 'uploads', 'audit', 'users', 'config', 'jobs', 'locks', 'backups', 'sessions'] as $name) {
    $path = rtrim($private, '/') . '/' . $name;
    if (!is_dir($path)) {
        @mkdir($path, 0770, true);
    }
    $checks[$name] = is_dir($path) && is_readable($path) && is_writable($path) ? 'ok' : 'attention';
    if ($checks[$name] !== 'ok') $storageOk = false;
}

$status = (($frontend['ok'] ?? false) && $storageOk) ? 'ok' : 'attention';
http_response_code($status === 'ok' ? 200 : 503);

echo json_encode([
    'status' => $status,
    'app' => 'ecrm',
    'version' => '0.6.0',
    'checks' => $checks,
], JSON_UNESCAPED_SLASHES);

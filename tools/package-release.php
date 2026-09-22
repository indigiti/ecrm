<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$release = $root . '/release';

function rrmdir(string $dir): void
{
    if (!is_dir($dir)) return;
    foreach (scandir($dir) ?: [] as $file) {
        if ($file === '.' || $file === '..') continue;
        $path = $dir . '/' . $file;
        is_dir($path) ? rrmdir($path) : unlink($path);
    }
    rmdir($dir);
}

function copytree(string $source, string $destination): void
{
    if (!is_dir($source)) return;
    if (!is_dir($destination)) mkdir($destination, 0775, true);
    foreach (scandir($source) ?: [] as $file) {
        if ($file === '.' || $file === '..') continue;
        $from = $source . '/' . $file;
        $to = $destination . '/' . $file;
        is_dir($from) ? copytree($from, $to) : copy($from, $to);
    }
}

rrmdir($release);
mkdir($release . '/public', 0775, true);
mkdir($release . '/private', 0775, true);
mkdir($release . '/private/build', 0775, true);

copytree($root . '/public', $release . '/public');
copytree($root . '/app', $release . '/private/app');
copytree($root . '/vendor', $release . '/private/vendor');
copytree($root . '/workers', $release . '/private/workers');

if (!is_dir($release . '/private/tools')) mkdir($release . '/private/tools', 0775, true);
foreach (['apply-geocodes.php', 'rebuild-search.php', 'verify-integrity.php'] as $tool) {
    if (is_file($root . '/tools/' . $tool)) {
        copy($root . '/tools/' . $tool, $release . '/private/tools/' . $tool);
    }
}

if (is_dir($root . '/dist/assets')) {
    copytree($root . '/dist/assets', $release . '/public/assets');
}
if (is_file($root . '/dist/.vite/manifest.json')) {
    if (!is_dir($release . '/public/.vite')) mkdir($release . '/public/.vite', 0775, true);
    copy($root . '/dist/.vite/manifest.json', $release . '/public/.vite/manifest.json');
}

$sha = trim((string) getenv('GITHUB_SHA')) ?: 'local';
$meta = [
    'app' => 'ecrm',
    'version' => '0.6.0',
    'source_sha' => $sha,
    'built_at' => gmdate(DATE_ATOM),
    'schema' => 2,
    'public_entry' => 'index.php',
    'health_endpoint' => 'health.php',
    'persistent_paths' => ['data', 'indexes', 'uploads', 'audit', 'users', 'config', 'jobs', 'locks', 'backups', 'sessions'],
    'runtime_commands' => [
        'geocode_provider' => 'python3 workers/geocode.py',
        'apply_geocodes' => 'php tools/apply-geocodes.php',
        'rebuild_search' => 'php tools/rebuild-search.php',
        'verify_integrity' => 'php tools/verify-integrity.php',
    ],
];

$json = json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
file_put_contents($release . '/RELEASE.json', $json);
file_put_contents($release . '/private/build/RELEASE.json', $json);

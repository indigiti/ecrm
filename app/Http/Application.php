<?php
declare(strict_types=1);

namespace Ecrm\Http;

use Ecrm\Support\Runtime;

final class Application
{
    public static function run(): void
    {
        self::securityHeaders();

        $path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        if (str_contains($path, '/api/')) {
            (new ApiController())->handle();
            return;
        }

        self::renderApp();
    }

    private static function renderApp(): void
    {
        header('Content-Type: text/html; charset=utf-8');

        $manifestPath = Runtime::publicRoot() . '/.vite/manifest.json';
        $manifest = is_file($manifestPath)
            ? (json_decode((string) file_get_contents($manifestPath), true) ?: [])
            : [];

        $entry = $manifest['index.html'] ?? null;
        $scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/ecrm/index.php');
        $base = rtrim(dirname($scriptName), '/');
        if ($base === '.' || $base === '/') {
            $base = '';
        }

        $styles = '';
        foreach (($entry['css'] ?? []) as $css) {
            $href = htmlspecialchars($base . '/' . ltrim((string) $css, '/'), ENT_QUOTES);
            $styles .= '<link rel="stylesheet" href="' . $href . '">';
        }

        $script = '';
        if (isset($entry['file'])) {
            $src = htmlspecialchars($base . '/' . ltrim((string) $entry['file'], '/'), ENT_QUOTES);
            $script = '<script type="module" src="' . $src . '"></script>';
        }

        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#f4f4f1"><title>eCRM</title>' . $styles . '</head><body><div id="app"><main style="font-family:system-ui;padding:2rem">Loading eCRM…</main></div>' . $script . '</body></html>';
    }

    private static function securityHeaders(): void
    {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: camera=(self), geolocation=(self)');
    }
}

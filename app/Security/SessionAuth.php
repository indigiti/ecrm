<?php
declare(strict_types=1);

namespace Ecrm\Security;

use Ecrm\Support\Runtime;
use RuntimeException;

final class SessionAuth
{
    public function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) return;

        $root = Runtime::sessionsRoot();
        if (!is_dir($root) && !mkdir($root, 0770, true) && !is_dir($root)) {
            throw new RuntimeException('Cannot create session directory');
        }

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_name('ecrm_session');
        session_save_path($root);

        $secure = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
            || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';

        $scriptName = (string) ($_SERVER['SCRIPT_NAME'] ?? '/');
        $cookiePath = rtrim(dirname($scriptName), '/');
        if ($cookiePath === '' || $cookiePath === '.') $cookiePath = '/';
        elseif ($cookiePath !== '/') $cookiePath .= '/';

        session_set_cookie_params([
            'lifetime' => 0,
            'path' => $cookiePath,
            'domain' => '',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);

        session_start();
        $this->csrfToken();
    }

    public function login(string $userId): void
    {
        $this->start();
        session_regenerate_id(true);
        $_SESSION['user_id'] = $userId;
        $_SESSION['authenticated_at'] = time();
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    public function logout(): void
    {
        $this->start();
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'],
                'domain' => $params['domain'],
                'secure' => (bool) $params['secure'],
                'httponly' => (bool) $params['httponly'],
                'samesite' => $params['samesite'] ?? 'Strict',
            ]);
        }

        session_destroy();
    }

    public function userId(): ?string
    {
        $this->start();
        $id = trim((string) ($_SESSION['user_id'] ?? ''));
        return $id !== '' ? $id : null;
    }

    public function csrfToken(): string
    {
        $this->start();
        $token = (string) ($_SESSION['csrf_token'] ?? '');
        if ($token === '') {
            $token = bin2hex(random_bytes(32));
            $_SESSION['csrf_token'] = $token;
        }
        return $token;
    }

    public function verifyCsrf(?string $token): bool
    {
        $this->start();
        $expected = (string) ($_SESSION['csrf_token'] ?? '');
        return $expected !== '' && is_string($token) && hash_equals($expected, $token);
    }
}

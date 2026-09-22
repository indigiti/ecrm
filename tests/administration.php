<?php
declare(strict_types=1);

$root = sys_get_temp_dir() . '/ecrm-admin-' . bin2hex(random_bytes(6));
mkdir($root, 0770, true);
define('ECRM_PRIVATE_ROOT', $root);
require dirname(__DIR__) . '/app/bootstrap.php';

use Ecrm\Audit\AuditLedger;
use Ecrm\Domain\Admin\SettingsService;
use Ecrm\Domain\Admin\UserService;
use Ecrm\Domain\Documents\DocumentService;
use Ecrm\Integrity\IntegrityVerifier;
use Ecrm\Search\SearchIndex;
use Ecrm\Search\SearchRebuilder;
use Ecrm\Security\SessionAuth;
use Ecrm\Storage\AtomicJsonStore;

function expectAdmin(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function expectAdminError(callable $callback, string $expected): void
{
    try {
        $callback();
        throw new RuntimeException('Expected error was not raised: ' . $expected);
    } catch (InvalidArgumentException $e) {
        expectAdmin($e->getMessage() === $expected, 'Unexpected error: ' . $e->getMessage());
    }
}

function cleanupAdmin(string $dir): void
{
    if (!is_dir($dir)) return;
    foreach (scandir($dir) ?: [] as $file) {
        if ($file === '.' || $file === '..') continue;
        $path = $dir . '/' . $file;
        is_dir($path) ? cleanupAdmin($path) : unlink($path);
    }
    rmdir($dir);
}

try {
    $data = new AtomicJsonStore($root . '/data');
    $userStore = new AtomicJsonStore($root . '/users');
    $configStore = new AtomicJsonStore($root . '/config');
    $search = new SearchIndex($root . '/indexes');
    $audit = new AuditLedger($root . '/audit');

    $users = new UserService($userStore, $audit);
    $settings = new SettingsService($configStore, $audit);
    $documents = new DocumentService($data, $search, $audit, $root . '/uploads');

    expectAdmin($users->count() === 0, 'Fresh installation should have no users');

    $admin = $users->createFirstAdmin([
        'name' => 'Primary Admin',
        'email' => 'admin@example.test',
        'password' => 'StrongPassword-123',
    ]);
    expectAdmin($admin['role'] === 'admin', 'First user must be admin');
    expectAdmin(!array_key_exists('password_hash', $admin), 'Password hash leaked in public user');
    expectAdmin($users->count() === 1, 'Initial admin count incorrect');

    expectAdminError(
        fn() => $users->createFirstAdmin([
            'name' => 'Second Initial Admin',
            'email' => 'second@example.test',
            'password' => 'StrongPassword-456',
        ]),
        'Initial admin is already configured'
    );

    expectAdmin($users->authenticate('admin@example.test', 'StrongPassword-123') !== null, 'Valid admin authentication failed');
    expectAdmin($users->authenticate('admin@example.test', 'wrong-password') === null, 'Invalid password authenticated');

    $manager = $users->create([
        'name' => 'Manager User',
        'email' => 'manager@example.test',
        'password' => 'ManagerPassword-123',
        'role' => 'manager',
    ]);
    expectAdmin($manager['role'] === 'manager', 'Manager role not stored');

    expectAdminError(
        fn() => $users->update($admin['id'], ['status' => 'inactive']),
        'At least one active admin is required'
    );

    $secondAdmin = $users->create([
        'name' => 'Backup Admin',
        'email' => 'backup@example.test',
        'password' => 'BackupPassword-123',
        'role' => 'admin',
    ]);
    $adminInactive = $users->update($admin['id'], ['status' => 'inactive']);
    expectAdmin($adminInactive['status'] === 'inactive', 'Admin deactivation failed after backup admin creation');

    $users->changePassword($manager['id'], 'UpdatedManager-456');
    expectAdmin($users->authenticate('manager@example.test', 'UpdatedManager-456') !== null, 'Password change authentication failed');

    $audit->setActor([
        'id' => $secondAdmin['id'],
        'name' => $secondAdmin['name'],
        'email' => $secondAdmin['email'],
        'role' => $secondAdmin['role'],
    ]);

    $company = $settings->update([
        'company_name' => 'Indigiti Test Company',
        'legal_name' => 'Indigiti Test Company Private Limited',
        'email' => 'accounts@example.test',
        'gstin' => '27ABCDE1234F1Z5',
        'financial_year_start_month' => 4,
        'default_tax_percent' => 18,
        'invoice_prefix' => 'INV',
        'quote_prefix' => 'QUO',
        'payment_prefix' => 'PAY',
    ]);
    expectAdmin($company['company_name'] === 'Indigiti Test Company', 'Company settings update failed');
    expectAdmin($company['default_tax_bps'] === 1800, 'Default tax basis points incorrect');

    expectAdminError(
        fn() => $settings->update(['financial_year_start_month' => 13]),
        'Financial year start month must be between 1 and 12'
    );

    $tmp = $root . '/upload-source.txt';
    file_put_contents($tmp, "eCRM administration document\n");
    $document = $documents->upload([
        'error' => UPLOAD_ERR_OK,
        'tmp_name' => $tmp,
        'size' => filesize($tmp),
        'name' => 'Admin Notes.txt',
    ], [
        'title' => 'Administration Notes',
        'entity_type' => 'settings',
        'entity_id' => 'primary',
        'category' => 'policy',
        'notes' => 'Persistent administration file',
    ]);

    expectAdmin($document['title'] === 'Administration Notes', 'Document title metadata was lost');
    expectAdmin($document['entity_type'] === 'settings', 'Document entity type metadata was lost');
    expectAdmin(strlen((string) $document['sha256']) === 64, 'Document checksum missing');
    expectAdmin(!array_key_exists('relative_path', $document), 'Private document storage path leaked');

    $stored = $documents->file($document['id']);
    expectAdmin(is_file($stored['path']), 'Stored document file missing');
    expectAdmin(hash_file('sha256', $stored['path']) === $document['sha256'], 'Stored document checksum mismatch');

    $rebuilt = (new SearchRebuilder($data, $search))->rebuild();
    expectAdmin(($rebuilt['documents'] ?? 0) === 1, 'Document search rebuild count incorrect');
    $matches = $search->search('Administration Notes');
    expectAdmin(count($matches) === 1 && ($matches[0]['type'] ?? '') === 'document', 'Document not searchable after rebuild');

    $integrity = (new IntegrityVerifier($data, $root . '/audit', $root . '/uploads'))->verify();
    expectAdmin($integrity['ok'] === true, 'Administration document integrity failed: ' . implode('; ', $integrity['errors']));
    expectAdmin(($integrity['checked']['documents'] ?? 0) === 1, 'Administration document integrity count incorrect');

    $archived = $documents->archive($document['id'], 'Superseded in test');
    expectAdmin($archived['status'] === 'archived', 'Document archive failed');

    $auth = new SessionAuth();
    $auth->start();
    $auth->login($secondAdmin['id']);
    expectAdmin($auth->userId() === $secondAdmin['id'], 'Session user ID not persisted');
    $csrf = $auth->csrfToken();
    expectAdmin(strlen($csrf) === 64, 'CSRF token format incorrect');
    expectAdmin($auth->verifyCsrf($csrf), 'Valid CSRF token rejected');
    expectAdmin(!$auth->verifyCsrf(str_repeat('0', 64)), 'Invalid CSRF token accepted');
    $auth->logout();

    $auditPath = $root . '/audit/events.jsonl';
    $events = array_values(array_filter(array_map(
        static fn(string $line): ?array => json_decode($line, true),
        file($auditPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []
    )));
    $attributed = array_values(array_filter($events, static fn(array $event): bool =>
        ($event['actor']['id'] ?? null) === $secondAdmin['id']
    ));
    expectAdmin(count($attributed) >= 2, 'Authenticated actor was not recorded in audit events');

    echo "Administration test passed\n";
} finally {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    cleanupAdmin($root);
}

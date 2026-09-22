<?php
declare(strict_types=1);

namespace Ecrm\Domain\Admin;

use Ecrm\Audit\AuditLedger;
use Ecrm\Storage\AtomicJsonStore;
use Ecrm\Support\UuidV7;
use InvalidArgumentException;

final class UserService
{
    public const ROLES = ['admin','manager','sales','accounts','user','read_only'];

    public function __construct(
        private AtomicJsonStore $store,
        private AuditLedger $audit
    ) {}

    public function count(): int
    {
        return count($this->store->all('accounts'));
    }

    public function createFirstAdmin(array $input): array
    {
        if ($this->count() !== 0) {
            throw new InvalidArgumentException('Initial admin is already configured');
        }
        return $this->createInternal($input, 'admin', true);
    }

    public function create(array $input): array
    {
        $role = strtolower(trim((string) ($input['role'] ?? 'user')));
        if (!in_array($role, self::ROLES, true)) {
            throw new InvalidArgumentException('Invalid user role');
        }
        return $this->createInternal($input, $role, false);
    }

    public function update(string $id, array $input): array
    {
        $record = $this->raw($id);

        if (array_key_exists('name', $input)) {
            $name = trim((string) $input['name']);
            if ($name === '') throw new InvalidArgumentException('User name is required');
            $record['name'] = $name;
        }

        if (array_key_exists('role', $input)) {
            $role = strtolower(trim((string) $input['role']));
            if (!in_array($role, self::ROLES, true)) throw new InvalidArgumentException('Invalid user role');
            if ($record['role'] === 'admin' && $role !== 'admin' && $this->activeAdminCount() <= 1) {
                throw new InvalidArgumentException('At least one active admin is required');
            }
            $record['role'] = $role;
        }

        if (array_key_exists('status', $input)) {
            $status = strtolower(trim((string) $input['status']));
            if (!in_array($status, ['active','inactive'], true)) throw new InvalidArgumentException('Invalid user status');
            if ($record['role'] === 'admin' && $status !== 'active' && $this->activeAdminCount() <= 1) {
                throw new InvalidArgumentException('At least one active admin is required');
            }
            $record['status'] = $status;
        }

        $record['updated_at'] = gmdate(DATE_ATOM);
        $this->store->put('accounts', $id, $record);
        $this->audit->append('user.updated', 'user', $id, [
            'role' => $record['role'],
            'status' => $record['status'],
        ]);
        return $this->public($record);
    }

    public function changePassword(string $id, string $password): array
    {
        $record = $this->raw($id);
        $this->validatePassword($password);

        $record['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
        $record['password_changed_at'] = gmdate(DATE_ATOM);
        $record['updated_at'] = gmdate(DATE_ATOM);
        $this->store->put('accounts', $id, $record);
        $this->audit->append('user.password_changed', 'user', $id);
        return $this->public($record);
    }

    public function authenticate(string $email, string $password): ?array
    {
        $email = strtolower(trim($email));
        if ($email === '' || $password === '') return null;

        foreach ($this->store->all('accounts') as $record) {
            if (strtolower((string) ($record['email'] ?? '')) !== $email) continue;
            if (($record['status'] ?? '') !== 'active') return null;
            if (!password_verify($password, (string) ($record['password_hash'] ?? ''))) return null;

            if (password_needs_rehash((string) $record['password_hash'], PASSWORD_DEFAULT)) {
                $record['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
            }
            $record['last_login_at'] = gmdate(DATE_ATOM);
            $record['updated_at'] = gmdate(DATE_ATOM);
            $this->store->put('accounts', (string) $record['id'], $record);
            return $this->public($record);
        }
        return null;
    }

    public function get(string $id): array
    {
        return $this->public($this->raw($id));
    }

    public function all(): array
    {
        return array_map(fn(array $row): array => $this->public($row), $this->store->all('accounts'));
    }

    public function isAllowed(array $user, array $roles): bool
    {
        return in_array((string) ($user['role'] ?? ''), $roles, true);
    }

    private function createInternal(array $input, string $role, bool $first): array
    {
        $name = trim((string) ($input['name'] ?? ''));
        $email = strtolower(trim((string) ($input['email'] ?? '')));
        $password = (string) ($input['password'] ?? '');

        if ($name === '') throw new InvalidArgumentException('User name is required');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Valid email is required');
        $this->validatePassword($password);

        foreach ($this->store->all('accounts') as $row) {
            if (strtolower((string) ($row['email'] ?? '')) === $email) {
                throw new InvalidArgumentException('Email is already in use');
            }
        }

        $now = gmdate(DATE_ATOM);
        $record = [
            'id' => UuidV7::generate(),
            'name' => $name,
            'email' => $email,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'role' => $role,
            'status' => 'active',
            'last_login_at' => null,
            'password_changed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $this->store->put('accounts', $record['id'], $record);
        $this->audit->append($first ? 'user.initial_admin_created' : 'user.created', 'user', $record['id'], [
            'email' => $email,
            'role' => $role,
        ]);
        return $this->public($record);
    }

    private function validatePassword(string $password): void
    {
        if (strlen($password) < 12) {
            throw new InvalidArgumentException('Password must be at least 12 characters');
        }
    }

    private function activeAdminCount(): int
    {
        return count(array_filter(
            $this->store->all('accounts'),
            static fn(array $row): bool => ($row['role'] ?? '') === 'admin' && ($row['status'] ?? '') === 'active'
        ));
    }

    private function raw(string $id): array
    {
        $record = $this->store->get('accounts', $id);
        if (!$record) throw new InvalidArgumentException('User not found');
        return $record;
    }

    private function public(array $record): array
    {
        unset($record['password_hash']);
        return $record;
    }
}

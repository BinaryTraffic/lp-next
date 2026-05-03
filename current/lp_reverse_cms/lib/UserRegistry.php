<?php

declare(strict_types=1);

/**
 * data/ws_* 単位で auth_users.json を保持（クローンとは独立したセッションワークスペース）。
 */
final class UserRegistry
{
    private string $filePath;

    private string $superAdmin;

    public function __construct(string $dataDir)
    {
        $this->filePath   = rtrim($dataDir, '/\\') . DIRECTORY_SEPARATOR . 'auth_users.json';
        $this->superAdmin = (string) getenv('CMS_SUPER_ADMIN');
    }

    /**
     * super_admin は .env 固定。それ以外は users.json + status。
     *
     * @return 'super_admin'|'admin'|'preview'|'pending'|'rejected'|null （未登録）
     */
    public function getRole(string $email): ?string
    {
        $emailNorm = strtolower(trim($email));

        if ($this->superAdmin !== '' && $emailNorm === strtolower(trim($this->superAdmin))) {
            return 'super_admin';
        }

        $user = $this->findUser($emailNorm);
        if ($user === null) {
            return null;
        }
        $st = isset($user['status']) ? (string) $user['status'] : '';

        if ($st === 'pending') {
            return 'pending';
        }
        if ($st === 'rejected') {
            return 'rejected';
        }
        if ($st !== 'approved') {
            return null;
        }
        $r = isset($user['role']) && is_string($user['role']) ? strtolower($user['role']) : 'preview';

        return in_array($r, ['admin', 'preview'], true) ? $r : 'preview';
    }

    public function registerPending(string $email, string $name): void
    {
        $emailNorm = strtolower(trim($email));

        if ($this->superAdmin !== '' && $emailNorm === strtolower(trim($this->superAdmin))) {
            return;
        }
        if ($this->findUser($emailNorm) !== null) {
            return;
        }

        $data = $this->load();

        $data['users'][] = [
            'email'        => $emailNorm,
            'name'         => $name,
            'role'         => null,
            'status'       => 'pending',
            'requested_at' => gmdate('c'),
        ];
        $this->save($data);
    }

    /** @param 'admin'|'preview' $role */
    public function approve(string $email, string $role, string $approvedBy): bool
    {
        if (!in_array($role, ['admin', 'preview'], true)) {
            return false;
        }
        $emailNorm = strtolower(trim($email));

        $data = $this->load();

        foreach ($data['users'] as &$u) {
            $em = strtolower((string) ($u['email'] ?? ''));

            if ($em !== $emailNorm) {
                continue;
            }

            $u['role']        = $role;
            $u['status']      = 'approved';
            $u['approved_at'] = gmdate('c');
            $u['approved_by'] = strtolower(trim($approvedBy));
            $this->save($data);

            return true;
        }

        return false;
    }

    public function reject(string $email, string $rejectedBy): bool
    {
        $emailNorm = strtolower(trim($email));
        $data      = $this->load();

        foreach ($data['users'] as &$u) {
            $em = strtolower((string) ($u['email'] ?? ''));

            if ($em !== $emailNorm) {
                continue;
            }

            $u['status']      = 'rejected';
            $u['rejected_by'] = strtolower(trim($rejectedBy));
            $u['rejected_at'] = gmdate('c');
            $this->save($data);

            return true;
        }

        return false;
    }

    public function remove(string $email): bool
    {
        $emailNorm = strtolower(trim($email));

        if ($this->superAdmin !== '' && $emailNorm === strtolower(trim($this->superAdmin))) {
            return false;
        }

        $data = $this->load();
        $prev = count($data['users']);
        /** @phpstan-ignore-next-line */
        $data['users'] = array_values(
            array_filter(
                $data['users'],
                static fn (mixed $raw): bool => !is_array($raw)
                  || strtolower((string) ($raw['email'] ?? '')) !== $emailNorm,
            ),
        );

        if (count($data['users']) >= $prev) {
            return false;
        }

        $this->save($data);

        return true;
    }

    /** @param 'admin'|'preview' $newRole */
    public function changeRole(string $email, string $newRole): bool
    {
        if (!in_array($newRole, ['admin', 'preview'], true)) {
            return false;
        }
        $emailNorm = strtolower(trim($email));

        $data = $this->load();

        foreach ($data['users'] as &$u) {
            $em = strtolower((string) ($u['email'] ?? ''));

            if ($em !== $emailNorm) {
                continue;
            }
            $st = (string) ($u['status'] ?? '');

            if ($st !== 'approved') {
                return false;
            }

            $u['role'] = $newRole;
            $this->save($data);

            return true;
        }

        return false;
    }

    /** @return list<array<string, mixed>> */
    public function getPending(): array
    {
        $out = [];
        foreach ($this->load()['users'] as $u) {
            if (!is_array($u)) {
                continue;
            }
            if (($u['status'] ?? '') !== 'pending') {
                continue;
            }
            $out[] = $u;
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    public function getApproved(): array
    {
        $out = [];

        foreach ($this->load()['users'] as $u) {
            if (!is_array($u)) {
                continue;
            }
            if (($u['status'] ?? '') !== 'approved') {
                continue;
            }
            $rawEm = strtolower((string) ($u['email'] ?? ''));

            if ($this->superAdmin !== '' && $rawEm === strtolower(trim($this->superAdmin))) {
                continue;
            }
            $out[] = $u;
        }

        return $out;
    }

    /** @return array<string, mixed>|null */
    private function findUser(string $emailNorm): ?array
    {
        foreach ($this->load()['users'] as $u) {
            if (!is_array($u)) {
                continue;
            }

            $em = strtolower(trim((string) ($u['email'] ?? '')));

            if ($em === $emailNorm) {
                return $u;
            }
        }

        return null;
    }

    /** @return array{users: list<mixed>} */
    private function load(): array
    {
        $dir = dirname($this->filePath);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        if (!file_exists($this->filePath) || !is_readable($this->filePath)) {
            return ['users' => []];
        }

        $raw = json_decode((string) file_get_contents($this->filePath), true);

        return is_array($raw) ? $this->normalizeRoot($raw) : ['users' => []];
    }

    /**
     * @param array<mixed,mixed> $raw
     *
     * @return array{users: list<mixed>}
     */
    private function normalizeRoot(array $raw): array
    {
        if (!isset($raw['users']) || !is_array($raw['users'])) {
            return ['users' => []];
        }

        /** @var list<mixed> $users */
        $users = array_values($raw['users']);

        return ['users' => $users];
    }

    /** @param array{users: list<mixed>} $data */
    private function save(array $data): void
    {
        $dir = dirname($this->filePath);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents(
            $this->filePath,
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            LOCK_EX,
        );
    }
}

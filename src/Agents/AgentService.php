<?php
declare(strict_types=1);

namespace Andrea\Helpdesk\Agents;

use Andrea\Helpdesk\Auth\PasswordService;
use Andrea\Helpdesk\Core\Exceptions\HttpException;

class AgentService
{
    public function __construct(
        private AgentRepository $repo,
        private PasswordService $passwordService = new PasswordService()
    ) {}

    public function create(array $data): array
    {
        $existing = $this->repo->findByEmail($data['email']);
        if ($existing) {
            throw new HttpException('An agent with this email already exists', 409);
        }

        if (!$this->passwordService->meetsRequirements($data['password'] ?? '')) {
            throw new HttpException('Password must be at least 8 characters', 400);
        }

        $createData = [
            'name'               => $data['name'],
            'email'              => $data['email'],
            'password_hash'      => $this->passwordService->hash($data['password']),
            'role'               => $data['role'] ?? 'agent',
            'can_close_tickets'  => (int)($data['can_close_tickets'] ?? 1),
            'can_delete_tickets' => (int)($data['can_delete_tickets'] ?? 0),
            'can_edit_customers' => (int)($data['can_edit_customers'] ?? 0),
            'can_view_reports'   => (int)($data['can_view_reports'] ?? 0),
            'can_manage_kb'      => (int)($data['can_manage_kb'] ?? 0),
            'signature'          => $data['signature'] ?? null,
        ];

        $id    = $this->repo->create($createData);
        $agent = $this->repo->findById($id);
        unset($agent['password_hash']);
        return $agent;
    }

    public function update(int $id, array $data): array
    {
        $updateData = [];
        $fields     = ['name', 'email', 'role', 'can_close_tickets', 'can_delete_tickets',
                       'can_edit_customers', 'can_view_reports', 'can_manage_kb', 'signature', 'is_active'];

        foreach ($fields as $field) {
            if (array_key_exists($field, $data)) {
                $updateData[$field] = $data[$field];
            }
        }

        if (!empty($data['password'])) {
            if (!$this->passwordService->meetsRequirements($data['password'])) {
                throw new HttpException('Password must be at least 8 characters', 400);
            }
            $updateData['password_hash'] = $this->passwordService->hash($data['password']);
        }

        // Don't allow removing the last admin
        if (isset($updateData['role']) && $updateData['role'] !== 'admin') {
            $current = $this->repo->findById($id);
            if ($current && $current['role'] === 'admin') {
                $adminCount = \Andrea\Helpdesk\Core\Database::getInstance()->count(
                    "SELECT COUNT(*) FROM agents WHERE role = 'admin' AND is_active = 1"
                );
                if ($adminCount <= 1) {
                    throw new HttpException('Cannot demote the last admin agent', 400);
                }
            }
        }

        $this->repo->update($id, $updateData);
        $agent = $this->repo->findById($id);
        unset($agent['password_hash']);
        return $agent;
    }

    public function resetPassword(int $agentId): string
    {
        $newPassword = $this->passwordService->generateTemporary();
        $hash        = $this->passwordService->hash($newPassword);
        $this->repo->update($agentId, ['password_hash' => $hash]);
        return $newPassword;
    }
}

<?php
declare(strict_types=1);

namespace Andrea\Helpdesk\Agents;

use Andrea\Helpdesk\Core\Database;

class AgentRepository
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function findById(int $id): ?array
    {
        return $this->db->fetch("SELECT * FROM agents WHERE id = ?", [$id]);
    }

    public function findByEmail(string $email): ?array
    {
        return $this->db->fetch("SELECT * FROM agents WHERE email = ?", [$email]);
    }

    public function findAll(bool $includeInactive = false): array
    {
        $where = $includeInactive ? '' : 'WHERE is_active = 1';
        return $this->db->fetchAll("SELECT * FROM agents {$where} ORDER BY name ASC");
    }

    public function getActiveAgents(): array
    {
        return $this->db->fetchAll(
            "SELECT id, name, email FROM agents WHERE is_active = 1 ORDER BY name ASC"
        );
    }

    public function create(array $data): int
    {
        return $this->db->insert(
            "INSERT INTO agents (name, email, password_hash, role, can_close_tickets, can_delete_tickets, can_edit_customers, can_view_reports, can_manage_kb, can_manage_tags, signature)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $data['name'],
                strtolower(trim($data['email'])),
                $data['password_hash'],
                $data['role'] ?? 'agent',
                $data['can_close_tickets'] ?? 1,
                $data['can_delete_tickets'] ?? 0,
                $data['can_edit_customers'] ?? 0,
                $data['can_view_reports'] ?? 0,
                $data['can_manage_kb'] ?? 0,
                $data['can_manage_tags'] ?? 0,
                $data['signature'] ?? null,
            ]
        );
    }

    public function update(int $id, array $data): bool
    {
        $allowed = ['name', 'email', 'password_hash', 'role', 'can_close_tickets',
                    'can_delete_tickets', 'can_edit_customers', 'can_view_reports', 'can_manage_kb', 'can_manage_tags', 'signature', 'page_size', 'theme', 'is_active'];
        $set     = [];
        $params  = [];

        $boolCols = ['can_close_tickets', 'can_delete_tickets', 'can_edit_customers',
                     'can_view_reports', 'can_manage_kb', 'can_manage_tags', 'is_active'];

        foreach ($allowed as $col) {
            if (array_key_exists($col, $data)) {
                $set[]    = "{$col} = ?";
                $params[] = in_array($col, $boolCols, true) ? (int)(bool)$data[$col] : $data[$col];
            }
        }

        if (empty($set)) return false;
        $params[] = $id;
        return $this->db->execute("UPDATE agents SET " . implode(', ', $set) . " WHERE id = ?", $params);
    }

    public function deactivate(int $id): bool
    {
        return $this->db->execute("UPDATE agents SET is_active = 0 WHERE id = ?", [$id]);
    }

    public function activate(int $id): bool
    {
        return $this->db->execute("UPDATE agents SET is_active = 1 WHERE id = ?", [$id]);
    }

    public function updateLastLogin(int $id): bool
    {
        return $this->db->execute("UPDATE agents SET last_login_at = NOW() WHERE id = ?", [$id]);
    }
}

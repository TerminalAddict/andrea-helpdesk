<?php
declare(strict_types=1);

namespace Andrea\Helpdesk\Customers;

use Andrea\Helpdesk\Core\Database;

class CustomerRepository
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function findById(int $id): ?array
    {
        return $this->db->fetch(
            "SELECT * FROM customers WHERE id = ? AND deleted_at IS NULL",
            [$id]
        );
    }

    public function findByEmail(string $email): ?array
    {
        return $this->db->fetch(
            "SELECT * FROM customers WHERE email = ? AND deleted_at IS NULL",
            [strtolower(trim($email))]
        );
    }

    public function findAll(array $filters = [], int $page = 1, int $perPage = 25): array
    {
        $where  = ['deleted_at IS NULL'];
        $params = [];

        if (!empty($filters['q'])) {
            $where[]  = '(name LIKE ? OR email LIKE ? OR company LIKE ? OR phone LIKE ?)';
            $q        = '%' . $filters['q'] . '%';
            $params   = array_merge($params, [$q, $q, $q, $q]);
        }

        if (!empty($filters['company'])) {
            $where[]  = 'company = ?';
            $params[] = $filters['company'];
        }

        $whereClause = implode(' AND ', $where);
        $total       = $this->db->count("SELECT COUNT(*) FROM customers WHERE {$whereClause}", $params);
        $offset      = ($page - 1) * $perPage;

        $items = $this->db->fetchAll(
            "SELECT c.*,
                    (SELECT COUNT(*) FROM tickets WHERE customer_id = c.id AND deleted_at IS NULL) AS ticket_count
             FROM customers c
             WHERE {$whereClause}
             ORDER BY c.name ASC
             LIMIT {$perPage} OFFSET {$offset}",
            $params
        );

        return ['items' => $items, 'total' => $total];
    }

    public function create(array $data): int
    {
        return $this->db->insert(
            "INSERT INTO customers (name, email, phone, company, notes) VALUES (?, ?, ?, ?, ?)",
            [
                $data['name'],
                strtolower(trim($data['email'])),
                $data['phone'] ?? null,
                $data['company'] ?? null,
                $data['notes'] ?? null,
            ]
        );
    }

    public function update(int $id, array $data): bool
    {
        $allowed = ['name', 'email', 'phone', 'company', 'notes'];
        $set     = [];
        $params  = [];

        foreach ($allowed as $col) {
            if (array_key_exists($col, $data)) {
                $set[]    = "{$col} = ?";
                $params[] = $data[$col];
            }
        }

        if (empty($set)) return false;
        $params[] = $id;
        return $this->db->execute("UPDATE customers SET " . implode(', ', $set) . " WHERE id = ?", $params);
    }

    public function softDelete(int $id): bool
    {
        return $this->db->execute("UPDATE customers SET deleted_at = NOW() WHERE id = ?", [$id]);
    }

    public function upsertByEmail(string $email, string $name = '', array $extra = []): array
    {
        $email    = strtolower(trim($email));
        $existing = $this->findByEmail($email);

        if ($existing) {
            // Update name if currently empty
            if (empty($existing['name']) && $name) {
                $this->update($existing['id'], ['name' => $name]);
                $existing['name'] = $name;
            }
            return $existing;
        }

        $id = $this->create(array_merge([
            'name'    => $name ?: $email,
            'email'   => $email,
            'phone'   => $extra['phone'] ?? null,
            'company' => $extra['company'] ?? null,
        ], $extra));

        return $this->findById($id) ?? [];
    }

    public function setPortalToken(int $id, string $token, \DateTime $expires): bool
    {
        return $this->db->execute(
            "UPDATE customers SET portal_token = ?, portal_token_expires = ? WHERE id = ?",
            [$token, $expires->format('Y-m-d H:i:s'), $id]
        );
    }

    public function clearPortalToken(int $id): bool
    {
        return $this->db->execute(
            "UPDATE customers SET portal_token = NULL, portal_token_expires = NULL WHERE id = ?",
            [$id]
        );
    }

    public function findByPortalToken(string $token): ?array
    {
        return $this->db->fetch(
            "SELECT * FROM customers WHERE portal_token = ? AND portal_token_expires > NOW() AND deleted_at IS NULL",
            [hash('sha256', $token)]
        );
    }

    public function getTickets(int $customerId, int $page = 1, int $perPage = 25): array
    {
        $total  = $this->db->count("SELECT COUNT(*) FROM tickets WHERE customer_id = ? AND deleted_at IS NULL", [$customerId]);
        $offset = ($page - 1) * $perPage;

        $items = $this->db->fetchAll(
            "SELECT t.*, a.name AS agent_name FROM tickets t
             LEFT JOIN agents a ON a.id = t.assigned_agent_id
             WHERE t.customer_id = ? AND t.deleted_at IS NULL
             ORDER BY t.updated_at DESC LIMIT {$perPage} OFFSET {$offset}",
            [$customerId]
        );

        return ['items' => $items, 'total' => $total];
    }

    public function getReplies(int $customerId, int $page = 1, int $perPage = 25): array
    {
        $total  = $this->db->count(
            "SELECT COUNT(*) FROM replies WHERE customer_id = ? AND author_type = 'customer'",
            [$customerId]
        );
        $offset = ($page - 1) * $perPage;

        $items = $this->db->fetchAll(
            "SELECT r.id, r.ticket_id, r.body_text, r.body_html, r.created_at,
                    t.ticket_number, t.subject
             FROM replies r
             JOIN tickets t ON t.id = r.ticket_id
             WHERE r.customer_id = ? AND r.author_type = 'customer'
             ORDER BY r.created_at DESC LIMIT {$perPage} OFFSET {$offset}",
            [$customerId]
        );

        return ['items' => $items, 'total' => $total];
    }
}

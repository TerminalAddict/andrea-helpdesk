<?php
declare(strict_types=1);

namespace Andrea\Helpdesk\Tickets;

use Andrea\Helpdesk\Core\Database;

class TicketRepository
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function findById(int $id): ?array
    {
        return $this->db->fetch(
            "SELECT t.*,
                    c.name AS customer_name, c.email AS customer_email, c.company AS customer_company,
                    a.name AS agent_name
             FROM tickets t
             LEFT JOIN customers c ON c.id = t.customer_id
             LEFT JOIN agents a ON a.id = t.assigned_agent_id
             WHERE t.id = ? AND t.deleted_at IS NULL",
            [$id]
        );
    }

    public function findByTicketNumber(string $number): ?array
    {
        return $this->db->fetch(
            "SELECT t.*, c.name AS customer_name, c.email AS customer_email
             FROM tickets t
             LEFT JOIN customers c ON c.id = t.customer_id
             WHERE t.ticket_number = ? AND t.deleted_at IS NULL",
            [$number]
        );
    }

    public function findByMessageId(string $messageId): ?array
    {
        return $this->db->fetch(
            "SELECT * FROM tickets
             WHERE (original_message_id = ? OR last_message_id = ?) AND deleted_at IS NULL",
            [$messageId, $messageId]
        );
    }

    public function findAll(array $filters = [], int $page = 1, int $perPage = 25): array
    {
        $where  = ['t.deleted_at IS NULL'];
        $params = [];

        if (!empty($filters['status'])) {
            $where[]  = 't.status = ?';
            $params[] = $filters['status'];
        }

        if (!empty($filters['priority'])) {
            $where[]  = 't.priority = ?';
            $params[] = $filters['priority'];
        }

        if (!empty($filters['assigned_agent_id'])) {
            if ($filters['assigned_agent_id'] === 'unassigned') {
                $where[] = 't.assigned_agent_id IS NULL';
            } else {
                $where[]  = 't.assigned_agent_id = ?';
                $params[] = (int)$filters['assigned_agent_id'];
            }
        }

        if (!empty($filters['customer_id'])) {
            $where[]  = 't.customer_id = ?';
            $params[] = (int)$filters['customer_id'];
        }

        if (!empty($filters['channel'])) {
            $where[]  = 't.channel = ?';
            $params[] = $filters['channel'];
        }

        if (!empty($filters['q'])) {
            $where[]  = '(t.subject LIKE ? OR t.ticket_number LIKE ? OR c.name LIKE ? OR c.email LIKE ?
                          OR EXISTS (SELECT 1 FROM replies r WHERE r.ticket_id = t.id AND (r.body_text LIKE ? OR r.body_html LIKE ?)))';
            $q        = '%' . $filters['q'] . '%';
            $params   = array_merge($params, [$q, $q, $q, $q, $q, $q]);
        }

        if (!empty($filters['from_date'])) {
            $where[]  = 'DATE(t.created_at) >= ?';
            $params[] = $filters['from_date'];
        }

        if (!empty($filters['to_date'])) {
            $where[]  = 'DATE(t.created_at) <= ?';
            $params[] = $filters['to_date'];
        }

        if (!empty($filters['tag_id'])) {
            $where[]  = 'EXISTS (SELECT 1 FROM ticket_tag_map WHERE ticket_id = t.id AND tag_id = ?)';
            $params[] = (int)$filters['tag_id'];
        }

        $whereClause = implode(' AND ', $where);
        $sort        = in_array($filters['sort'] ?? '', ['created_at', 'updated_at', 'priority', 'status'], true)
            ? $filters['sort'] : 'updated_at';
        $dir         = ($filters['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';

        $total = $this->db->count(
            "SELECT COUNT(*) FROM tickets t LEFT JOIN customers c ON c.id = t.customer_id WHERE {$whereClause}",
            $params
        );

        $offset = ($page - 1) * $perPage;
        $items  = $this->db->fetchAll(
            "SELECT t.*, c.name AS customer_name, c.email AS customer_email,
                    a.name AS agent_name,
                    GROUP_CONCAT(tg.name ORDER BY tg.name SEPARATOR ',') AS tag_names,
                    (SELECT COUNT(*) FROM replies r WHERE r.ticket_id = t.id AND r.author_type != 'system') AS reply_count
             FROM tickets t
             LEFT JOIN customers c ON c.id = t.customer_id
             LEFT JOIN agents a ON a.id = t.assigned_agent_id
             LEFT JOIN ticket_tag_map ttm ON ttm.ticket_id = t.id
             LEFT JOIN tags tg ON tg.id = ttm.tag_id
             WHERE {$whereClause}
             GROUP BY t.id
             ORDER BY t.{$sort} {$dir}
             LIMIT {$perPage} OFFSET {$offset}",
            $params
        );

        return ['items' => $items, 'total' => $total];
    }

    public function create(array $data): int
    {
        return $this->db->insert(
            "INSERT INTO tickets (ticket_number, subject, status, priority, channel, customer_id,
             assigned_agent_id, original_message_id, last_message_id, reply_to_address, parent_ticket_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $data['ticket_number'],
                $data['subject'],
                $data['status'] ?? 'open',
                $data['priority'] ?? 'normal',
                $data['channel'] ?? 'email',
                $data['customer_id'],
                $data['assigned_agent_id'] ?? null,
                $data['original_message_id'] ?? null,
                $data['last_message_id'] ?? null,
                $data['reply_to_address'] ?? null,
                $data['parent_ticket_id'] ?? null,
            ]
        );
    }

    public function update(int $id, array $data): bool
    {
        $allowed = ['subject', 'status', 'priority', 'assigned_agent_id', 'closed_at',
                    'first_response_at', 'last_message_id', 'merged_into_id'];
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
        return $this->db->execute(
            "UPDATE tickets SET " . implode(', ', $set) . " WHERE id = ?",
            $params
        );
    }

    public function softDelete(int $id): bool
    {
        $this->db->beginTransaction();
        try {
            $this->db->execute("DELETE FROM ticket_participants WHERE ticket_id = ?", [$id]);
            $this->db->execute("DELETE FROM ticket_tag_map WHERE ticket_id = ?", [$id]);
            $result = $this->db->execute("UPDATE tickets SET deleted_at = NOW() WHERE id = ?", [$id]);
            $this->db->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    public function generateTicketNumber(string $prefix, string $date): string
    {
        // INSERT ... ON DUPLICATE KEY UPDATE is atomic at the row level — no wrapping
        // transaction needed, and adding one here would conflict with any caller that
        // already has an open transaction (e.g. createFromEmail in TicketService).
        $this->db->execute(
            "INSERT INTO ticket_number_sequences (date_key, last_seq) VALUES (?, 1)
             ON DUPLICATE KEY UPDATE last_seq = last_seq + 1",
            [$date]
        );
        $row = $this->db->fetch(
            "SELECT last_seq FROM ticket_number_sequences WHERE date_key = ?",
            [$date]
        );
        $seq = str_pad((string)$row['last_seq'], 4, '0', STR_PAD_LEFT);
        return "{$prefix}-{$date}-{$seq}";
    }

    public function getParticipants(int $ticketId): array
    {
        return $this->db->fetchAll(
            "SELECT tp.*, c.name AS customer_name
             FROM ticket_participants tp
             LEFT JOIN customers c ON c.id = tp.customer_id
             WHERE tp.ticket_id = ?",
            [$ticketId]
        );
    }

    public function addParticipant(int $ticketId, string $email, string $name = '', string $role = 'cc', ?int $customerId = null): int
    {
        try {
            return $this->db->insert(
                "INSERT INTO ticket_participants (ticket_id, email, name, role, customer_id) VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE name = VALUES(name), customer_id = COALESCE(VALUES(customer_id), customer_id)",
                [$ticketId, $email, $name, $role, $customerId]
            );
        } catch (\Throwable) {
            return 0;
        }
    }

    public function removeParticipant(int $participantId): bool
    {
        return $this->db->execute("DELETE FROM ticket_participants WHERE id = ?", [$participantId]);
    }

    public function getRelations(int $ticketId): array
    {
        return $this->db->fetchAll(
            "SELECT t.id, t.ticket_number, t.subject, t.status
             FROM ticket_relations r
             JOIN tickets t ON t.id = IF(r.ticket_a_id = ?, r.ticket_b_id, r.ticket_a_id)
             WHERE (r.ticket_a_id = ? OR r.ticket_b_id = ?) AND t.deleted_at IS NULL",
            [$ticketId, $ticketId, $ticketId]
        );
    }

    public function addRelation(int $ticketIdA, int $ticketIdB): bool
    {
        $a = min($ticketIdA, $ticketIdB);
        $b = max($ticketIdA, $ticketIdB);
        try {
            return $this->db->execute(
                "INSERT IGNORE INTO ticket_relations (ticket_a_id, ticket_b_id) VALUES (?, ?)",
                [$a, $b]
            );
        } catch (\Throwable) {
            return false;
        }
    }

    public function removeRelation(int $ticketIdA, int $ticketIdB): bool
    {
        $a = min($ticketIdA, $ticketIdB);
        $b = max($ticketIdA, $ticketIdB);
        return $this->db->execute(
            "DELETE FROM ticket_relations WHERE ticket_a_id = ? AND ticket_b_id = ?",
            [$a, $b]
        );
    }

    public function addTag(int $ticketId, int $tagId): bool
    {
        return $this->db->execute(
            "INSERT IGNORE INTO ticket_tag_map (ticket_id, tag_id) VALUES (?, ?)",
            [$ticketId, $tagId]
        );
    }

    public function removeTag(int $ticketId, int $tagId): bool
    {
        return $this->db->execute(
            "DELETE FROM ticket_tag_map WHERE ticket_id = ? AND tag_id = ?",
            [$ticketId, $tagId]
        );
    }

    public function getTags(int $ticketId): array
    {
        return $this->db->fetchAll(
            "SELECT t.* FROM tags t
             JOIN ticket_tag_map m ON m.tag_id = t.id
             WHERE m.ticket_id = ?",
            [$ticketId]
        );
    }

    public function countByStatus(): array
    {
        $rows   = $this->db->fetchAll(
            "SELECT status, COUNT(*) as count FROM tickets WHERE deleted_at IS NULL GROUP BY status"
        );
        $result = ['open' => 0, 'pending' => 0, 'resolved' => 0, 'closed' => 0];
        foreach ($rows as $row) {
            $result[$row['status']] = (int)$row['count'];
        }
        return $result;
    }

    public function getRecentByAgent(int $agentId, int $limit = 10): array
    {
        return $this->db->fetchAll(
            "SELECT t.*, c.name AS customer_name FROM tickets t
             LEFT JOIN customers c ON c.id = t.customer_id
             WHERE t.assigned_agent_id = ? AND t.deleted_at IS NULL
             ORDER BY t.updated_at DESC LIMIT ?",
            [$agentId, $limit]
        );
    }

    public function getChildTickets(int $parentId): array
    {
        return $this->db->fetchAll(
            "SELECT t.*, c.name AS customer_name FROM tickets t
             LEFT JOIN customers c ON c.id = t.customer_id
             WHERE t.parent_ticket_id = ? AND t.deleted_at IS NULL",
            [$parentId]
        );
    }
}

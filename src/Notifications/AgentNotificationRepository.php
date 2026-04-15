<?php
declare(strict_types=1);

namespace Andrea\Helpdesk\Notifications;

use Andrea\Helpdesk\Core\Database;

class AgentNotificationRepository
{
    private Database $db;
    private const TICKET_ACTIVE_TYPES = [
        'ticket_created',
        'customer_reply',
        'ticket_assigned',
        'agent_mentioned',
        'sla_escalated',
        'ticket_overdue',
    ];

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function createForAgents(array $agentIds, array $payload): void
    {
        $agentIds = array_values(array_unique(array_filter(array_map('intval', $agentIds), fn(int $id): bool => $id > 0)));
        if (!$agentIds) {
            return;
        }

        $sql = "INSERT IGNORE INTO agent_notifications
                   (agent_id, type, severity, title, body, link, data_json, dedupe_key)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)";

        $dataJson = isset($payload['data']) ? json_encode($payload['data']) : null;
        foreach ($agentIds as $agentId) {
            $dedupeKey = isset($payload['dedupe_key']) && $payload['dedupe_key'] !== null
                ? (string)$payload['dedupe_key']
                : null;
            $this->db->insert($sql, [
                $agentId,
                (string)$payload['type'],
                (string)($payload['severity'] ?? 'info'),
                (string)$payload['title'],
                $payload['body'] ?? null,
                $payload['link'] ?? null,
                $dataJson,
                $dedupeKey,
            ]);
        }
    }

    public function listForAgent(int $agentId, int $limit = 12, ?int $afterId = null): array
    {
        $limit = max(1, min(50, $limit));
        if ($afterId !== null && $afterId > 0) {
            return $this->db->fetchAll(
                "SELECT id, type, severity, title, body, link, data_json, read_at, created_at
                   FROM agent_notifications
                  WHERE agent_id = ? AND id > ?
               ORDER BY id ASC
                  LIMIT {$limit}",
                [$agentId, $afterId]
            );
        }

        return $this->db->fetchAll(
            "SELECT id, type, severity, title, body, link, data_json, read_at, created_at
               FROM agent_notifications
              WHERE agent_id = ?
           ORDER BY id DESC
              LIMIT {$limit}",
            [$agentId]
        );
    }

    public function listActiveTicketNotificationsForAgent(
        int $agentId,
        int $limit = 50,
        ?int $afterId = null,
        bool $unreadOnly = false
    ): array {
        $limit = max(1, min(250, $limit));
        $params = [$agentId];
        $where = [
            'n.agent_id = ?',
            "n.type IN ('" . implode("','", self::TICKET_ACTIVE_TYPES) . "')",
            't.id IS NOT NULL',
            't.deleted_at IS NULL',
            "t.status NOT IN ('resolved', 'closed')",
            '(' . $this->ticketActiveTypeClause('n', 't') . ')',
        ];

        if ($unreadOnly) {
            $where[] = 'n.read_at IS NULL';
        }

        $order = 'n.id DESC';
        if ($afterId !== null && $afterId > 0) {
            $where[] = 'n.id > ?';
            $params[] = $afterId;
            $order = 'n.id ASC';
        }

        return $this->db->fetchAll(
            "SELECT n.id, n.type, n.severity, n.title, n.body, n.link, n.data_json, n.read_at, n.created_at
               FROM agent_notifications n
               LEFT JOIN tickets t
                 ON t.id = CAST(JSON_UNQUOTE(JSON_EXTRACT(n.data_json, '$.ticket_id')) AS UNSIGNED)
              WHERE " . implode(' AND ', $where) . "
           ORDER BY {$order}
              LIMIT {$limit}",
            $params
        );
    }

    public function countActiveTicketNotificationsForAgent(int $agentId, bool $unreadOnly = false): int
    {
        $params = [$agentId];
        $where = [
            'n.agent_id = ?',
            "n.type IN ('" . implode("','", self::TICKET_ACTIVE_TYPES) . "')",
            't.id IS NOT NULL',
            't.deleted_at IS NULL',
            "t.status NOT IN ('resolved', 'closed')",
            '(' . $this->ticketActiveTypeClause('n', 't') . ')',
        ];

        if ($unreadOnly) {
            $where[] = 'n.read_at IS NULL';
        }

        return $this->db->count(
            "SELECT COUNT(*)
               FROM agent_notifications n
               LEFT JOIN tickets t
                 ON t.id = CAST(JSON_UNQUOTE(JSON_EXTRACT(n.data_json, '$.ticket_id')) AS UNSIGNED)
              WHERE " . implode(' AND ', $where),
            $params
        );
    }

    public function latestUpdateNotificationForAgent(int $agentId, bool $unreadOnly = false, ?int $afterId = null): ?array
    {
        $params = [$agentId];
        $where = [
            'agent_id = ?',
            "type = 'update_available'",
        ];

        if ($unreadOnly) {
            $where[] = 'read_at IS NULL';
        }

        if ($afterId !== null && $afterId > 0) {
            $where[] = 'id > ?';
            $params[] = $afterId;
        }

        return $this->db->fetch(
            "SELECT id, type, severity, title, body, link, data_json, read_at, created_at
               FROM agent_notifications
              WHERE " . implode(' AND ', $where) . "
           ORDER BY id DESC
              LIMIT 1",
            $params
        );
    }

    public function unreadCount(int $agentId): int
    {
        return $this->db->count(
            "SELECT COUNT(*) FROM agent_notifications WHERE agent_id = ? AND read_at IS NULL",
            [$agentId]
        );
    }

    public function markRead(int $agentId, int $notificationId): bool
    {
        return $this->db->execute(
            "UPDATE agent_notifications
                SET read_at = COALESCE(read_at, NOW())
              WHERE id = ? AND agent_id = ?",
            [$notificationId, $agentId]
        );
    }

    public function markAllRead(int $agentId): bool
    {
        return $this->db->execute(
            "UPDATE agent_notifications
                SET read_at = NOW()
              WHERE agent_id = ? AND read_at IS NULL",
            [$agentId]
        );
    }

    private function ticketActiveTypeClause(string $notificationAlias, string $ticketAlias): string
    {
        return implode(' OR ', [
            "{$notificationAlias}.type IN ('ticket_created','customer_reply','ticket_assigned','agent_mentioned')",
            "({$notificationAlias}.type = 'sla_escalated' AND {$ticketAlias}.priority IN ('high', 'urgent', 'overdue'))",
            "({$notificationAlias}.type = 'ticket_overdue' AND {$ticketAlias}.priority = 'overdue')",
        ]);
    }
}

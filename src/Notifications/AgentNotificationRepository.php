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
        'ticket_internal_note',
        'ticket_sla_overdue',
        'ticket_due_overdue',
    ];

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function createForAgents(array $agentIds, array $payload): array
    {
        $agentIds = array_values(array_unique(array_filter(array_map('intval', $agentIds), fn(int $id): bool => $id > 0)));
        if (!$agentIds) {
            return [];
        }

        $sql = "INSERT IGNORE INTO agent_notifications
                   (agent_id, type, severity, title, body, link, data_json, dedupe_key)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)";

        $dataJson = isset($payload['data']) ? json_encode($payload['data']) : null;
        $createdAgentIds = [];
        foreach ($agentIds as $agentId) {
            $dedupeKey = isset($payload['dedupe_key']) && $payload['dedupe_key'] !== null
                ? (string)$payload['dedupe_key']
                : null;
            $notificationId = $this->db->insert($sql, [
                $agentId,
                (string)$payload['type'],
                (string)($payload['severity'] ?? 'info'),
                (string)$payload['title'],
                $payload['body'] ?? null,
                $payload['link'] ?? null,
                $dataJson,
                $dedupeKey,
            ]);
            if ($notificationId > 0) {
                $createdAgentIds[] = $agentId;
            }
        }

        return $createdAgentIds;
    }

    public function listForAgent(int $agentId, int $limit = 12, ?int $afterId = null): array
    {
        $limit = max(1, min(50, $limit));
        if ($afterId !== null && $afterId > 0) {
            return $this->db->fetchAll(
            "SELECT id, type, severity, title, body, link, data_json, created_at
               FROM agent_notifications
              WHERE agent_id = ? AND id > ?
               ORDER BY id ASC
                  LIMIT {$limit}",
                [$agentId, $afterId]
            );
        }

        return $this->db->fetchAll(
            "SELECT id, type, severity, title, body, link, data_json, created_at
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
        ?int $afterId = null
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

        $order = 'n.id DESC';
        if ($afterId !== null && $afterId > 0) {
            $where[] = 'n.id > ?';
            $params[] = $afterId;
            $order = 'n.id ASC';
        }

        return $this->db->fetchAll(
            "SELECT n.id, n.type, n.severity, n.title, n.body, n.link, n.data_json, n.created_at
               FROM agent_notifications n
               LEFT JOIN tickets t
                 ON t.id = CAST(JSON_UNQUOTE(JSON_EXTRACT(n.data_json, '$.ticket_id')) AS UNSIGNED)
              WHERE " . implode(' AND ', $where) . "
           ORDER BY {$order}
              LIMIT {$limit}",
            $params
        );
    }

    public function countActiveTicketNotificationsForAgent(int $agentId): int
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

        return $this->db->count(
            "SELECT COUNT(*)
               FROM agent_notifications n
               LEFT JOIN tickets t
                 ON t.id = CAST(JSON_UNQUOTE(JSON_EXTRACT(n.data_json, '$.ticket_id')) AS UNSIGNED)
              WHERE " . implode(' AND ', $where),
            $params
        );
    }

    public function listActiveChatNotificationsForAgent(int $agentId, int $limit = 50, ?int $afterId = null): array
    {
        $limit = max(1, min(250, $limit));
        $params = [$agentId];
        $where = [
            'agent_id = ?',
            "type IN ('chat_mention','chat_direct_message','chat_channel_message')",
        ];
        $order = 'id DESC';

        if ($afterId !== null && $afterId > 0) {
            $where[] = 'id > ?';
            $params[] = $afterId;
            $order = 'id ASC';
        }

        return $this->db->fetchAll(
            "SELECT id, type, severity, title, body, link, data_json, created_at
               FROM agent_notifications
              WHERE " . implode(' AND ', $where) . "
           ORDER BY {$order}
              LIMIT {$limit}",
            $params
        );
    }

    public function countActiveChatNotificationsForAgent(int $agentId): int
    {
        return $this->db->count(
            "SELECT COUNT(*)
               FROM agent_notifications
              WHERE agent_id = ?
                AND type IN ('chat_mention','chat_direct_message','chat_channel_message')",
            [$agentId]
        );
    }

    public function latestUpdateNotificationForAgent(int $agentId, ?int $afterId = null): ?array
    {
        $params = [$agentId];
        $where = [
            'agent_id = ?',
            "type = 'update_available'",
        ];

        if ($afterId !== null && $afterId > 0) {
            $where[] = 'id > ?';
            $params[] = $afterId;
        }

        return $this->db->fetch(
            "SELECT id, type, severity, title, body, link, data_json, created_at
               FROM agent_notifications
              WHERE " . implode(' AND ', $where) . "
           ORDER BY id DESC
              LIMIT 1",
            $params
        );
    }

    public function deleteForAgent(int $agentId, int $notificationId): bool
    {
        return $this->db->execute(
            "DELETE FROM agent_notifications WHERE id = ? AND agent_id = ?",
            [$notificationId, $agentId]
        );
    }

    public function deleteOpenedTicketNotifications(int $agentId, int $ticketId): bool
    {
        return $this->db->execute(
            "DELETE FROM agent_notifications
              WHERE agent_id = ?
                AND type IN ('ticket_created','customer_reply','ticket_assigned','ticket_internal_note','agent_mentioned')
                AND CAST(JSON_UNQUOTE(JSON_EXTRACT(data_json, '$.ticket_id')) AS UNSIGNED) = ?",
            [$agentId, $ticketId]
        );
    }

    private function ticketActiveTypeClause(string $notificationAlias, string $ticketAlias): string
    {
        return implode(' OR ', [
            "{$notificationAlias}.type IN ('ticket_created','customer_reply','ticket_assigned','ticket_internal_note','agent_mentioned')",
            "({$notificationAlias}.type = 'ticket_sla_overdue' AND {$ticketAlias}.priority = 'overdue')",
            "({$notificationAlias}.type = 'ticket_due_overdue' AND {$ticketAlias}.priority = 'overdue' AND {$ticketAlias}.due_at IS NOT NULL AND DATE(COALESCE({$ticketAlias}.due_end, {$ticketAlias}.due_at)) <= CURRENT_DATE())",
        ]);
    }
}

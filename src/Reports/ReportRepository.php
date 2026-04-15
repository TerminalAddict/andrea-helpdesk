<?php
declare(strict_types=1);

namespace Andrea\Helpdesk\Reports;

use Andrea\Helpdesk\Core\Database;

class ReportRepository
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function snapshot(): array
    {
        $rows = $this->db->fetchAll(
            "SELECT status, priority, COUNT(*) AS count
             FROM tickets
             WHERE deleted_at IS NULL
             GROUP BY status, priority"
        );

        $result = [
            'new' => 0,
            'waiting_for_reply' => 0,
            'pending' => 0,
            'replied' => 0,
            'overdue' => 0,
        ];

        foreach ($rows as $row) {
            $status = (string)$row['status'];
            $count  = (int)$row['count'];
            if (array_key_exists($status, $result)) {
                $result[$status] += $count;
            }
            if ($row['priority'] === 'overdue' && !in_array($status, ['resolved', 'closed'], true)) {
                $result['overdue'] += $count;
            }
        }

        return $result;
    }

    public function activitySummary(string $from, string $to): array
    {
        [$activitySql, $activityParams] = $this->activityTicketIdsSql($from, $to);
        $rows = $this->db->fetchAll(
            "SELECT t.status, t.priority, COUNT(*) AS count
             FROM tickets t
             INNER JOIN ({$activitySql}) scope ON scope.ticket_id = t.id
             WHERE t.deleted_at IS NULL
             GROUP BY t.status, t.priority",
            $activityParams
        );

        $result = [
            'new' => 0,
            'waiting_for_reply' => 0,
            'pending' => 0,
            'replied' => 0,
            'overdue' => 0,
            'ticket_count' => 0,
        ];

        foreach ($rows as $row) {
            $status = (string)$row['status'];
            $count  = (int)$row['count'];
            $result['ticket_count'] += $count;
            if (array_key_exists($status, $result)) {
                $result[$status] += $count;
            }
            if ($row['priority'] === 'overdue' && !in_array($status, ['resolved', 'closed'], true)) {
                $result['overdue'] += $count;
            }
        }

        return $result;
    }

    public function activityVolume(string $from, string $to, string $groupBy = 'day'): array
    {
        [$fromDt, $toDt] = $this->rangeBounds($from, $to);
        $ticketPeriodExpr = match($groupBy) {
            'week'  => "DATE_FORMAT(t.created_at, '%Y-W%u')",
            'month' => "DATE_FORMAT(t.created_at, '%Y-%m')",
            default => "DATE(t.created_at)",
        };
        $replyPeriodExpr = match($groupBy) {
            'week'  => "DATE_FORMAT(r.created_at, '%Y-W%u')",
            'month' => "DATE_FORMAT(r.created_at, '%Y-%m')",
            default => "DATE(r.created_at)",
        };

        return $this->db->fetchAll(
            "SELECT period,
                    SUM(CASE WHEN event_type = 'created' THEN 1 ELSE 0 END) AS created,
                    SUM(CASE WHEN event_type = 'customer_reply' THEN 1 ELSE 0 END) AS customer_replies,
                    SUM(CASE WHEN event_type = 'agent_reply' THEN 1 ELSE 0 END) AS agent_replies,
                    SUM(CASE WHEN event_type = 'internal_note' THEN 1 ELSE 0 END) AS internal_notes,
                    SUM(CASE WHEN event_type = 'system_event' THEN 1 ELSE 0 END) AS system_events,
                    COUNT(*) AS total
             FROM (
                SELECT {$ticketPeriodExpr} AS period, t.created_at, 'created' AS event_type
                FROM tickets t
                WHERE t.deleted_at IS NULL
                  AND t.created_at BETWEEN ? AND ?

                UNION ALL

                SELECT {$replyPeriodExpr} AS period, r.created_at,
                       CASE
                           WHEN r.author_type = 'customer' THEN 'customer_reply'
                           WHEN r.author_type = 'system' THEN 'system_event'
                           WHEN r.is_private = 1 THEN 'internal_note'
                           ELSE 'agent_reply'
                       END AS event_type
                FROM replies r
                INNER JOIN tickets t ON t.id = r.ticket_id
                WHERE t.deleted_at IS NULL
                  AND r.created_at BETWEEN ? AND ?
             ) events
             GROUP BY period
             ORDER BY period ASC",
            [$fromDt, $toDt, $fromDt, $toDt]
        );
    }

    public function activityByAgent(string $from, string $to): array
    {
        [$activitySql, $activityParams] = $this->activityTicketIdsSql($from, $to);
        [$fromDt, $toDt] = $this->rangeBounds($from, $to);

        return $this->db->fetchAll(
            "SELECT a.id AS agent_id,
                    a.name AS agent_name,
                    COALESCE(assigned.assigned_count, 0) AS assigned,
                    COALESCE(created.created_count, 0) AS created,
                    COALESCE(reply_counts.reply_count, 0) AS replies,
                    COALESCE(note_counts.note_count, 0) AS notes,
                    COALESCE(resolved.resolved_count, 0) AS resolved,
                    COALESCE(closed.closed_count, 0) AS closed
             FROM agents a
             LEFT JOIN (
                SELECT t.assigned_agent_id AS agent_id, COUNT(DISTINCT t.id) AS assigned_count
                FROM tickets t
                INNER JOIN ({$activitySql}) scope ON scope.ticket_id = t.id
                WHERE t.deleted_at IS NULL
                  AND t.assigned_agent_id IS NOT NULL
                GROUP BY t.assigned_agent_id
             ) assigned ON assigned.agent_id = a.id
             LEFT JOIN (
                SELECT t.created_by_agent_id AS agent_id, COUNT(*) AS created_count
                FROM tickets t
                WHERE t.deleted_at IS NULL
                  AND t.created_by_agent_id IS NOT NULL
                  AND t.created_at BETWEEN ? AND ?
                GROUP BY t.created_by_agent_id
             ) created ON created.agent_id = a.id
             LEFT JOIN (
                SELECT r.agent_id, COUNT(*) AS reply_count
                FROM replies r
                INNER JOIN tickets t ON t.id = r.ticket_id
                WHERE t.deleted_at IS NULL
                  AND r.agent_id IS NOT NULL
                  AND r.author_type = 'agent'
                  AND r.is_private = 0
                  AND r.created_at BETWEEN ? AND ?
                GROUP BY r.agent_id
             ) reply_counts ON reply_counts.agent_id = a.id
             LEFT JOIN (
                SELECT r.agent_id, COUNT(*) AS note_count
                FROM replies r
                INNER JOIN tickets t ON t.id = r.ticket_id
                WHERE t.deleted_at IS NULL
                  AND r.agent_id IS NOT NULL
                  AND r.author_type = 'agent'
                  AND r.is_private = 1
                  AND r.created_at BETWEEN ? AND ?
                GROUP BY r.agent_id
             ) note_counts ON note_counts.agent_id = a.id
             LEFT JOIN (
                SELECT r.agent_id, COUNT(*) AS resolved_count
                FROM replies r
                INNER JOIN tickets t ON t.id = r.ticket_id
                WHERE t.deleted_at IS NULL
                  AND r.agent_id IS NOT NULL
                  AND r.author_type = 'system'
                  AND r.body_text = 'Status changed to resolved.'
                  AND r.created_at BETWEEN ? AND ?
                GROUP BY r.agent_id
             ) resolved ON resolved.agent_id = a.id
             LEFT JOIN (
                SELECT r.agent_id, COUNT(*) AS closed_count
                FROM replies r
                INNER JOIN tickets t ON t.id = r.ticket_id
                WHERE t.deleted_at IS NULL
                  AND r.agent_id IS NOT NULL
                  AND r.author_type = 'system'
                  AND r.body_text = 'Status changed to closed.'
                  AND r.created_at BETWEEN ? AND ?
                GROUP BY r.agent_id
             ) closed ON closed.agent_id = a.id
             WHERE a.is_active = 1
               AND (
                    COALESCE(assigned.assigned_count, 0) > 0
                 OR COALESCE(created.created_count, 0) > 0
                 OR COALESCE(reply_counts.reply_count, 0) > 0
                 OR COALESCE(note_counts.note_count, 0) > 0
                 OR COALESCE(resolved.resolved_count, 0) > 0
                 OR COALESCE(closed.closed_count, 0) > 0
               )
             ORDER BY (COALESCE(reply_counts.reply_count, 0) + COALESCE(note_counts.note_count, 0) + COALESCE(created.created_count, 0) + COALESCE(resolved.resolved_count, 0) + COALESCE(closed.closed_count, 0)) DESC,
                      a.name ASC",
            array_merge(
                $activityParams,
                [$fromDt, $toDt, $fromDt, $toDt, $fromDt, $toDt, $fromDt, $toDt, $fromDt, $toDt]
            )
        );
    }

    public function timeToClose(string $from, string $to, ?int $agentId = null): array
    {
        [$fromDt, $toDt] = $this->rangeBounds($from, $to);
        $agentClause = $agentId ? "AND EXISTS (
                SELECT 1 FROM replies r
                WHERE r.ticket_id = t.id
                  AND r.agent_id = ?
                  AND r.author_type = 'system'
                  AND r.body_text = 'Status changed to closed.'
            )" : '';
        $params = $agentId ? [$fromDt, $toDt, $agentId] : [$fromDt, $toDt];

        $stats = $this->db->fetch(
            "SELECT
                ROUND(AVG(TIMESTAMPDIFF(MINUTE, t.created_at, t.closed_at)), 1) AS avg_minutes,
                MIN(TIMESTAMPDIFF(MINUTE, t.created_at, t.closed_at)) AS min_minutes,
                MAX(TIMESTAMPDIFF(MINUTE, t.created_at, t.closed_at)) AS max_minutes,
                COUNT(*) AS total_closed
             FROM tickets t
             WHERE t.closed_at IS NOT NULL
               AND t.deleted_at IS NULL
               AND t.closed_at BETWEEN ? AND ?
               {$agentClause}",
            $params
        );

        $tickets = $this->db->fetchAll(
            "SELECT t.ticket_number,
                    t.subject,
                    a.name AS agent_name,
                    TIMESTAMPDIFF(MINUTE, t.created_at, t.closed_at) AS close_minutes
             FROM tickets t
             LEFT JOIN agents a ON a.id = t.assigned_agent_id
             WHERE t.closed_at IS NOT NULL
               AND t.deleted_at IS NULL
               AND t.closed_at BETWEEN ? AND ?
               {$agentClause}
             ORDER BY close_minutes DESC
             LIMIT 50",
            $params
        );

        return [
            'avg_minutes' => (float)($stats['avg_minutes'] ?? 0),
            'min_minutes' => (int)($stats['min_minutes'] ?? 0),
            'max_minutes' => (int)($stats['max_minutes'] ?? 0),
            'count'       => (int)($stats['total_closed'] ?? 0),
            'tickets'     => $tickets,
        ];
    }

    private function activityTicketIdsSql(string $from, string $to): array
    {
        [$fromDt, $toDt] = $this->rangeBounds($from, $to);
        return [
            "SELECT DISTINCT ticket_id
             FROM (
                SELECT t.id AS ticket_id
                FROM tickets t
                WHERE t.deleted_at IS NULL
                  AND t.created_at BETWEEN ? AND ?

                UNION

                SELECT r.ticket_id
                FROM replies r
                INNER JOIN tickets t ON t.id = r.ticket_id
                WHERE t.deleted_at IS NULL
                  AND r.created_at BETWEEN ? AND ?
             ) activity_scope",
            [$fromDt, $toDt, $fromDt, $toDt]
        ];
    }

    private function rangeBounds(string $from, string $to): array
    {
        return [$from . ' 00:00:00', $to . ' 23:59:59'];
    }
}

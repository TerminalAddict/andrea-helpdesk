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

    public function summary(string $from, string $to): array
    {
        $fromDt = $from . ' 00:00:00';
        $toDt   = $to . ' 23:59:59';

        $statusCounts = $this->db->fetchAll(
            "SELECT status, COUNT(*) as count FROM tickets WHERE deleted_at IS NULL GROUP BY status"
        );
        $result = ['new' => 0, 'open' => 0, 'waiting_for_reply' => 0, 'replied' => 0, 'pending' => 0, 'resolved' => 0, 'closed' => 0];
        foreach ($statusCounts as $row) {
            $result[$row['status']] = (int)$row['count'];
        }

        $newInPeriod = $this->db->count(
            "SELECT COUNT(*) FROM tickets WHERE created_at BETWEEN ? AND ? AND deleted_at IS NULL",
            [$fromDt, $toDt]
        );

        $closedInPeriod = $this->db->count(
            "SELECT COUNT(*) FROM tickets WHERE closed_at BETWEEN ? AND ? AND deleted_at IS NULL",
            [$fromDt, $toDt]
        );

        $avgResponse = $this->db->fetch(
            "SELECT AVG(TIMESTAMPDIFF(MINUTE, created_at, first_response_at)) AS avg_minutes
             FROM tickets WHERE first_response_at IS NOT NULL AND deleted_at IS NULL
             AND created_at BETWEEN ? AND ?",
            [$fromDt, $toDt]
        );

        return array_merge($result, [
            'new_in_period'     => $newInPeriod,
            'closed_in_period'  => $closedInPeriod,
            'avg_response_minutes' => round((float)($avgResponse['avg_minutes'] ?? 0), 1),
        ]);
    }

    public function byAgent(string $from, string $to): array
    {
        $fromDt = $from . ' 00:00:00';
        $toDt   = $to . ' 23:59:59';

        return $this->db->fetchAll(
            "SELECT a.id AS agent_id, a.name AS agent_name,
                    COUNT(t.id) AS ticket_count,
                    SUM(CASE WHEN t.status IN ('resolved','closed') THEN 1 ELSE 0 END) AS closed_count,
                    SUM(CASE WHEN t.status NOT IN ('resolved','closed') THEN 1 ELSE 0 END) AS open_count,
                    ROUND(AVG(TIMESTAMPDIFF(MINUTE, t.created_at, t.closed_at)), 1) AS avg_close_minutes
             FROM agents a
             LEFT JOIN tickets t ON t.assigned_agent_id = a.id
                AND t.created_at BETWEEN ? AND ?
                AND t.deleted_at IS NULL
             WHERE a.is_active = 1
             GROUP BY a.id, a.name
             HAVING ticket_count > 0
             ORDER BY ticket_count DESC",
            [$fromDt, $toDt]
        );
    }

    public function byStatus(string $from, string $to): array
    {
        $fromDt = $from . ' 00:00:00';
        $toDt   = $to . ' 23:59:59';

        return $this->db->fetchAll(
            "SELECT status, COUNT(*) AS count
             FROM tickets
             WHERE created_at BETWEEN ? AND ? AND deleted_at IS NULL
             GROUP BY status",
            [$fromDt, $toDt]
        );
    }

    public function timeToClose(string $from, string $to, ?int $agentId = null): array
    {
        $fromDt = $from . ' 00:00:00';
        $toDt   = $to . ' 23:59:59';

        $agentClause  = $agentId ? "AND t.assigned_agent_id = ?" : '';
        $baseParams   = [$fromDt, $toDt];
        $agentParams  = $agentId ? [$agentId] : [];

        $stats = $this->db->fetch(
            "SELECT
                ROUND(AVG(TIMESTAMPDIFF(MINUTE, t.created_at, t.closed_at)), 1) AS avg_minutes,
                MIN(TIMESTAMPDIFF(MINUTE, t.created_at, t.closed_at)) AS min_minutes,
                MAX(TIMESTAMPDIFF(MINUTE, t.created_at, t.closed_at)) AS max_minutes,
                COUNT(*) AS total_closed
             FROM tickets t
             WHERE t.closed_at IS NOT NULL AND t.deleted_at IS NULL
               AND t.closed_at BETWEEN ? AND ?
               {$agentClause}",
            array_merge($baseParams, $agentParams)
        );

        $tickets = $this->db->fetchAll(
            "SELECT t.ticket_number, t.subject, a.name AS agent_name,
                    TIMESTAMPDIFF(MINUTE, t.created_at, t.closed_at) AS close_minutes
             FROM tickets t
             LEFT JOIN agents a ON a.id = t.assigned_agent_id
             WHERE t.closed_at IS NOT NULL AND t.deleted_at IS NULL
               AND t.closed_at BETWEEN ? AND ?
               {$agentClause}
             ORDER BY close_minutes DESC LIMIT 50",
            array_merge($baseParams, $agentParams)
        );

        return [
            'avg_minutes'   => (float)($stats['avg_minutes'] ?? 0),
            'min_minutes'   => (int)($stats['min_minutes'] ?? 0),
            'max_minutes'   => (int)($stats['max_minutes'] ?? 0),
            'total_closed'  => (int)($stats['total_closed'] ?? 0),
            'tickets'       => $tickets,
        ];
    }

    public function volume(string $from, string $to, string $groupBy = 'day'): array
    {
        $fromDt = $from . ' 00:00:00';
        $toDt   = $to . ' 23:59:59';

        $groupExpr = match($groupBy) {
            'week'  => "DATE_FORMAT(created_at, '%Y-W%u')",
            'month' => "DATE_FORMAT(created_at, '%Y-%m')",
            default => "DATE(created_at)",
        };

        return $this->db->fetchAll(
            "SELECT {$groupExpr} AS period, COUNT(*) AS count
             FROM tickets
             WHERE created_at BETWEEN ? AND ? AND deleted_at IS NULL
             GROUP BY period
             ORDER BY period ASC",
            [$fromDt, $toDt]
        );
    }
}

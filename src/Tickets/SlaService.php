<?php
declare(strict_types=1);

namespace Andrea\Helpdesk\Tickets;

use Andrea\Helpdesk\Agents\AgentRepository;
use Andrea\Helpdesk\Core\Database;
use Andrea\Helpdesk\Notifications\NotificationService;
use Andrea\Helpdesk\Settings\SettingsService;

class SlaService
{
    private Database $db;
    private SettingsService $settings;
    private TicketRepository $ticketRepo;
    private AgentRepository $agentRepo;
    private NotificationService $notifications;

    public function __construct()
    {
        $this->db            = Database::getInstance();
        $this->settings      = SettingsService::getInstance();
        $this->ticketRepo    = new TicketRepository();
        $this->agentRepo     = new AgentRepository();
        $this->notifications = new NotificationService();
    }

    public function run(): array
    {
        $config = $this->settings->getSlaConfig();
        $recipientAgentIds = $this->resolveRecipientAgentIds($config);
        $dueDateOverdueCount = $this->processDueDateOverdueTickets($recipientAgentIds);
        if (!$config['enabled']) {
            return ['high_escalated' => 0, 'overdue_escalated' => $dueDateOverdueCount];
        }
        if (!$recipientAgentIds) {
            return ['high_escalated' => 0, 'overdue_escalated' => $dueDateOverdueCount];
        }
        $highCutoff = date('Y-m-d H:i:s', strtotime('-' . $config['high_after_days'] . ' days'));
        $overdueCutoff = date(
            'Y-m-d H:i:s',
            strtotime('-' . ($config['high_after_days'] + $config['overdue_after_days']) . ' days')
        );

        $highTickets = $this->db->fetchAll(
            "SELECT t.*,
                    c.name AS customer_name,
                    c.email AS customer_email,
                    a.name AS agent_name
             FROM tickets t
             LEFT JOIN customers c ON c.id = t.customer_id
             LEFT JOIN agents a ON a.id = t.assigned_agent_id
             WHERE t.deleted_at IS NULL
               AND t.status NOT IN ('resolved', 'closed')
               AND t.last_attention_at <= ?
               AND t.last_attention_at > ?
               AND (t.sla_high_notified_at IS NULL OR t.sla_high_notified_at < t.last_attention_at)
               AND (t.sla_overdue_notified_at IS NULL OR t.sla_overdue_notified_at < t.last_attention_at)
               AND t.priority != 'overdue'
             ORDER BY t.last_attention_at ASC",
            [$highCutoff, $overdueCutoff]
        );

        $overdueTickets = $this->db->fetchAll(
            "SELECT t.*,
                    c.name AS customer_name,
                    c.email AS customer_email,
                    a.name AS agent_name
             FROM tickets t
             LEFT JOIN customers c ON c.id = t.customer_id
             LEFT JOIN agents a ON a.id = t.assigned_agent_id
             WHERE t.deleted_at IS NULL
               AND t.status NOT IN ('resolved', 'closed')
               AND t.last_attention_at <= ?
               AND (t.sla_overdue_notified_at IS NULL OR t.sla_overdue_notified_at < t.last_attention_at)
             ORDER BY t.last_attention_at ASC",
            [$overdueCutoff]
        );

        $highCount = 0;
        foreach ($highTickets as $ticket) {
            if (!$this->claimHighEscalation((int)$ticket['id'])) {
                continue;
            }

            $updated = $this->ticketRepo->findById((int)$ticket['id']) ?? $ticket;
            $this->notifications->sendSlaReminder($updated, 'high', $recipientAgentIds, $config['high_after_days']);
            $highCount++;
        }

        $overdueCount = 0;
        $overdueDays = $config['high_after_days'] + $config['overdue_after_days'];
        foreach ($overdueTickets as $ticket) {
            if (!$this->claimOverdueEscalation((int)$ticket['id'])) {
                continue;
            }

            $updated = $this->ticketRepo->findById((int)$ticket['id']) ?? $ticket;
            $this->notifications->sendSlaReminder($updated, 'overdue', $recipientAgentIds, $overdueDays);
            $overdueCount++;
        }

        return ['high_escalated' => $highCount, 'overdue_escalated' => $overdueCount + $dueDateOverdueCount];
    }

    private function processDueDateOverdueTickets(array $recipientAgentIds): int
    {
        $tickets = $this->db->fetchAll(
            "SELECT t.*,
                    c.name AS customer_name,
                    c.email AS customer_email,
                    a.name AS agent_name
             FROM tickets t
             LEFT JOIN customers c ON c.id = t.customer_id
             LEFT JOIN agents a ON a.id = t.assigned_agent_id
             WHERE t.deleted_at IS NULL
               AND t.status NOT IN ('resolved', 'closed')
               AND t.priority != 'overdue'
               AND t.due_at IS NOT NULL
               AND DATE(COALESCE(t.due_end, t.due_at)) <= CURRENT_DATE()
             ORDER BY COALESCE(t.due_end, t.due_at) ASC"
        );

        $count = 0;
        foreach ($tickets as $ticket) {
            if (!$this->claimDueDateOverdue((int)$ticket['id'])) {
                continue;
            }

            $updated = $this->ticketRepo->findById((int)$ticket['id']) ?? $ticket;
            (new ReplyService())->createSystemReply(
                (int)$ticket['id'],
                'Priority changed to overdue because the due date has passed.',
                null
            );
            $this->notifications->sendOverdueAlert(
                $updated,
                $recipientAgentIds,
                'Due date has passed',
                'due:' . (string)($ticket['due_end'] ?: $ticket['due_at'] ?: '')
            );
            $count++;
        }

        return $count;
    }

    private function claimHighEscalation(int $ticketId): bool
    {
        $stmt = $this->db->getPdo()->prepare(
            "UPDATE tickets
             SET priority = CASE
                    WHEN priority IN ('low', 'normal') THEN 'high'
                    ELSE priority
                 END,
                 sla_high_notified_at = NOW()
             WHERE id = ?
               AND deleted_at IS NULL
               AND status NOT IN ('resolved', 'closed')
               AND (sla_high_notified_at IS NULL OR sla_high_notified_at < last_attention_at)
               AND (sla_overdue_notified_at IS NULL OR sla_overdue_notified_at < last_attention_at)
               AND priority != 'overdue'"
        );
        $stmt->execute([$ticketId]);
        return $stmt->rowCount() > 0;
    }

    private function claimOverdueEscalation(int $ticketId): bool
    {
        $stmt = $this->db->getPdo()->prepare(
            "UPDATE tickets
             SET priority = 'overdue',
                 sla_overdue_notified_at = NOW()
             WHERE id = ?
               AND deleted_at IS NULL
               AND status NOT IN ('resolved', 'closed')
               AND (sla_overdue_notified_at IS NULL OR sla_overdue_notified_at < last_attention_at)"
        );
        $stmt->execute([$ticketId]);
        return $stmt->rowCount() > 0;
    }

    private function claimDueDateOverdue(int $ticketId): bool
    {
        $stmt = $this->db->getPdo()->prepare(
            "UPDATE tickets
             SET priority = 'overdue',
                 sla_overdue_notified_at = NOW()
             WHERE id = ?
               AND deleted_at IS NULL
               AND status NOT IN ('resolved', 'closed')
               AND priority != 'overdue'
               AND due_at IS NOT NULL
               AND DATE(COALESCE(due_end, due_at)) <= CURRENT_DATE()"
        );
        $stmt->execute([$ticketId]);
        return $stmt->rowCount() > 0;
    }

    private function resolveRecipientAgentIds(array $config): array
    {
        if ($config['notify_scope'] === 'specific') {
            if (empty($config['notify_agent_ids'])) {
                return [];
            }

            $activeIds = array_map('intval', array_column($this->agentRepo->getActiveAgents(), 'id'));
            return array_values(array_filter(
                $config['notify_agent_ids'],
                fn(int $id): bool => in_array($id, $activeIds, true)
            ));
        }

        return array_map('intval', array_column($this->agentRepo->getActiveAgents(), 'id'));
    }
}

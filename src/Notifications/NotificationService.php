<?php
declare(strict_types=1);

namespace Andrea\Helpdesk\Notifications;

use Andrea\Helpdesk\Core\Database;
use Andrea\Helpdesk\Agents\AgentRepository;
use Andrea\Helpdesk\Settings\SettingsService;

class NotificationService
{
    private EmailNotifier $emailNotifier;
    private SlackNotifier $slackNotifier;
    private AutoResponder $autoResponder;
    private AgentNotificationRepository $inbox;
    private AgentRepository $agents;

    public function __construct()
    {
        $settings             = SettingsService::getInstance();
        $this->emailNotifier  = new EmailNotifier();
        $this->slackNotifier  = new SlackNotifier($settings);
        $this->autoResponder  = new AutoResponder($this->emailNotifier, $settings);
        $this->inbox          = new AgentNotificationRepository();
        $this->agents         = new AgentRepository();
    }

    public function onNewTicket(array $ticket, array $customer): void
    {
        $db = Database::getInstance();

        // 1. Send auto-response to customer (skipped if suppression is active on ticket or customer)
        if (empty($ticket['suppress_emails']) && empty($customer['suppress_emails'])) {
            try {
                $this->autoResponder->sendForNewTicket($ticket, $customer);
            } catch (\Throwable $e) {
                $this->log('onNewTicket auto-response: ' . $e->getMessage());
            }
        }

        // 2. Notify all active agents by email
        try {
            $emailConfig = SettingsService::getInstance()->getEmailConfig();
            if ($emailConfig['notify_agent_on_new_ticket']) {
                $agents = $db->fetchAll("SELECT email, name FROM agents WHERE is_active = 1");
                if ($agents) {
                    $this->emailNotifier->sendNewTicketNotification($ticket, $customer, $agents);
                }
            }
        } catch (\Throwable $e) {
            $this->log('onNewTicket agent email: ' . $e->getMessage());
        }

        // 3. Slack notification
        try {
            $this->slackNotifier->sendNewTicketAlert($ticket, $customer);
        } catch (\Throwable $e) {
            $this->log('onNewTicket slack: ' . $e->getMessage());
        }

        try {
            $recipientIds = array_map('intval', array_column($this->agents->getActiveAgents(), 'id'));
            if (!empty($ticket['created_by_agent_id'])) {
                $recipientIds = array_values(array_filter(
                    $recipientIds,
                    fn(int $id): bool => $id !== (int)$ticket['created_by_agent_id']
                ));
            }

            $this->createInAppNotifications($recipientIds, [
                'type'       => 'ticket_created',
                'severity'   => $this->ticketSeverity($ticket),
                'title'      => "New ticket {$ticket['ticket_number']}",
                'body'       => trim(($customer['name'] ?? $customer['email'] ?? 'Customer') . ' · ' . $ticket['subject']),
                'link'       => '/tickets/' . (int)$ticket['id'],
                'dedupe_key' => 'ticket:new:' . (int)$ticket['id'],
                'data'       => ['ticket_id' => (int)$ticket['id']],
            ]);
        } catch (\Throwable $e) {
            $this->log('onNewTicket inbox: ' . $e->getMessage());
        }
    }

    public function onTicketAssigned(array $ticket, array $assignedAgent): void
    {
        try {
            $appUrl    = rtrim(SettingsService::getInstance()->get('app_url') ?: getenv('APP_URL') ?: '', '/');
            $ticketUrl = "{$appUrl}/#/tickets/{$ticket['id']}";
            $this->emailNotifier->sendAgentNotification(
                $assignedAgent['id'],
                "Ticket Assigned: {$ticket['ticket_number']}",
                "<p>You have been assigned ticket <strong>{$ticket['ticket_number']}</strong>: {$ticket['subject']}</p>
                 <p><a href='{$ticketUrl}'>View Ticket</a></p>"
            );
        } catch (\Throwable $e) {
            $this->log('onTicketAssigned email: ' . $e->getMessage());
        }

        try {
            $this->slackNotifier->sendAssignmentAlert($ticket, $assignedAgent);
        } catch (\Throwable $e) {
            $this->log('onTicketAssigned slack: ' . $e->getMessage());
        }

        try {
            $this->createInAppNotifications([(int)$assignedAgent['id']], [
                'type'     => 'ticket_assigned',
                'severity' => 'info',
                'title'    => "Assigned {$ticket['ticket_number']}",
                'body'     => $ticket['subject'],
                'link'     => '/tickets/' . (int)$ticket['id'],
                'data'     => ['ticket_id' => (int)$ticket['id']],
            ]);
        } catch (\Throwable $e) {
            $this->log('onTicketAssigned inbox: ' . $e->getMessage());
        }
    }

    public function onCustomerReply(array $ticket, array $reply, array $customer): void
    {
        $emailConfig = SettingsService::getInstance()->getEmailConfig();
        if (!$emailConfig['notify_agent_on_new_reply']) return;

        $db = Database::getInstance();

        try {
            $appUrl    = rtrim(SettingsService::getInstance()->get('app_url') ?: getenv('APP_URL') ?: '', '/');
            $ticketUrl = "{$appUrl}/#/tickets/{$ticket['id']}";

            if ($ticket['assigned_agent_id']) {
                $this->emailNotifier->sendAgentNotification(
                    $ticket['assigned_agent_id'],
                    "Customer replied: {$ticket['ticket_number']}",
                    "<p><strong>" . htmlspecialchars($customer['name'] ?? $customer['email']) . "</strong> replied to ticket <strong>{$ticket['ticket_number']}</strong>.</p>
                     <blockquote>" . htmlspecialchars(substr(strip_tags($reply['body_html']), 0, 500)) . "</blockquote>
                     <p><a href='{$ticketUrl}'>View Ticket</a></p>"
                );
            } else {
                // Notify all agents
                $agents = $db->fetchAll("SELECT id FROM agents WHERE is_active = 1");
                foreach ($agents as $agent) {
                    $this->emailNotifier->sendAgentNotification(
                        $agent['id'],
                        "Customer replied: {$ticket['ticket_number']}",
                        "<p><strong>" . htmlspecialchars($customer['name'] ?? $customer['email']) . "</strong> replied to ticket <strong>{$ticket['ticket_number']}</strong>.</p>
                         <p><a href='{$ticketUrl}'>View Ticket</a></p>"
                    );
                }
            }
        } catch (\Throwable $e) {
            $this->log('onCustomerReply: ' . $e->getMessage());
        }

        try {
            $this->slackNotifier->sendCustomerReplyAlert($ticket, $reply, $customer);
        } catch (\Throwable $e) {
            $this->log('onCustomerReply slack: ' . $e->getMessage());
        }

        try {
            $recipientIds = $ticket['assigned_agent_id']
                ? [(int)$ticket['assigned_agent_id']]
                : array_map('intval', array_column($this->agents->getActiveAgents(), 'id'));
            $preview = trim(substr((string)($reply['body_text'] ?: strip_tags((string)($reply['body_html'] ?? ''))), 0, 140));
            $this->createInAppNotifications($recipientIds, [
                'type'       => 'customer_reply',
                'severity'   => 'info',
                'title'      => "Customer replied on {$ticket['ticket_number']}",
                'body'       => trim(($customer['name'] ?? $customer['email'] ?? 'Customer') . ($preview ? ' · ' . $preview : '')),
                'link'       => '/tickets/' . (int)$ticket['id'],
                'dedupe_key' => 'ticket:customer-reply:' . (int)$reply['id'],
                'data'       => ['ticket_id' => (int)$ticket['id']],
            ]);
        } catch (\Throwable $e) {
            $this->log('onCustomerReply inbox: ' . $e->getMessage());
        }
    }

    public function onAgentReply(array $ticket, array $reply, array $agent, array $customer, array $ccEmails = [], array $attachmentIds = [], bool $includeSignature = true): void
    {
        if (!empty($ticket['suppress_emails']) || !empty($customer['suppress_emails'])) return;

        try {
            $this->emailNotifier->sendTicketReply($ticket, $reply, $agent, $customer, $ccEmails, $attachmentIds, $includeSignature);
        } catch (\Throwable $e) {
            $this->log('onAgentReply: ' . $e->getMessage());
        }
    }

    public function onAgentMentioned(array $ticket, int $mentionedAgentId, int $authorAgentId): void
    {
        try {
            $db     = Database::getInstance();
            $author = $db->fetch("SELECT name FROM agents WHERE id = ?", [$authorAgentId]);
            $appUrl = rtrim(SettingsService::getInstance()->get('app_url') ?: getenv('APP_URL') ?: '', '/');
            $ticketUrl = "{$appUrl}/#/tickets/{$ticket['id']}";

            $this->emailNotifier->sendAgentNotification(
                $mentionedAgentId,
                "You were mentioned in {$ticket['ticket_number']}",
                "<p>" . htmlspecialchars($author['name'] ?? 'An agent') . " mentioned you in ticket "
                . "<strong>" . htmlspecialchars($ticket['ticket_number']) . "</strong>: "
                . htmlspecialchars($ticket['subject']) . "</p>"
                . "<p><a href='" . htmlspecialchars($ticketUrl) . "'>View Ticket</a></p>"
            );
        } catch (\Throwable $e) {
            $this->log('onAgentMentioned: ' . $e->getMessage());
        }

        try {
            $db     = Database::getInstance();
            $author = $db->fetch("SELECT name FROM agents WHERE id = ?", [$authorAgentId]);
            $this->createInAppNotifications([$mentionedAgentId], [
                'type'     => 'agent_mentioned',
                'severity' => 'info',
                'title'    => "Mentioned in {$ticket['ticket_number']}",
                'body'     => trim(($author['name'] ?? 'An agent') . ' mentioned you · ' . $ticket['subject']),
                'link'     => '/tickets/' . (int)$ticket['id'],
                'data'     => ['ticket_id' => (int)$ticket['id']],
            ]);
        } catch (\Throwable $e) {
            $this->log('onAgentMentioned inbox: ' . $e->getMessage());
        }
    }

    public function sendSlaReminder(array $ticket, string $stage, array $agentIds, int $daysWithoutAttention): void
    {
        if (empty($agentIds)) {
            return;
        }

        $stageLabel = $stage === 'overdue' ? 'Overdue' : 'High Priority';
        $icon       = $stage === 'overdue' ? '&#9888;' : '&#128276;';
        $appUrl     = rtrim(SettingsService::getInstance()->get('app_url') ?: getenv('APP_URL') ?: '', '/');
        $ticketUrl  = "{$appUrl}/#/tickets/{$ticket['id']}";
        $customer   = $ticket['customer_name'] ?: $ticket['customer_email'] ?: 'Unknown customer';
        $assigned   = $ticket['agent_name'] ?: 'Unassigned';

        $subject = "{$stageLabel} SLA alert: {$ticket['ticket_number']}";
        $body = "<p>{$icon} Ticket <strong>" . htmlspecialchars($ticket['ticket_number']) . "</strong> is now marked <strong>"
            . htmlspecialchars($stageLabel) . "</strong>.</p>"
            . "<p><strong>Subject:</strong> " . htmlspecialchars($ticket['subject']) . "<br>"
            . "<strong>Customer:</strong> " . htmlspecialchars($customer) . "<br>"
            . "<strong>Assigned To:</strong> " . htmlspecialchars($assigned) . "<br>"
            . "<strong>No attention for:</strong> " . (int)$daysWithoutAttention . " day(s)</p>"
            . "<p><a href='" . htmlspecialchars($ticketUrl) . "'>View Ticket</a></p>";

        foreach (array_unique(array_map('intval', $agentIds)) as $agentId) {
            try {
                $this->emailNotifier->sendAgentNotification($agentId, $subject, $body);
            } catch (\Throwable $e) {
                $this->log("sendSlaReminder({$stage}) agent {$agentId}: " . $e->getMessage());
            }
        }

        try {
            if ($stage === 'overdue') {
                $this->sendOverdueAlert(
                    $ticket,
                    $agentIds,
                    'No attention for ' . (int)$daysWithoutAttention . ' day(s)',
                    'sla:' . (int)$ticket['id'] . ':' . (string)($ticket['last_attention_at'] ?? '')
                );
            } else {
                $this->createInAppNotifications($agentIds, [
                    'type'       => 'sla_escalated',
                    'severity'   => 'warning',
                    'title'      => "{$stageLabel}: {$ticket['ticket_number']}",
                    'body'       => trim($ticket['subject'] . ' · No attention for ' . (int)$daysWithoutAttention . ' day(s)'),
                    'link'       => '/tickets/' . (int)$ticket['id'],
                    'dedupe_key' => 'sla:high:' . (int)$ticket['id'] . ':' . (string)($ticket['last_attention_at'] ?? ''),
                    'data'       => ['ticket_id' => (int)$ticket['id']],
                ]);
            }
        } catch (\Throwable $e) {
            $this->log("sendSlaReminder({$stage}) inbox: " . $e->getMessage());
        }
    }

    public function sendOverdueAlert(array $ticket, array $agentIds, string $reason, ?string $dedupeSuffix = null): void
    {
        $agentIds = array_values(array_unique(array_map('intval', $agentIds)));
        if (empty($agentIds)) {
            return;
        }

        $appUrl    = rtrim(SettingsService::getInstance()->get('app_url') ?: getenv('APP_URL') ?: '', '/');
        $ticketUrl = "{$appUrl}/#/tickets/{$ticket['id']}";
        $customer  = $ticket['customer_name'] ?: $ticket['customer_email'] ?: 'Unknown customer';
        $assigned  = $ticket['agent_name'] ?: 'Unassigned';
        $subject   = "Overdue alert: {$ticket['ticket_number']}";
        $body      = "<p>&#9888; Ticket <strong>" . htmlspecialchars($ticket['ticket_number']) . "</strong> is marked <strong>Overdue</strong>.</p>"
            . "<p><strong>Subject:</strong> " . htmlspecialchars($ticket['subject']) . "<br>"
            . "<strong>Customer:</strong> " . htmlspecialchars($customer) . "<br>"
            . "<strong>Assigned To:</strong> " . htmlspecialchars($assigned) . "<br>"
            . "<strong>Reason:</strong> " . htmlspecialchars($reason) . "</p>"
            . "<p><a href='" . htmlspecialchars($ticketUrl) . "'>View Ticket</a></p>";

        foreach ($agentIds as $agentId) {
            try {
                $this->emailNotifier->sendAgentNotification($agentId, $subject, $body);
            } catch (\Throwable $e) {
                $this->log("sendOverdueAlert agent {$agentId}: " . $e->getMessage());
            }
        }

        try {
            $this->createInAppNotifications($agentIds, [
                'type'       => 'ticket_overdue',
                'severity'   => 'danger',
                'title'      => "Overdue: {$ticket['ticket_number']}",
                'body'       => trim($ticket['subject'] . ' · ' . $reason),
                'link'       => '/tickets/' . (int)$ticket['id'],
                'dedupe_key' => 'overdue:' . (int)$ticket['id'] . ':' . ($dedupeSuffix ?: (string)($ticket['updated_at'] ?? time())),
                'data'       => ['ticket_id' => (int)$ticket['id']],
            ]);
        } catch (\Throwable $e) {
            $this->log('sendOverdueAlert inbox: ' . $e->getMessage());
        }
    }

    public function onUpdateAvailable(int $agentId, array $installed, array $latest): void
    {
        $latestVersion = (string)($latest['version'] ?? '');
        if ($latestVersion === '') {
            return;
        }

        $this->createInAppNotifications([$agentId], [
            'type'       => 'update_available',
            'severity'   => 'success',
            'title'      => "Update available: {$latestVersion}",
            'body'       => 'Installed ' . (string)($installed['version'] ?? 'unknown') . ' · Open Version & Updates to review and install.',
            'link'       => '/admin/settings/general',
            'dedupe_key' => 'update:' . $latestVersion,
            'data'       => ['latest_version' => $latestVersion],
        ]);
    }

    private function createInAppNotifications(array $agentIds, array $payload): void
    {
        $this->inbox->createForAgents($agentIds, $payload);
    }

    private function ticketSeverity(array $ticket): string
    {
        return match ((string)($ticket['priority'] ?? 'normal')) {
            'urgent', 'overdue' => 'danger',
            'high' => 'warning',
            default => 'info',
        };
    }

    private function log(string $message): void
    {
        $logFile = (getenv('STORAGE_PATH') ?: '/tmp') . '/logs/app.log';
        $dir = dirname($logFile);
        if (!is_dir($dir)) mkdir($dir, 0750, true);
        file_put_contents($logFile, '[' . date('Y-m-d H:i:s') . '] [WARN] NotificationService: ' . $message . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}

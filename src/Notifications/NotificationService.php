<?php
declare(strict_types=1);

namespace Andrea\Helpdesk\Notifications;

use Andrea\Helpdesk\Core\Database;
use Andrea\Helpdesk\Settings\SettingsService;

class NotificationService
{
    private EmailNotifier $emailNotifier;
    private SlackNotifier $slackNotifier;
    private AutoResponder $autoResponder;

    public function __construct()
    {
        $settings             = SettingsService::getInstance();
        $this->emailNotifier  = new EmailNotifier();
        $this->slackNotifier  = new SlackNotifier($settings);
        $this->autoResponder  = new AutoResponder($this->emailNotifier, $settings);
    }

    public function onNewTicket(array $ticket, array $customer): void
    {
        $db = Database::getInstance();

        // 1. Send auto-response to customer
        try {
            $this->autoResponder->sendForNewTicket($ticket, $customer);
        } catch (\Throwable $e) {
            $this->log('onNewTicket auto-response: ' . $e->getMessage());
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
    }

    public function onTicketAssigned(array $ticket, array $assignedAgent): void
    {
        try {
            $appUrl    = SettingsService::getInstance()->get('app_url') ?: getenv('APP_URL') ?: '';
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
    }

    public function onCustomerReply(array $ticket, array $reply, array $customer): void
    {
        $emailConfig = SettingsService::getInstance()->getEmailConfig();
        if (!$emailConfig['notify_agent_on_new_reply']) return;

        $db = Database::getInstance();

        try {
            $appUrl    = SettingsService::getInstance()->get('app_url') ?: getenv('APP_URL') ?: '';
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
    }

    public function onAgentReply(array $ticket, array $reply, array $agent, array $customer, array $ccEmails = [], array $attachmentIds = []): void
    {
        try {
            $this->emailNotifier->sendTicketReply($ticket, $reply, $agent, $customer, $ccEmails, $attachmentIds);
        } catch (\Throwable $e) {
            $this->log('onAgentReply: ' . $e->getMessage());
        }
    }

    private function log(string $message): void
    {
        $logFile = (getenv('STORAGE_PATH') ?: '/tmp') . '/logs/app.log';
        $dir = dirname($logFile);
        if (!is_dir($dir)) mkdir($dir, 0750, true);
        file_put_contents($logFile, '[' . date('Y-m-d H:i:s') . '] [WARN] NotificationService: ' . $message . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}

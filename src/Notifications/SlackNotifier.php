<?php
declare(strict_types=1);

namespace Andrea\Helpdesk\Notifications;

use Andrea\Helpdesk\Settings\SettingsService;

class SlackNotifier
{
    public function __construct(private SettingsService $settings) {}

    public function sendNewTicketAlert(array $ticket, array $customer): bool
    {
        $config = $this->settings->getSlackConfig();
        if (!$config['enabled'] || !$config['on_new_ticket']) return false;

        $priority  = strtoupper($ticket['priority'] ?? 'normal');
        $channel   = $config['channel'];
        $appUrl    = rtrim($this->settings->get('app_url') ?: getenv('APP_URL') ?: '', '/');
        $ticketUrl = "{$appUrl}/#/tickets/{$ticket['id']}";
        $customer_name = $customer['name'] ?? $customer['email'];

        $emoji = match($ticket['priority'] ?? 'normal') {
            'urgent' => ':rotating_light:',
            'high'   => ':exclamation:',
            default  => ':ticket:',
        };

        $text = "{$emoji} *New Ticket <{$ticketUrl}|{$ticket['ticket_number']}>*: {$ticket['subject']}\n"
              . ">*From:* {$customer_name} ({$customer['email']})\n"
              . ">*Priority:* {$priority} | *Channel:* {$ticket['channel']}";

        return $this->post(['text' => $text, 'channel' => $channel]);
    }

    public function sendAssignmentAlert(array $ticket, array $agent): bool
    {
        $config = $this->settings->getSlackConfig();
        if (!$config['enabled'] || !$config['on_assign']) return false;

        $appUrl    = rtrim($this->settings->get('app_url') ?: getenv('APP_URL') ?: '', '/');
        $ticketUrl = "{$appUrl}/#/tickets/{$ticket['id']}";

        $text = ":clipboard: Ticket <{$ticketUrl}|{$ticket['ticket_number']}> assigned to *{$agent['name']}*: {$ticket['subject']}";
        return $this->post(['text' => $text, 'channel' => $config['channel']]);
    }

    public function sendCustomerReplyAlert(array $ticket, array $reply, array $customer): bool
    {
        $config = $this->settings->getSlackConfig();
        if (!$config['enabled'] || !$config['on_new_reply']) return false;

        $appUrl        = rtrim($this->settings->get('app_url') ?: getenv('APP_URL') ?: '', '/');
        $ticketUrl     = "{$appUrl}/#/tickets/{$ticket['id']}";
        $customerName  = $customer['name'] ?? $customer['email'];
        $preview       = substr(strip_tags($reply['body_html'] ?? ''), 0, 200);

        $text = ":speech_balloon: *<{$ticketUrl}|{$ticket['ticket_number']}>* — reply from *{$customerName}*: {$ticket['subject']}"
              . ($preview ? "\n>{$preview}" : '');

        return $this->post(['text' => $text, 'channel' => $config['channel']]);
    }

    public function sendMessage(string $message): bool
    {
        $config = $this->settings->getSlackConfig();
        if (!$config['enabled']) return false;

        return $this->post(['text' => $message, 'channel' => $config['channel']]);
    }

    private function post(array $payload): bool
    {
        $config = $this->settings->getSlackConfig();
        if (empty($config['webhook_url'])) return false;

        $payload['unfurl_links'] = $config['unfurl_links'];
        $payload['unfurl_media'] = $config['unfurl_links'];

        if (!empty($config['username'])) {
            $payload['username'] = $config['username'];
        }

        if (!empty($config['icon_url'])) {
            $payload['icon_url'] = $config['icon_url'];
        } elseif (!empty($config['icon_emoji'])) {
            $payload['icon_emoji'] = $config['icon_emoji'];
        }

        $json    = json_encode($payload);
        $context = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: application/json\r\nContent-Length: " . strlen($json),
                'content' => $json,
                'timeout' => 5,
            ],
        ]);

        $result = @file_get_contents($config['webhook_url'], false, $context);
        return $result !== false;
    }
}

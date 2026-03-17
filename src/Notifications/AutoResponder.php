<?php
declare(strict_types=1);

namespace Andrea\Helpdesk\Notifications;

use Andrea\Helpdesk\Settings\SettingsService;

class AutoResponder
{
    public function __construct(
        private EmailNotifier $emailNotifier,
        private SettingsService $settings
    ) {}

    public function sendForNewTicket(array $ticket, array $customer): bool
    {
        $config = $this->settings->getEmailConfig();
        if (!$config['auto_response_enabled']) {
            return false;
        }

        $vars = [
            'customer_name'    => $customer['name'] ?? $customer['email'],
            'ticket_number'    => $ticket['ticket_number'],
            'subject'          => $ticket['subject'],
            'company_name'     => $this->settings->getCompanyName(),
            'global_signature' => $config['global_signature'],
        ];

        $subject = $this->renderTemplate($config['auto_response_subject'], $vars);
        $body    = $this->renderTemplate($config['auto_response_body'], $vars);

        $ticketNumber = $ticket['ticket_number'];
        $messageId    = $ticket['ticket_number'] . '.auto.' . time() . '@' . (parse_url(getenv('APP_URL') ?: '', PHP_URL_HOST) ?: 'helpdesk');

        $headers = [
            'Message-ID'  => "<{$messageId}>",
            'X-Ticket-ID' => $ticketNumber,
        ];

        if (!empty($ticket['original_message_id'])) {
            $origId = trim($ticket['original_message_id'], '<>');
            $headers['In-Reply-To'] = "<{$origId}>";
            $headers['References']  = "<{$origId}>";
        }

        $replyTo = $customer['email'];
        if (!empty($ticket['reply_to_address'])) {
            $replyTo = $ticket['reply_to_address'];
        }

        return $this->emailNotifier->sendRaw(
            $replyTo,
            $customer['name'] ?? '',
            $subject,
            $body,
            $headers,
            (int)$ticket['id']
        );
    }

    public function renderTemplate(string $template, array $vars): string
    {
        foreach ($vars as $key => $value) {
            $template = str_replace('{{' . $key . '}}', (string)$value, $template);
        }
        return $template;
    }
}

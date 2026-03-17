<?php
declare(strict_types=1);

namespace Andrea\Helpdesk\Notifications;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception as MailerException;
use Andrea\Helpdesk\Core\Database;
use Andrea\Helpdesk\Settings\SettingsService;
use Andrea\Helpdesk\Tickets\AttachmentService;
use Andrea\Helpdesk\IMAP\ImapAccountRepository;

class EmailNotifier
{
    private SettingsService $settings;

    public function __construct()
    {
        $this->settings = SettingsService::getInstance();
    }

    private function createMailer(): PHPMailer
    {
        $smtp   = $this->settings->getSmtpConfig();
        $mailer = new PHPMailer(true);

        $mailer->isSMTP();
        $mailer->Host        = $smtp['host'];
        $mailer->Port        = $smtp['port'];
        $mailer->SMTPAuth    = !empty($smtp['username']);
        $mailer->Username    = $smtp['username'];
        $mailer->Password    = $smtp['password'];
        $mailer->SMTPSecure  = match(strtolower($smtp['encryption'])) {
            'ssl'   => PHPMailer::ENCRYPTION_SMTPS,
            'tls'   => PHPMailer::ENCRYPTION_STARTTLS,
            default => '',
        };
        $mailer->Timeout     = 15; // seconds — prevent slow SMTP from stalling requests
        $mailer->CharSet     = PHPMailer::CHARSET_UTF8;
        $mailer->setFrom($smtp['from_address'], $smtp['from_name']);
        if (!empty($smtp['reply_to'])) {
            $mailer->addReplyTo($smtp['reply_to']);
        }

        return $mailer;
    }

    public function sendTicketReply(array $ticket, array $reply, array $agent, array $customer, array $ccEmails = [], array $attachmentIds = []): bool
    {
        try {
            $mailer = $this->createMailer();
            $this->applyTagFromAddress($mailer, (int)$ticket['id']);
            $mailer->addAddress($customer['email'], $customer['name'] ?? '');

            foreach ($ccEmails as $cc) {
                $mailer->addCC($cc['email'], $cc['name'] ?? '');
            }

            // Add participants as CC
            $db = Database::getInstance();
            $participants = $db->fetchAll(
                "SELECT email, name FROM ticket_participants WHERE ticket_id = ?",
                [$ticket['id']]
            );
            foreach ($participants as $p) {
                if ($p['email'] !== $customer['email']) {
                    $mailer->addCC($p['email'], $p['name'] ?? '');
                }
            }

            $ticketNumber = $ticket['ticket_number'];
            $mailer->Subject = "Re: {$ticket['subject']} [{$ticketNumber}]";

            // Email threading headers
            $messageId = $this->generateMessageId($ticketNumber);
            $mailer->addCustomHeader('Message-ID', "<{$messageId}>");
            $mailer->addCustomHeader('X-Ticket-ID', $ticketNumber);

            if (!empty($ticket['last_message_id'])) {
                $lastId = trim($ticket['last_message_id'], '<>');
                $mailer->addCustomHeader('In-Reply-To', "<{$lastId}>");
                $mailer->addCustomHeader('References', "<{$lastId}>");
            }

            $body = $this->applySignature($reply['body_html'], $agent);
            $mailer->isHTML(true);
            $mailer->Body    = $body;
            $mailer->AltBody = strip_tags($body);

            // Attach files
            if (!empty($attachmentIds)) {
                $attachService = new AttachmentService();
                foreach ($attachmentIds as $attachId) {
                    $attachment = $db->fetch("SELECT * FROM attachments WHERE id = ?", [$attachId]);
                    if ($attachment) {
                        $path = $attachService->getStoredPath($attachment['stored_path']);
                        if (file_exists($path)) {
                            $mailer->addAttachment($path, $attachment['filename']);
                        }
                    }
                }
            }

            $mailer->send();

            // Store message ID on reply and update ticket
            $db->execute("UPDATE replies SET raw_message_id = ?, email_sent_at = NOW() WHERE id = ?",
                [$messageId, $reply['id']]);
            $db->execute("UPDATE tickets SET last_message_id = ? WHERE id = ?",
                [$messageId, $ticket['id']]);

            return true;
        } catch (\Throwable $e) {
            $this->logError('sendTicketReply: ' . $e->getMessage());
            return false;
        }
    }

    public function sendNewTicketNotification(array $ticket, array $customer, array $agents): bool
    {
        try {
            $mailer = $this->createMailer();
            foreach ($agents as $agent) {
                if (!empty($agent['email'])) {
                    $mailer->addAddress($agent['email'], $agent['name']);
                }
            }

            $mailer->Subject = "[New Ticket] {$ticket['ticket_number']}: {$ticket['subject']}";
            $mailer->isHTML(true);

            $appUrl = getenv('APP_URL') ?: 'https://your-helpdesk-domain';
            $link   = "{$appUrl}/#/tickets/{$ticket['id']}";

            $mailer->Body = "
                <p>A new support ticket has been created.</p>
                <table>
                    <tr><td><strong>Ticket:</strong></td><td>{$ticket['ticket_number']}</td></tr>
                    <tr><td><strong>Subject:</strong></td><td>" . htmlspecialchars($ticket['subject']) . "</td></tr>
                    <tr><td><strong>Customer:</strong></td><td>" . htmlspecialchars($customer['name'] ?? $customer['email']) . "</td></tr>
                    <tr><td><strong>Priority:</strong></td><td>{$ticket['priority']}</td></tr>
                    <tr><td><strong>Channel:</strong></td><td>{$ticket['channel']}</td></tr>
                </table>
                <p><a href='{$link}'>View Ticket</a></p>
            ";
            $mailer->AltBody = "New ticket {$ticket['ticket_number']}: {$ticket['subject']} from {$customer['email']}. View: {$link}";

            $mailer->send();
            return true;
        } catch (\Throwable $e) {
            $this->logError('sendNewTicketNotification: ' . $e->getMessage());
            return false;
        }
    }

    public function sendAutoResponse(array $ticket, array $customer): bool
    {
        try {
            $emailConfig = $this->settings->getEmailConfig();
            if (!$emailConfig['auto_response_enabled']) return false;

            $autoResponder = new AutoResponder($this, $this->settings);
            return $autoResponder->sendForNewTicket($ticket, $customer);
        } catch (\Throwable $e) {
            $this->logError('sendAutoResponse: ' . $e->getMessage());
            return false;
        }
    }

    public function sendAgentNotification(int $agentId, string $subject, string $body): bool
    {
        try {
            $db    = Database::getInstance();
            $agent = $db->fetch("SELECT email, name FROM agents WHERE id = ?", [$agentId]);
            if (!$agent) return false;

            $mailer = $this->createMailer();
            $mailer->addAddress($agent['email'], $agent['name']);
            $mailer->Subject = $subject;
            $mailer->isHTML(true);
            $mailer->Body    = $body;
            $mailer->AltBody = strip_tags($body);
            $mailer->send();
            return true;
        } catch (\Throwable $e) {
            $this->logError('sendAgentNotification: ' . $e->getMessage());
            return false;
        }
    }

    public function sendPortalInvite(array $customer, string $magicLink): bool
    {
        try {
            $company = $this->settings->getCompanyName();
            $mailer  = $this->createMailer();
            $mailer->addAddress($customer['email'], $customer['name'] ?? '');
            $mailer->Subject = "Your support portal access - {$company}";
            $mailer->isHTML(true);
            $mailer->Body = "
                <p>Hello " . htmlspecialchars($customer['name'] ?? 'Customer') . ",</p>
                <p>You have been invited to access the {$company} support portal.</p>
                <p><a href='{$magicLink}'>Click here to log in</a></p>
                <p>This link will expire in 1 hour.</p>
                <p>{$this->settings->getEmailConfig()['global_signature']}</p>
            ";
            $mailer->AltBody = "Your portal login link: {$magicLink}";
            $mailer->send();
            return true;
        } catch (\Throwable $e) {
            $this->logError('sendPortalInvite: ' . $e->getMessage());
            return false;
        }
    }

    public function sendRaw(string $to, string $toName, string $subject, string $body, array $headers = [], ?int $ticketId = null): bool
    {
        try {
            $mailer = $this->createMailer();
            if ($ticketId) $this->applyTagFromAddress($mailer, $ticketId);
            $mailer->addAddress($to, $toName);
            $mailer->Subject = $subject;
            $mailer->isHTML(true);
            $mailer->Body    = $body;
            $mailer->AltBody = strip_tags($body);
            foreach ($headers as $name => $value) {
                $mailer->addCustomHeader($name, $value);
            }
            $mailer->send();
            return true;
        } catch (\Throwable $e) {
            $this->logError('sendRaw: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * If the ticket has a tag linked to an IMAP account with a from_address,
     * override the mailer's From/Reply-To with that address.
     */
    private function applyTagFromAddress(PHPMailer $mailer, int $ticketId): void
    {
        $db     = Database::getInstance();
        $tagIds = $db->fetchAll(
            "SELECT tag_id FROM ticket_tag_map WHERE ticket_id = ? ORDER BY tag_id ASC",
            [$ticketId]
        );
        if (empty($tagIds)) return;

        $repo    = new ImapAccountRepository();
        $account = $repo->findByTagIds(array_column($tagIds, 'tag_id'));
        if (!$account) return;

        $fromAddress = !empty($account['from_address']) ? $account['from_address'] : $account['username'];
        if (!$fromAddress) return;

        $smtp = $this->settings->getSmtpConfig();
        $mailer->setFrom($fromAddress, $smtp['from_name']);
        $mailer->clearReplyTos();
        $mailer->addReplyTo($fromAddress);
    }

    private function applySignature(string $body, array $agent): string
    {
        $emailConfig     = $this->settings->getEmailConfig();
        $globalSignature = $emailConfig['global_signature'] ?? '';
        $agentSignature  = $agent['signature'] ?? '';

        $signature = '';
        if ($agentSignature) {
            $signature .= '<br><br>' . $agentSignature;
        }
        if ($globalSignature) {
            $signature .= '<br>' . $globalSignature;
        }

        return $body . $signature;
    }

    private function generateMessageId(string $ticketNumber): string
    {
        $domain = parse_url(getenv('APP_URL') ?: 'support.example.com', PHP_URL_HOST) ?: 'support.example.com';
        return $ticketNumber . '.' . time() . '.' . bin2hex(random_bytes(4)) . '@' . $domain;
    }

    private function logError(string $message): void
    {
        $logFile = (getenv('STORAGE_PATH') ?: '/tmp') . '/logs/app.log';
        $dir = dirname($logFile);
        if (!is_dir($dir)) mkdir($dir, 0750, true);
        $line = '[' . date('Y-m-d H:i:s') . '] [ERROR] EmailNotifier: ' . $message . PHP_EOL;
        file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    }
}

<?php
declare(strict_types=1);

namespace Andrea\Helpdesk\IMAP;

use Andrea\Helpdesk\Settings\SettingsService;

class IncomingEmailBlockService
{
    public function __construct(private ?SettingsService $settings = null)
    {
        $this->settings = $settings ?: SettingsService::getInstance();
    }

    public function isBlocked(string $email): bool
    {
        $email = $this->normaliseEmail($email);
        if ($email === '') {
            return false;
        }

        $domain = substr(strrchr($email, '@') ?: '', 1);
        foreach ($this->blockedPatterns() as $pattern) {
            if ($pattern === $email) {
                return true;
            }
            if ($domain !== '' && str_starts_with($pattern, '*@') && substr($pattern, 2) === $domain) {
                return true;
            }
            if ($domain !== '' && !str_contains($pattern, '@') && $pattern === $domain) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return string[]
     */
    public function blockedPatterns(): array
    {
        $raw = $this->settings->get('incoming_email_blocklist', []);
        $items = is_array($raw) ? $raw : preg_split('/\r\n|\r|\n|,/', (string)$raw);
        $patterns = [];

        foreach ($items ?: [] as $item) {
            $pattern = $this->normalisePattern((string)$item);
            if ($pattern !== '') {
                $patterns[$pattern] = true;
            }
        }

        return array_keys($patterns);
    }

    public function renderMessage(array $ticket, array $customer, array $parsed): string
    {
        $template = trim((string)$this->settings->get(
            'incoming_email_block_message',
            'Your email has not been accepted by this helpdesk. This ticket has been closed automatically.'
        ));
        if ($template === '') {
            $template = 'Your email has not been accepted by this helpdesk. This ticket has been closed automatically.';
        }

        $vars = [
            'customer_name' => (string)($customer['name'] ?? $parsed['from_name'] ?? $parsed['from_email'] ?? ''),
            'customer_email' => (string)($customer['email'] ?? $parsed['from_email'] ?? ''),
            'ticket_number' => (string)($ticket['ticket_number'] ?? ''),
            'subject' => (string)($ticket['subject'] ?? $parsed['subject'] ?? ''),
            'app_name' => (string)$this->settings->get('company_name', 'Andrea Helpdesk'),
        ];

        foreach ($vars as $key => $value) {
            $template = str_replace('{{' . $key . '}}', $value, $template);
        }

        return nl2br(htmlspecialchars($template, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    }

    public function normalisePattern(string $pattern): string
    {
        $pattern = strtolower(trim($pattern));
        $pattern = trim($pattern, " \t\n\r\0\x0B,;<>");
        if ($pattern === '') {
            return '';
        }
        if (str_starts_with($pattern, '*@')) {
            $domain = substr($pattern, 2);
            return $this->isValidDomain($domain) ? '*@' . $domain : '';
        }
        if (str_contains($pattern, '@')) {
            return filter_var($pattern, FILTER_VALIDATE_EMAIL) ? $pattern : '';
        }
        return $this->isValidDomain($pattern) ? $pattern : '';
    }

    private function normaliseEmail(string $email): string
    {
        $email = strtolower(trim($email));
        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
    }

    private function isValidDomain(string $domain): bool
    {
        return preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i', $domain) === 1;
    }
}

<?php
declare(strict_types=1);

namespace Andrea\Helpdesk\Settings;

class SettingsService
{
    private static ?SettingsService $instance = null;
    private SettingsRepository $repo;

    private function __construct()
    {
        $this->repo = new SettingsRepository();
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->repo->get($key, $default);
    }

    public function getRepository(): SettingsRepository
    {
        return $this->repo;
    }

    public function getSmtpConfig(): array
    {
        $e = fn(string $key) => getenv($key) ?: null;
        return [
            'host'         => $e('SMTP_HOST')         ?? $this->repo->get('smtp_host', ''),
            'port'         => (int)($e('SMTP_PORT')   ?? $this->repo->get('smtp_port', 587)),
            'username'     => $e('SMTP_USERNAME')      ?? $this->repo->get('smtp_username', ''),
            'password'     => $e('SMTP_PASSWORD')      ?? $this->decrypt((string)$this->repo->get('smtp_password', '')),
            'from_address' => $e('SMTP_FROM_ADDRESS')  ?? $this->repo->get('smtp_from_address', ''),
            'from_name'    => $e('SMTP_FROM_NAME')     ?? $this->repo->get('smtp_from_name', 'Andrea Helpdesk'),
            'encryption'   => $e('SMTP_ENCRYPTION')    ?? $this->repo->get('smtp_encryption', 'tls'),
            'reply_to'     => $e('SMTP_REPLY_TO')      ?? $this->repo->get('reply_to_address', ''),
        ];
    }

    public function getImapConfig(): array
    {
        $e = fn(string $key) => getenv($key) ?: null;
        return [
            'host'                => $e('IMAP_HOST')       ?? $this->repo->get('imap_host', ''),
            'port'                => (int)($e('IMAP_PORT') ?? $this->repo->get('imap_port', 993)),
            'username'            => $e('IMAP_USERNAME')    ?? $this->repo->get('imap_username', ''),
            'password'            => $e('IMAP_PASSWORD')    ?? $this->decrypt((string)$this->repo->get('imap_password', '')),
            'folder'              => $e('IMAP_FOLDER')      ?? $this->repo->get('imap_folder', 'INBOX'),
            'encryption'          => $e('IMAP_ENCRYPTION')  ?? $this->repo->get('imap_encryption', 'ssl'),
            'delete_after_import' => filter_var(
                $e('IMAP_DELETE_AFTER_IMPORT') ?? $this->repo->get('imap_delete_after_import', false),
                FILTER_VALIDATE_BOOLEAN
            ),
        ];
    }

    public function getEmailConfig(): array
    {
        return [
            'global_signature'            => $this->repo->get('global_signature', ''),
            'include_portal_link_in_customer_emails' => (bool)$this->repo->get('include_portal_link_in_customer_emails', false),
            'auto_response_enabled'       => (bool)$this->repo->get('auto_response_enabled', true),
            'auto_response_subject'       => $this->repo->get('auto_response_subject', 'Re: {{subject}} [{{ticket_number}}]'),
            'auto_response_body'          => $this->repo->get('auto_response_body', ''),
            'notify_agent_on_new_ticket'  => (bool)$this->repo->get('notify_agent_on_new_ticket', true),
            'notify_agent_on_new_reply'   => (bool)$this->repo->get('notify_agent_on_new_reply', true),
            'incoming_email_blocklist'     => (array)$this->repo->get('incoming_email_blocklist', []),
            'incoming_email_block_message' => (string)$this->repo->get('incoming_email_block_message', 'Your email has not been accepted by this helpdesk. This ticket has been closed automatically.'),
        ];
    }

    public function getChatConfig(): array
    {
        $managementMode = (string)$this->repo->get('chat_websocket_management_mode', 'cron');
        $host = trim((string)$this->repo->get('chat_websocket_host', '127.0.0.1'));
        if (!$this->isValidWebsocketHost($host)) {
            $host = '127.0.0.1';
        }
        $port = max(1, min(65535, (int)$this->repo->get('chat_websocket_port', 8090)));

        return [
            'enabled' => (bool)$this->repo->get('chat_enabled', false),
            'default_channel_retention_days' => max(1, (int)$this->repo->get('chat_default_channel_retention_days', 90)),
            'direct_retention_days' => max(1, (int)$this->repo->get('chat_direct_retention_days', 90)),
            'max_message_length' => max(1, (int)$this->repo->get('chat_max_message_length', 4000)),
            'allow_external_links' => (bool)$this->repo->get('chat_allow_external_links', true),
            'websocket_enabled' => (bool)$this->repo->get('chat_websocket_enabled', true),
            'websocket_autostart' => (bool)$this->repo->get('chat_websocket_autostart', true),
            'websocket_management_mode' => in_array($managementMode, ['cron', 'external'], true) ? $managementMode : 'cron',
            'websocket_host' => $host,
            'websocket_port' => $port,
        ];
    }

    public function getCacheConfig(): array
    {
        return [
            'enabled' => (bool)$this->repo->get('cache_enabled', false),
            'cache_home' => (string)$this->repo->get('cache_home', 'cache'),
            'ttl' => max(5, min(86400, (int)$this->repo->get('cache_ttl_seconds', 60))),
            'redis_host' => (string)$this->repo->get('redis_host', '127.0.0.1'),
            'redis_port' => max(1, min(65535, (int)$this->repo->get('redis_port', 6379))),
            'redis_prefix' => (string)$this->repo->get('redis_prefix', 'andrea_helpdesk'),
            'redis_database' => max(0, min(15, (int)$this->repo->get('redis_database', 1))),
        ];
    }

    public function getSupportFormConfig(): array
    {
        return [
            'recaptcha_site_key' => (string)$this->repo->get('support_form_recaptcha_site_key', ''),
            'recaptcha_secret_key' => $this->decrypt((string)$this->repo->get('support_form_recaptcha_secret_key', '')),
            'allowed_embed_origins' => $this->normalizeOriginList($this->repo->get('support_form_allowed_origins', [])),
        ];
    }

    public function normalizeSupportFormAllowedOrigins(mixed $value): array
    {
        return $this->normalizeOriginList($value);
    }

    public function getSlackConfig(): array
    {
        return [
            'enabled'        => (bool)$this->repo->get('slack_enabled', false),
            'webhook_url'    => $this->repo->get('slack_webhook_url', ''),
            'channel'        => $this->repo->get('slack_channel', '#helpdesk'),
            'on_new_ticket'  => (bool)$this->repo->get('slack_on_new_ticket', true),
            'on_assign'      => (bool)$this->repo->get('slack_on_assign', true),
            'on_new_reply'   => (bool)$this->repo->get('slack_on_new_reply', true),
            'unfurl_links'   => (bool)$this->repo->get('slack_unfurl_links', true),
            'username'       => $this->repo->get('slack_username', ''),
            'icon_url'       => $this->repo->get('slack_icon_url', ''),
            'icon_emoji'     => $this->repo->get('slack_icon_emoji', ''),
        ];
    }

    public function getBrandingConfig(): array
    {
        return [
            'company_name'  => $this->repo->get('company_name', 'Andrea Helpdesk'),
            'logo_url'      => $this->repo->get('logo_url', ''),
            'primary_color' => $this->repo->get('primary_color', '#0d6efd'),
            'accent_color'  => $this->repo->get('accent_color', '#6610f2'),
            'custom_css'    => $this->repo->get('custom_css', ''),
        ];
    }

    public function getSlaConfig(): array
    {
        $scope = (string)$this->repo->get('sla_notify_scope', 'all');

        return [
            'enabled'            => (bool)$this->repo->get('sla_enabled', false),
            'high_after_days'    => max(0, (int)$this->repo->get('sla_high_after_days', 3)),
            'overdue_after_days' => max(0, (int)$this->repo->get('sla_overdue_after_days', 2)),
            'notify_scope'       => in_array($scope, ['all', 'specific'], true) ? $scope : 'all',
            'notify_agent_ids'   => array_values(array_filter(
                array_map('intval', (array)$this->repo->get('sla_notify_agent_ids', [])),
                fn(int $id): bool => $id > 0
            )),
        ];
    }

    public function getTicketPrefix(): string
    {
        $env = getenv('TICKET_PREFIX');
        if ($env) return (string)$env;
        return (string)$this->repo->get('ticket_prefix', 'HD');
    }

    public function getTimezone(): string
    {
        return (string)$this->repo->get('timezone', 'UTC');
    }

    public function getDateFormat(): string
    {
        return (string)$this->repo->get('date_format', 'd/m/Y H:i');
    }

    public function getCompanyName(): string
    {
        return (string)$this->repo->get('company_name', 'Andrea Helpdesk');
    }

    public function encrypt(string $value): string
    {
        if (empty($value)) return '';
        $key    = substr(hash('sha256', $this->getEncryptionKey(), true), 0, 32);
        $iv     = random_bytes(16);
        $cipher = openssl_encrypt($value, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        return base64_encode($iv . $cipher);
    }

    public function decrypt(string $value): string
    {
        if (empty($value)) return '';
        try {
            $data   = base64_decode($value);
            if (strlen($data) <= 16) return $value; // Not encrypted
            $key    = substr(hash('sha256', $this->getEncryptionKey(), true), 0, 32);
            $iv     = substr($data, 0, 16);
            $cipher = substr($data, 16);
            $result = openssl_decrypt($cipher, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
            return $result !== false ? $result : $value;
        } catch (\Throwable) {
            return $value;
        }
    }

    private function getEncryptionKey(): string
    {
        return (string)getenv('JWT_SECRET');
    }

    private function normalizeOriginList(mixed $value): array
    {
        $items = is_array($value) ? $value : preg_split('/\r\n|\r|\n/', (string)$value);
        $seen = [];
        $origins = [];

        foreach ($items as $item) {
            $item = trim((string)$item);
            if ($item === '') {
                continue;
            }
            $parts = parse_url($item);
            if ($parts === false) {
                continue;
            }

            $scheme = strtolower((string)($parts['scheme'] ?? ''));
            $host = strtolower((string)($parts['host'] ?? ''));
            if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
                continue;
            }
            if (isset($parts['path']) && $parts['path'] !== '' && $parts['path'] !== '/') {
                continue;
            }
            if (isset($parts['query']) || isset($parts['fragment']) || isset($parts['user']) || isset($parts['pass'])) {
                continue;
            }

            $origin = $scheme . '://' . $host;
            if (isset($parts['port']) && $parts['port'] !== null) {
                $origin .= ':' . (int)$parts['port'];
            }

            if (isset($seen[$origin])) {
                continue;
            }
            $seen[$origin] = true;
            $origins[] = $origin;
        }

        return $origins;
    }

    private function isValidWebsocketHost(string $host): bool
    {
        if ($host === '' || strlen($host) > 255) {
            return false;
        }
        if (preg_match('/[\s\/\\\\]/', $host) || str_contains($host, '://')) {
            return false;
        }
        if (str_contains($host, ':')) {
            return (bool)preg_match('/^\[[0-9a-f:.]+\]$/i', $host);
        }
        return (bool)preg_match('/^[a-z0-9._-]+$/i', $host);
    }
}

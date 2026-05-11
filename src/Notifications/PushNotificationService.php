<?php
declare(strict_types=1);

namespace Andrea\Helpdesk\Notifications;

use Andrea\Helpdesk\Settings\SettingsService;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\VAPID;
use Minishlink\WebPush\WebPush;

class PushNotificationService
{
    private SettingsService $settings;
    private PushSubscriptionRepository $subscriptions;

    public function __construct(?SettingsService $settings = null, ?PushSubscriptionRepository $subscriptions = null)
    {
        $this->settings = $settings ?: SettingsService::getInstance();
        $this->subscriptions = $subscriptions ?: new PushSubscriptionRepository();
    }

    public function isConfigured(): bool
    {
        $config = $this->getConfig();
        return $config['public_key'] !== '' && $config['private_key'] !== '' && $config['subject'] !== '';
    }

    public function getPublicConfig(): array
    {
        $config = $this->getConfig(false);
        return [
            'configured' => $config['public_key'] !== '' && $config['subject'] !== '',
            'public_key' => $config['public_key'],
            'subject' => $config['subject'],
        ];
    }

    public function getAdminStatus(): array
    {
        $config = $this->getConfig();
        $status = 'not_configured';
        $message = 'Push notifications are not configured.';

        if ($config['public_key'] !== '' || $config['private_key'] !== '' || $config['subject'] !== '') {
            try {
                VAPID::validate([
                    'subject' => $config['subject'],
                    'publicKey' => $config['public_key'],
                    'privateKey' => $config['private_key'],
                ]);
                $status = 'configured';
                $message = 'Push notifications are configured.';
            } catch (\Throwable) {
                $status = 'invalid';
                $message = 'Public/private key mismatch or invalid VAPID subject.';
            }
        }

        return [
            'status' => $status,
            'message' => $message,
            'configured' => $status === 'configured',
            'public_key_present' => $config['public_key'] !== '',
            'private_key_present' => $config['private_key'] !== '',
            'subject' => $config['subject'],
            'diagnostics' => [
                ...$this->subscriptions->diagnostics(),
                'php_extensions' => [
                    'curl' => extension_loaded('curl'),
                    'mbstring' => extension_loaded('mbstring'),
                    'openssl' => extension_loaded('openssl'),
                ],
                'openssl_prime256v1' => function_exists('openssl_get_curve_names') && in_array('prime256v1', openssl_get_curve_names() ?: [], true),
                'last_send_failed_at' => (string)$this->settings->get('push_last_send_failed_at', ''),
                'last_send_failure' => (string)$this->settings->get('push_last_send_failure', ''),
            ],
        ];
    }

    public function sendToAgents(array $agentIds, array $payload): void
    {
        if (!$this->isConfigured()) {
            return;
        }

        $agentIds = array_values(array_unique(array_filter(array_map('intval', $agentIds), fn(int $id): bool => $id > 0)));
        if (!$agentIds) {
            return;
        }

        $subscriptions = $this->subscriptions->listForAgents($agentIds);
        if (!$subscriptions) {
            return;
        }

        $config = $this->getConfig();
        $auth = [
            'VAPID' => [
                'subject' => $config['subject'],
                'publicKey' => $config['public_key'],
                'privateKey' => $config['private_key'],
            ],
        ];

        $safePayload = json_encode([
            'title' => $this->safeText((string)($payload['title'] ?? 'Andrea Helpdesk'), 180),
            'body' => $this->safeText((string)($payload['body'] ?? ''), 240),
            'link' => $this->safeLink((string)($payload['link'] ?? '/my-profile/notifications')),
            'tag' => 'andrea-helpdesk-' . preg_replace('/[^a-zA-Z0-9:_-]/', '-', (string)($payload['dedupe_key'] ?? uniqid('push:', true))),
            'icon' => '/Andrea-Helpdesk-favicon.png',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($safePayload === false) {
            return;
        }

        $webPush = new WebPush($auth);
        foreach ($subscriptions as $row) {
            try {
                $webPush->queueNotification(Subscription::create([
                    'endpoint' => $row['endpoint'],
                    'keys' => [
                        'p256dh' => $row['p256dh'],
                        'auth' => $row['auth'],
                    ],
                    'contentEncoding' => $row['content_encoding'] ?: 'aes128gcm',
                ]), $safePayload, ['TTL' => 3600]);
            } catch (\Throwable $e) {
                $this->recordFailure('queue: ' . $e->getMessage());
                $this->log('queue: ' . $e->getMessage());
            }
        }

        foreach ($webPush->flush() as $report) {
            if ($report->isSubscriptionExpired()) {
                $this->subscriptions->deleteByEndpoint($report->getEndpoint());
            } elseif (!$report->isSuccess()) {
                $this->recordFailure('send: ' . $report->getReason());
                $this->log('send: ' . $report->getReason());
            }
        }
    }

    private function getConfig(bool $includePrivate = true): array
    {
        return [
            'public_key' => (string)$this->settings->get('push_vapid_public_key', ''),
            'private_key' => $includePrivate ? $this->settings->decrypt((string)$this->settings->get('push_vapid_private_key', '')) : '',
            'subject' => (string)$this->settings->get('push_vapid_subject', ''),
        ];
    }

    private function safeText(string $value, int $maxLength): string
    {
        $value = trim(preg_replace('/\s+/', ' ', strip_tags($value)) ?: '');
        return mb_substr($value, 0, $maxLength);
    }

    private function safeLink(string $link): string
    {
        $link = trim($link);
        if ($link === '' || $link[0] !== '/' || str_starts_with($link, '//') || preg_match('/[\r\n]/', $link)) {
            return '/my-profile/notifications';
        }

        return mb_substr($link, 0, 255);
    }

    private function log(string $message): void
    {
        $logFile = (getenv('STORAGE_PATH') ?: '/tmp') . '/logs/app.log';
        $dir = dirname($logFile);
        if (!is_dir($dir)) mkdir($dir, 0750, true);
        file_put_contents($logFile, '[' . date('Y-m-d H:i:s') . '] [WARN] PushNotificationService: ' . $message . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    private function recordFailure(string $message): void
    {
        try {
            $this->settings->getRepository()->setMany([
                'push_last_send_failed_at' => date('Y-m-d H:i:s'),
                'push_last_send_failure' => substr($message, 0, 500),
            ]);
        } catch (\Throwable) {
            // Logging remains the fallback if settings cannot be written.
        }
    }
}

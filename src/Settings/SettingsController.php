<?php
declare(strict_types=1);

namespace Andrea\Helpdesk\Settings;

use Andrea\Helpdesk\Core\DependencyRepairService;
use Andrea\Helpdesk\Core\Sanitizer;
use Andrea\Helpdesk\Core\Request;
use Andrea\Helpdesk\Core\Response;
use Andrea\Helpdesk\Notifications\EmailNotifier;
use Andrea\Helpdesk\Notifications\PushNotificationService;
use Andrea\Helpdesk\Notifications\SlackNotifier;
use Andrea\Helpdesk\Tickets\AttachmentService;
use Minishlink\WebPush\VAPID;

class SettingsController
{
    private SettingsService $service;
    private SettingsRepository $repo;

    public function __construct()
    {
        $this->service = SettingsService::getInstance();
        $this->repo    = $this->service->getRepository();
    }

    /**
     * GET /api/settings/public — no auth required
     */
    public function publicSettings(Request $request): void
    {
        $keys = ['company_name', 'logo_url', 'primary_color', 'date_format', 'favicon_url', 'global_signature', 'imap_poll_mode', 'support_form_recaptcha_site_key', 'push_vapid_public_key'];
        $data = [];
        foreach ($keys as $key) {
            $data[$key] = $this->repo->get($key);
        }
        $attachmentService = new AttachmentService();
        $data['support_form_attachment_max_bytes'] = $attachmentService->getMaxSizeBytes();
        $data['support_form_attachment_mime_types'] = $attachmentService->getAllowedMimeTypes();
        Response::success($data);
    }

    /**
     * GET /api/admin/settings?group=email
     */
    public function index(Request $request): void
    {
        $group = $request->input('group');

        if ($group) {
            $settings = $this->repo->getGroup($group);
        } else {
            $settings = $this->repo->getAll();
        }

        // Mask sensitive values
        $sensitiveKeys = ['smtp_password', 'imap_password', 'support_form_recaptcha_secret_key', 'push_vapid_private_key'];
        array_walk_recursive($settings, function (&$item, $key) use ($sensitiveKeys) {
            if (in_array($key, $sensitiveKeys, true) && !empty($item)) {
                $item = '***';
            }
        });

        Response::success($settings);
    }

    /**
     * PUT /api/admin/settings
     * Body: { settings: { key: value, ... } }
     */
    public function update(Request $request): void
    {
        $data = $request->input('settings', []);
        if (!is_array($data) || empty($data)) {
            Response::error('settings object is required', 400);
            return;
        }

        if (array_key_exists('sla_high_after_days', $data)) {
            $data['sla_high_after_days'] = max(0, (int)$data['sla_high_after_days']);
        }
        if (array_key_exists('sla_overdue_after_days', $data)) {
            $data['sla_overdue_after_days'] = max(0, (int)$data['sla_overdue_after_days']);
        }
        if (array_key_exists('sla_notify_scope', $data)) {
            $data['sla_notify_scope'] = in_array($data['sla_notify_scope'], ['all', 'specific'], true)
                ? $data['sla_notify_scope']
                : 'all';
        }
        if (array_key_exists('sla_notify_agent_ids', $data)) {
            $data['sla_notify_agent_ids'] = array_values(array_filter(
                array_map('intval', (array)$data['sla_notify_agent_ids']),
                fn(int $id): bool => $id > 0
            ));
        }
        if (($data['sla_notify_scope'] ?? null) === 'specific' && empty($data['sla_notify_agent_ids'])) {
            Response::error('Select at least one SLA reminder recipient when using specific agents', 422);
            return;
        }
        if (array_key_exists('support_form_allowed_origins', $data)) {
            $data['support_form_allowed_origins'] = $this->service->normalizeSupportFormAllowedOrigins($data['support_form_allowed_origins']);
        }
        if (array_key_exists('push_vapid_subject', $data)) {
            $data['push_vapid_subject'] = $this->normalizeVapidSubject((string)$data['push_vapid_subject']);
        }
        if (
            array_key_exists('push_vapid_public_key', $data)
            || array_key_exists('push_vapid_private_key', $data)
            || array_key_exists('push_vapid_subject', $data)
        ) {
            $dependency = $this->ensureWebPushDependency();
            if (!$dependency['available']) {
                Response::error('Web Push dependency is missing. ' . $dependency['message'], 503);
                return;
            }
            $publicKey = (string)($data['push_vapid_public_key'] ?? $this->repo->get('push_vapid_public_key', ''));
            $privateKey = (string)($data['push_vapid_private_key'] ?? $this->service->decrypt((string)$this->repo->get('push_vapid_private_key', '')));
            $subject = (string)($data['push_vapid_subject'] ?? $this->repo->get('push_vapid_subject', ''));
            if ($publicKey !== '' || $privateKey !== '' || $subject !== '') {
                try {
                    VAPID::validate([
                        'subject' => $subject,
                        'publicKey' => $publicKey,
                        'privateKey' => $privateKey,
                    ]);
                } catch (\Throwable $e) {
                    Response::error('Invalid VAPID configuration: ' . $e->getMessage(), 422);
                    return;
                }
            }
        }

        foreach (['global_signature', 'auto_response_body'] as $htmlKey) {
            if (array_key_exists($htmlKey, $data)) {
                $data[$htmlKey] = Sanitizer::html((string)$data[$htmlKey]);
            }
        }

        // Encrypt passwords before saving
        $sensitiveKeys = ['smtp_password', 'imap_password', 'support_form_recaptcha_secret_key', 'push_vapid_private_key'];
        foreach ($sensitiveKeys as $key) {
            if (isset($data[$key]) && $data[$key] !== '***' && $data[$key] !== '') {
                $data[$key] = $this->service->encrypt($data[$key]);
            } elseif (isset($data[$key]) && ($data[$key] === '***' || $data[$key] === '')) {
                unset($data[$key]); // Don't overwrite with masked/empty value
            }
        }

        $this->repo->setMany($data);
        Response::success(null, 'Settings saved');
    }

    public function generatePushKeys(Request $request): void
    {
        try {
            $dependency = $this->ensureWebPushDependency();
            if (!$dependency['available']) {
                Response::error('Web Push dependency is missing. ' . $dependency['message'], 503);
                return;
            }
            $keys = VAPID::createVapidKeys();
            $subject = $this->normalizeVapidSubject(
                (string)$this->repo->get('push_vapid_subject', '')
            );
            if ($subject === '') {
                $appUrl = rtrim((string)($this->repo->get('app_url', '') ?: getenv('APP_URL') ?: ''), '/');
                $subject = $appUrl !== '' ? $appUrl : 'mailto:admin@example.com';
            }

            $this->repo->setMany([
                'push_vapid_public_key' => $keys['publicKey'],
                'push_vapid_private_key' => $this->service->encrypt($keys['privateKey']),
                'push_vapid_subject' => $subject,
            ]);

            Response::success([
                'push_vapid_public_key' => $keys['publicKey'],
                'push_vapid_subject' => $subject,
                'status' => (new PushNotificationService())->getAdminStatus(),
            ], 'VAPID keys generated');
        } catch (\Throwable $e) {
            Response::error('Failed to generate VAPID keys: ' . $e->getMessage(), 500);
        }
    }

    private function ensureWebPushDependency(): array
    {
        if (class_exists(VAPID::class)) {
            return ['available' => true, 'message' => ''];
        }

        $repair = (new DependencyRepairService())->ensureClasses([VAPID::class]);
        return [
            'available' => (bool)$repair['available'],
            'message' => (string)$repair['message'],
        ];
    }

    public function pushStatus(Request $request): void
    {
        Response::success((new PushNotificationService())->getAdminStatus());
    }

    /**
     * POST /api/admin/settings/test-smtp
     */
    public function testSmtp(Request $request): void
    {
        try {
            $notifier = new EmailNotifier();
            $toEmail  = $request->agent->email;
            $result   = $notifier->sendAgentNotification(
                $request->agent->id ?? 0,
                'Andrea Helpdesk - SMTP Test',
                '<p>This is a test email from Andrea Helpdesk. Your SMTP configuration is working correctly.</p>'
            );

            if ($result) {
                Response::success(null, "Test email sent to {$toEmail}");
            } else {
                Response::error('Failed to send test email');
            }
        } catch (\Throwable $e) {
            Response::error('SMTP test failed: ' . $e->getMessage());
        }
    }

    /**
     * POST /api/admin/settings/test-imap
     */
    public function testImap(Request $request): void
    {
        $config = $this->service->getImapConfig();

        if (empty($config['host'])) {
            Response::error('IMAP host is not configured');
            return;
        }

        try {
            $enc     = $config['encryption'] === 'ssl' ? '/ssl' : '/tls';
            $mailbox = "{{{$config['host']}:{$config['port']}/imap{$enc}}}{$config['folder']}";
            $conn    = @imap_open($mailbox, $config['username'], $config['password'], 0, 1);

            if (!$conn) {
                $error = imap_last_error();
                Response::error('IMAP connection failed: ' . ($error ?: 'Unknown error'));
                return;
            }

            $count = imap_num_msg($conn);
            imap_close($conn);

            Response::success(['message_count' => $count], "IMAP connection successful. {$count} messages in folder.");
        } catch (\Throwable $e) {
            Response::error('IMAP test failed: ' . $e->getMessage());
        }
    }

    /**
     * POST /api/admin/settings/test-slack
     */
    public function testSlack(Request $request): void
    {
        try {
            $notifier = new SlackNotifier($this->service);
            $result   = $notifier->sendMessage(':white_check_mark: Andrea Helpdesk Slack integration test - working correctly!');

            if ($result) {
                Response::success(null, 'Slack test message sent');
            } else {
                Response::error('Slack is disabled or webhook URL not configured');
            }
        } catch (\Throwable $e) {
            Response::error('Slack test failed: ' . $e->getMessage());
        }
    }

    private function normalizeVapidSubject(string $subject): string
    {
        $subject = trim($subject);
        if ($subject === '') {
            return '';
        }

        if (str_starts_with($subject, 'mailto:')) {
            $email = substr($subject, 7);
            return filter_var($email, FILTER_VALIDATE_EMAIL) ? 'mailto:' . strtolower($email) : $subject;
        }

        $parts = parse_url($subject);
        if ($parts === false) {
            return $subject;
        }
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = strtolower((string)($parts['host'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            return $subject;
        }

        $origin = $scheme . '://' . $host;
        if (isset($parts['port'])) {
            $origin .= ':' . (int)$parts['port'];
        }
        return $origin;
    }
}

<?php
declare(strict_types=1);

namespace Andrea\Helpdesk\Settings;

use Andrea\Helpdesk\Core\Request;
use Andrea\Helpdesk\Core\Response;
use Andrea\Helpdesk\Notifications\EmailNotifier;
use Andrea\Helpdesk\Notifications\SlackNotifier;

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
        $keys = ['company_name', 'logo_url', 'primary_color', 'date_format', 'favicon_url', 'global_signature'];
        $data = [];
        foreach ($keys as $key) {
            $data[$key] = $this->repo->get($key);
        }
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
        $sensitiveKeys = ['smtp_password', 'imap_password'];
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

        // Encrypt passwords before saving
        $sensitiveKeys = ['smtp_password', 'imap_password'];
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
}

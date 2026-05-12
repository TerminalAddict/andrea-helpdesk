<?php
declare(strict_types=1);

namespace Andrea\Helpdesk\Chat;

use Andrea\Helpdesk\Core\Database;
use Andrea\Helpdesk\Core\EmoticonNormalizer;
use Andrea\Helpdesk\Core\Exceptions\HttpException;
use Andrea\Helpdesk\Notifications\NotificationService;
use Andrea\Helpdesk\Settings\SettingsService;

class ChatService
{
    private Database $db;
    private SettingsService $settings;
    private ChatRenderService $renderer;

    public function __construct()
    {
        $this->db       = Database::getInstance();
        $this->settings = SettingsService::getInstance();
        $this->renderer = new ChatRenderService();
    }

    public function config(): array
    {
        return $this->settings->getChatConfig();
    }

    public function ensureEnabled(): void
    {
        if (empty($this->config()['enabled'])) {
            throw new HttpException('Internal chat is disabled', 403);
        }
    }

    public function listChannelsForAgent(int $agentId): array
    {
        $this->ensureEnabled();
        return $this->db->fetchAll(
            "SELECT c.id, c.name, c.slug, c.description, c.retention_days, c.updated_at,
                    m.can_post,
                    COALESCE(r.last_read_message_id, 0) AS last_read_message_id,
                    (
                        SELECT COUNT(*)
                          FROM chat_messages cm
                         WHERE cm.message_scope = 'channel'
                           AND cm.channel_id = c.id
                           AND cm.deleted_at IS NULL
                           AND cm.id > COALESCE(r.last_read_message_id, 0)
                    ) AS unread_count
               FROM chat_channels c
               JOIN chat_channel_members m ON m.channel_id = c.id AND m.agent_id = ?
               LEFT JOIN chat_message_reads r
                      ON r.agent_id = ?
                     AND r.message_scope = 'channel'
                     AND r.channel_id = c.id
              WHERE c.is_active = 1
           ORDER BY c.name ASC",
            [$agentId, $agentId]
        );
    }

    public function listAllChannels(): array
    {
        return $this->db->fetchAll(
            "SELECT c.*,
                    a.name AS created_by_name,
                    COUNT(m.agent_id) AS member_count
               FROM chat_channels c
          LEFT JOIN agents a ON a.id = c.created_by_agent_id
          LEFT JOIN chat_channel_members m ON m.channel_id = c.id
           GROUP BY c.id
           ORDER BY c.is_active DESC, c.name ASC"
        );
    }

    public function channelMembers(int $channelId): array
    {
        return $this->db->fetchAll(
            "SELECT a.id, a.name, a.email, a.role, a.is_active, a.chat_handle, m.can_post
               FROM chat_channel_members m
               JOIN agents a ON a.id = m.agent_id
              WHERE m.channel_id = ?
           ORDER BY a.name ASC",
            [$channelId]
        );
    }

    public function createChannel(array $data, int $adminAgentId): array
    {
        $name = $this->cleanName((string)($data['name'] ?? ''));
        if ($name === '') {
            throw new HttpException('Channel name is required', 422);
        }

        $slug = $this->slug((string)($data['slug'] ?? $name));
        $retention = $this->optionalPositiveInt($data['retention_days'] ?? null);

        $id = $this->db->insert(
            "INSERT INTO chat_channels (name, slug, description, retention_days, created_by_agent_id)
             VALUES (?, ?, ?, ?, ?)",
            [
                $name,
                $slug,
                $this->nullableString($data['description'] ?? null, 255),
                $retention,
                $adminAgentId,
            ]
        );

        $this->setChannelMembers($id, $this->memberPayload($data['members'] ?? []), $adminAgentId);
        return $this->adminChannel($id);
    }

    public function updateChannel(int $channelId, array $data, int $adminAgentId): array
    {
        $channel = $this->adminChannel($channelId);
        if (!$channel) {
            throw new HttpException('Chat channel not found', 404);
        }

        $fields = [];
        $params = [];
        if (array_key_exists('name', $data)) {
            $name = $this->cleanName((string)$data['name']);
            if ($name === '') {
                throw new HttpException('Channel name is required', 422);
            }
            $fields[] = 'name = ?';
            $params[] = $name;
        }
        if (array_key_exists('slug', $data)) {
            $fields[] = 'slug = ?';
            $params[] = $this->slug((string)$data['slug']);
        }
        if (array_key_exists('description', $data)) {
            $fields[] = 'description = ?';
            $params[] = $this->nullableString($data['description'], 255);
        }
        if (array_key_exists('retention_days', $data)) {
            $fields[] = 'retention_days = ?';
            $params[] = $this->optionalPositiveInt($data['retention_days']);
        }
        if (array_key_exists('is_active', $data)) {
            $fields[] = 'is_active = ?';
            $params[] = empty($data['is_active']) ? 0 : 1;
        }

        if ($fields) {
            $params[] = $channelId;
            $this->db->execute(
                "UPDATE chat_channels SET " . implode(', ', $fields) . " WHERE id = ?",
                $params
            );
        }

        if (array_key_exists('members', $data)) {
            $this->setChannelMembers($channelId, $this->memberPayload($data['members']), $adminAgentId);
        }

        return $this->adminChannel($channelId);
    }

    public function deactivateChannel(int $channelId): void
    {
        $this->db->execute("UPDATE chat_channels SET is_active = 0 WHERE id = ?", [$channelId]);
    }

    public function deleteChannel(int $channelId): void
    {
        $this->db->execute("DELETE FROM chat_channels WHERE id = ?", [$channelId]);
    }

    public function messagesForChannel(int $channelId, int $agentId, int $limit, ?int $afterId = null): array
    {
        $this->ensureChannelMember($channelId, $agentId);
        return $this->fetchMessages('channel', $channelId, $limit, $afterId);
    }

    public function createChannelMessage(int $channelId, int $senderAgentId, string $body): array
    {
        $this->ensureEnabled();
        $member = $this->channelMember($channelId, $senderAgentId);
        if (!$member || empty($member['can_post'])) {
            throw new HttpException('You cannot post in this chat channel', 403);
        }

        $message = $this->storeMessage('channel', $channelId, $senderAgentId, $body);
        $this->notifyForChannelMessage($message, $channelId, $senderAgentId);
        return $message;
    }

    public function listDirectThreads(int $agentId): array
    {
        $this->ensureEnabled();
        return $this->db->fetchAll(
            "SELECT t.id, t.last_message_at, t.updated_at,
                    other_agent.id AS other_agent_id,
                    other_agent.name AS other_agent_name,
                    other_agent.email AS other_agent_email,
                    other_agent.chat_handle AS other_agent_chat_handle,
                    other_agent.is_active AS other_agent_is_active,
                    COALESCE(r.last_read_message_id, 0) AS last_read_message_id,
                    (
                        SELECT COUNT(*)
                          FROM chat_messages cm
                         WHERE cm.message_scope = 'direct'
                           AND cm.thread_id = t.id
                           AND cm.deleted_at IS NULL
                           AND cm.sender_agent_id <> ?
                           AND cm.id > COALESCE(r.last_read_message_id, 0)
                    ) AS unread_count
               FROM chat_threads t
               JOIN agents other_agent
                 ON other_agent.id = CASE WHEN t.agent_one_id = ? THEN t.agent_two_id ELSE t.agent_one_id END
          LEFT JOIN chat_message_reads r
                 ON r.agent_id = ?
                AND r.message_scope = 'direct'
                AND r.thread_id = t.id
              WHERE t.agent_one_id = ? OR t.agent_two_id = ?
           ORDER BY COALESCE(t.last_message_at, t.created_at) DESC",
            [$agentId, $agentId, $agentId, $agentId, $agentId]
        );
    }

    public function startDirectThread(int $agentId, int $otherAgentId): array
    {
        $this->ensureEnabled();
        if ($agentId === $otherAgentId) {
            throw new HttpException('You cannot direct message yourself', 422);
        }

        $other = $this->db->fetch("SELECT id, is_active FROM agents WHERE id = ?", [$otherAgentId]);
        if (!$other || empty($other['is_active'])) {
            throw new HttpException('Disabled agents cannot receive new direct messages', 422);
        }

        [$one, $two] = $this->directPair($agentId, $otherAgentId);
        $existing = $this->db->fetch(
            "SELECT id FROM chat_threads WHERE agent_one_id = ? AND agent_two_id = ?",
            [$one, $two]
        );
        if ($existing) {
            return $this->directThread((int)$existing['id'], $agentId);
        }

        $this->db->execute(
            "INSERT IGNORE INTO chat_threads (agent_one_id, agent_two_id) VALUES (?, ?)",
            [$one, $two]
        );
        $thread = $this->db->fetch(
            "SELECT id FROM chat_threads WHERE agent_one_id = ? AND agent_two_id = ?",
            [$one, $two]
        );
        if (!$thread) {
            throw new HttpException('Direct message thread could not be created', 500);
        }

        return $this->directThread((int)$thread['id'], $agentId);
    }

    public function messagesForDirectThread(int $threadId, int $agentId, int $limit, ?int $afterId = null): array
    {
        $this->ensureDirectParticipant($threadId, $agentId);
        return $this->fetchMessages('direct', $threadId, $limit, $afterId);
    }

    public function createDirectMessage(int $threadId, int $senderAgentId, string $body): array
    {
        $thread = $this->ensureDirectParticipant($threadId, $senderAgentId);
        $recipientId = (int)$thread['agent_one_id'] === $senderAgentId
            ? (int)$thread['agent_two_id']
            : (int)$thread['agent_one_id'];

        $recipient = $this->db->fetch("SELECT id, is_active FROM agents WHERE id = ?", [$recipientId]);
        if (!$recipient || empty($recipient['is_active'])) {
            throw new HttpException('Disabled agents cannot receive new direct messages', 422);
        }

        $message = $this->storeMessage('direct', $threadId, $senderAgentId, $body);
        $this->notifyForDirectMessage($message, $recipientId);
        return $message;
    }

    public function markRead(int $agentId, string $scope, ?int $channelId, ?int $threadId, int $lastReadMessageId): void
    {
        $scope = $scope === 'direct' ? 'direct' : 'channel';
        if ($scope === 'channel') {
            if (!$channelId) {
                throw new HttpException('channel_id is required', 422);
            }
            $this->ensureChannelMember($channelId, $agentId);
            $targetId = $channelId;
            $column = 'channel_id';
        } else {
            if (!$threadId) {
                throw new HttpException('thread_id is required', 422);
            }
            $this->ensureDirectParticipant($threadId, $agentId);
            $targetId = $threadId;
            $column = 'thread_id';
        }

        $lastReadMessageId = max(0, $lastReadMessageId);
        $this->db->execute(
            "INSERT INTO chat_message_reads (agent_id, message_scope, {$column}, last_read_message_id, last_read_at)
             VALUES (?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE last_read_message_id = GREATEST(last_read_message_id, VALUES(last_read_message_id)), last_read_at = NOW()",
            [$agentId, $scope, $targetId, $lastReadMessageId]
        );

        $this->deleteReadChatNotifications($agentId, $scope, $targetId);
    }

    public function typingRecipients(int $agentId, string $scope, ?int $channelId, ?int $threadId): array
    {
        if ($scope === 'direct') {
            if (!$threadId) {
                throw new HttpException('thread_id is required', 422);
            }
            $thread = $this->ensureDirectParticipant($threadId, $agentId);
            return [(int)$thread['agent_one_id'], (int)$thread['agent_two_id']];
        }

        if (!$channelId) {
            throw new HttpException('channel_id is required', 422);
        }
        $this->ensureChannelMember($channelId, $agentId);
        return array_map('intval', array_column($this->db->fetchAll(
            "SELECT m.agent_id
               FROM chat_channel_members m
               JOIN agents a ON a.id = m.agent_id AND a.is_active = 1
              WHERE m.channel_id = ?",
            [$channelId]
        ), 'agent_id'));
    }

    public function eventsAfter(int $agentId, int $afterId, int $limit): array
    {
        $this->ensureEnabled();
        $limit = max(1, min(250, $limit));
        $rows = $this->db->fetchAll(
            "SELECT m.*, a.name AS sender_name, a.chat_handle AS sender_chat_handle
               FROM chat_messages m
               JOIN agents a ON a.id = m.sender_agent_id
          LEFT JOIN chat_channel_members cm
                 ON cm.channel_id = m.channel_id
                AND cm.agent_id = ?
                AND m.message_scope = 'channel'
          LEFT JOIN chat_threads t
                 ON t.id = m.thread_id
                AND m.message_scope = 'direct'
              WHERE m.id > ?
                AND m.deleted_at IS NULL
                AND (
                    cm.agent_id IS NOT NULL
                    OR t.agent_one_id = ?
                    OR t.agent_two_id = ?
                )
           ORDER BY m.id ASC
              LIMIT {$limit}",
            [$agentId, $afterId, $agentId, $agentId]
        );

        return array_map(static function (array $message): array {
            return [
                'id' => (int)$message['id'],
                'type' => $message['message_scope'] === 'direct'
                    ? 'chat.direct.message.created'
                    : 'chat.channel.message.created',
                'message' => $message,
            ];
        }, $rows);
    }

    public function activeAgentsForChat(int $currentAgentId): array
    {
        $rows = $this->db->fetchAll(
            "SELECT id, name, email, role, chat_handle
               FROM agents
              WHERE is_active = 1
           ORDER BY name ASC"
        );

        return array_map(function (array $row) use ($currentAgentId): array {
            $row['is_self'] = (int)$row['id'] === $currentAgentId;
            return $row;
        }, $rows);
    }

    public function channelNotificationPreferences(int $agentId): array
    {
        return $this->db->fetchAll(
            "SELECT c.id, c.name, c.slug, COALESCE(p.notify_enabled, 0) AS notify_enabled
               FROM chat_channels c
               JOIN chat_channel_members m ON m.channel_id = c.id AND m.agent_id = ?
          LEFT JOIN chat_channel_notification_preferences p
                 ON p.channel_id = c.id AND p.agent_id = ?
              WHERE c.is_active = 1
           ORDER BY c.name ASC",
            [$agentId, $agentId]
        );
    }

    public function saveChannelNotificationPreferences(int $agentId, array $preferences): void
    {
        $allowed = array_map(
            'intval',
            array_column($this->channelNotificationPreferences($agentId), 'id')
        );
        $allowed = array_flip($allowed);

        foreach ($preferences as $channelId => $enabled) {
            $channelId = (int)$channelId;
            if ($channelId <= 0 || !isset($allowed[$channelId])) {
                continue;
            }
            $this->db->execute(
                "INSERT INTO chat_channel_notification_preferences (agent_id, channel_id, notify_enabled)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE notify_enabled = VALUES(notify_enabled), updated_at = NOW()",
                [$agentId, $channelId, $enabled ? 1 : 0]
            );
        }
    }

    public function adminDirectThreads(): array
    {
        return $this->db->fetchAll(
            "SELECT t.id, t.last_message_at, t.created_at,
                    a1.id AS agent_one_id, a1.name AS agent_one_name, a1.email AS agent_one_email,
                    a2.id AS agent_two_id, a2.name AS agent_two_name, a2.email AS agent_two_email,
                    (
                        SELECT COUNT(*) FROM chat_messages m
                         WHERE m.thread_id = t.id AND m.message_scope = 'direct' AND m.deleted_at IS NULL
                    ) AS message_count
               FROM chat_threads t
               JOIN agents a1 ON a1.id = t.agent_one_id
               JOIN agents a2 ON a2.id = t.agent_two_id
           ORDER BY COALESCE(t.last_message_at, t.created_at) DESC"
        );
    }

    public function adminDirectMessages(int $threadId, int $adminAgentId, string $ip, int $limit, ?int $afterId): array
    {
        $this->audit($adminAgentId, 'chat_direct_history_viewed', 'chat_thread', $threadId, [
            'ip' => $ip,
            'after_id' => $afterId,
            'limit' => $limit,
        ]);

        return $this->fetchMessages('direct', $threadId, $limit, $afterId);
    }

    public function prunePreview(?string $scope = null, ?int $channelId = null): array
    {
        return $this->pruneCounts($scope, $channelId);
    }

    public function prune(?string $scope, ?int $channelId, int $adminAgentId, string $ip): array
    {
        $counts = $this->pruneCounts($scope, $channelId);
        $deleted = ['channel' => 0, 'direct' => 0];

        foreach ($counts['channels'] as $row) {
            if ($scope === 'direct') {
                continue;
            }
            if ($channelId !== null && (int)$row['channel_id'] !== $channelId) {
                continue;
            }
            $this->db->execute(
                "UPDATE chat_messages
                    SET deleted_at = NOW(), deleted_by_agent_id = ?
                  WHERE message_scope = 'channel'
                    AND channel_id = ?
                    AND deleted_at IS NULL
                    AND created_at < DATE_SUB(NOW(), INTERVAL ? DAY)",
                [$adminAgentId, (int)$row['channel_id'], (int)$row['retention_days']]
            );
            $deleted['channel'] += (int)$row['message_count'];
        }

        if ($scope === null || $scope === 'direct') {
            $days = (int)$this->config()['direct_retention_days'];
            $this->db->execute(
                "UPDATE chat_messages
                    SET deleted_at = NOW(), deleted_by_agent_id = ?
                  WHERE message_scope = 'direct'
                    AND deleted_at IS NULL
                    AND created_at < DATE_SUB(NOW(), INTERVAL ? DAY)",
                [$adminAgentId, $days]
            );
            $deleted['direct'] = (int)$counts['direct']['message_count'];
        }

        $this->audit($adminAgentId, 'chat_prune_run', 'chat_messages', null, [
            'ip' => $ip,
            'scope' => $scope,
            'channel_id' => $channelId,
            'deleted' => $deleted,
        ]);

        return ['preview' => $counts, 'deleted' => $deleted];
    }

    public function websocketStatus(): array
    {
        $config = $this->config();
        $lastSeenAt = (string)$this->settings->get('chat_websocket_last_seen_at', '');
        $pid = (string)$this->settings->get('chat_websocket_pid', '');
        $heartbeatAge = $this->heartbeatAgeSeconds($lastSeenAt);
        $status = (string)$this->settings->get('chat_websocket_status', 'stopped');
        $effectiveStatus = $status;
        if ($status === 'running' && ($heartbeatAge === null || $heartbeatAge > 90)) {
            $effectiveStatus = 'stale';
        }
        if ($pid === '') {
            $effectiveStatus = $effectiveStatus === 'running' ? 'stale' : $effectiveStatus;
        }

        $host = (string)$config['websocket_host'];
        $port = (int)$config['websocket_port'];

        return [
            'enabled' => $config['websocket_enabled'],
            'autostart' => $config['websocket_autostart'],
            'management_mode' => $config['websocket_management_mode'],
            'status' => $effectiveStatus,
            'recorded_status' => $status,
            'pid' => $pid,
            'pid_alive' => $this->pidAlive((int)$pid),
            'process_hint' => $this->processHint((int)$pid),
            'last_seen_at' => $lastSeenAt,
            'heartbeat_age_seconds' => $heartbeatAge,
            'last_started_at' => (string)$this->settings->get('chat_websocket_last_started_at', ''),
            'host' => $host,
            'port' => $port,
            'restart_requested' => (bool)$this->settings->get('chat_websocket_restart_requested', false),
            'stop_requested' => (bool)$this->settings->get('chat_websocket_stop_requested', false),
            'diagnostics' => [
                'local_socket' => $this->localSocketDiagnostic($host, $port),
                'web_server' => $this->webServerDiagnostic($host, $port),
            ],
        ];
    }

    public function updateWebsocketSettings(array $settings): array
    {
        $repo = $this->settings->getRepository();
        $allowed = [
            'chat_websocket_enabled',
            'chat_websocket_autostart',
            'chat_websocket_management_mode',
            'chat_websocket_host',
            'chat_websocket_port',
        ];

        foreach ($allowed as $key) {
            if (!array_key_exists($key, $settings)) {
                continue;
            }
            $value = $settings[$key];
            if ($key === 'chat_websocket_management_mode') {
                $value = in_array((string)$value, ['cron', 'external'], true) ? (string)$value : 'cron';
            } elseif ($key === 'chat_websocket_host') {
                $value = trim((string)$value);
                if ($value === '') {
                    $value = '127.0.0.1';
                }
                if (!$this->isValidWebsocketHost($value)) {
                    throw new HttpException('WebSocket listen host must be a hostname or IP address, not a URL or path', 422);
                }
            } elseif ($key === 'chat_websocket_port') {
                $port = filter_var($value, FILTER_VALIDATE_INT);
                if ($port === false || $port < 1 || $port > 65535) {
                    throw new HttpException('WebSocket listen port must be between 1 and 65535', 422);
                }
                $value = (string)$port;
            } else {
                $value = empty($value) ? '0' : '1';
            }
            $repo->set($key, $value);
        }

        return $this->websocketStatus();
    }

    public function requestWebsocketAction(string $action, int $adminAgentId = 0, string $ip = ''): array
    {
        $mode = (string)$this->settings->get('chat_websocket_management_mode', 'cron');
        if ($mode === 'external') {
            $this->audit($adminAgentId, 'chat_websocket_' . $action . '_requested_external', 'chat_websocket', 0, [
                'ip' => $ip,
                'mode' => $mode,
            ]);
            return [
                'status' => $this->websocketStatus(),
                'message' => 'WebSocket service is externally managed. Use your system service manager.',
            ];
        }

        $repo = $this->settings->getRepository();
        if ($action === 'start') {
            $repo->set('chat_websocket_enabled', '1');
            $repo->set('chat_websocket_autostart', '1');
            $repo->set('chat_websocket_stop_requested', '0');
        } elseif ($action === 'stop') {
            $repo->set('chat_websocket_stop_requested', '1');
            $repo->set('chat_websocket_autostart', '0');
        } elseif ($action === 'restart') {
            $repo->set('chat_websocket_restart_requested', '1');
        }

        $this->audit($adminAgentId, 'chat_websocket_' . $action . '_requested', 'chat_websocket', 0, [
            'ip' => $ip,
            'mode' => $mode,
        ]);

        return [
            'status' => $this->websocketStatus(),
            'message' => 'WebSocket service request saved. The chat supervisor will process it from cron.',
        ];
    }

    private function heartbeatAgeSeconds(string $lastSeenAt): ?int
    {
        if ($lastSeenAt === '') {
            return null;
        }
        $timestamp = strtotime($lastSeenAt);
        if ($timestamp === false) {
            return null;
        }
        return max(0, time() - $timestamp);
    }

    private function pidAlive(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }
        if (function_exists('posix_kill')) {
            return @posix_kill($pid, 0);
        }
        return is_dir('/proc/' . $pid);
    }

    private function processHint(int $pid): array
    {
        if ($pid <= 0 || !is_dir('/proc/' . $pid)) {
            return [
                'available' => false,
                'manager_guess' => 'unknown',
                'details' => 'Process details are unavailable from PHP.',
            ];
        }

        $comm = $this->readSmallFile('/proc/' . $pid . '/comm');
        $cgroup = $this->readSmallFile('/proc/' . $pid . '/cgroup');
        $ppid = null;
        $parent = 'unknown';
        $stat = $this->readSmallFile('/proc/' . $pid . '/stat');
        if ($stat !== null && preg_match('/^\d+\s+\(.+\)\s+\S+\s+(\d+)/', $stat, $matches)) {
            $ppid = (int)$matches[1];
            $parent = trim((string)$this->readSmallFile('/proc/' . $ppid . '/comm')) ?: 'unknown';
        }

        $guess = 'unknown';
        $haystack = strtolower(($comm ?? '') . ' ' . $parent . ' ' . ($cgroup ?? ''));
        if (str_contains($haystack, 'system.slice') || str_contains($haystack, 'systemd')) {
            $guess = 'systemd';
        } elseif (str_contains($haystack, 'cron') || str_contains($haystack, 'crond')) {
            $guess = 'cron';
        }

        return [
            'available' => true,
            'manager_guess' => $guess,
            'process_name' => trim((string)$comm),
            'parent_pid' => $ppid,
            'parent_process_name' => $parent,
            'details' => $guess === 'unknown'
                ? 'PHP can read the process, but the service manager could not be inferred reliably.'
                : 'Inferred from /proc process metadata.',
        ];
    }

    private function localSocketDiagnostic(string $host, int $port): array
    {
        $started = microtime(true);
        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client(
            'tcp://' . $host . ':' . $port,
            $errno,
            $errstr,
            2.0,
            STREAM_CLIENT_CONNECT
        );
        $latencyMs = (int)round((microtime(true) - $started) * 1000);
        if (is_resource($socket)) {
            fclose($socket);
            return [
                'status' => 'verified',
                'reachable' => true,
                'host' => $host,
                'port' => $port,
                'latency_ms' => $latencyMs,
                'message' => 'PHP can connect to the local chat daemon socket.',
            ];
        }

        return [
            'status' => 'not_verified',
            'reachable' => false,
            'host' => $host,
            'port' => $port,
            'latency_ms' => $latencyMs,
            'message' => trim($errstr) !== '' ? trim($errstr) : 'PHP could not connect to the local chat daemon socket.',
            'error_code' => $errno,
        ];
    }

    private function webServerDiagnostic(string $host, int $port): array
    {
        return [
            'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? '',
            'apache' => [
                'modules' => $this->apacheModuleDiagnostic(),
                'config' => $this->configDiagnostic('apache', $host, $port),
            ],
            'nginx' => [
                'config' => $this->configDiagnostic('nginx', $host, $port),
            ],
        ];
    }

    private function apacheModuleDiagnostic(): array
    {
        $modules = null;
        $source = '';
        if (function_exists('apache_get_modules')) {
            $modules = array_map('strtolower', apache_get_modules());
            $source = 'apache_get_modules';
        } elseif ($this->functionAvailable('shell_exec')) {
            $output = @shell_exec('apache2ctl -M 2>/dev/null || apachectl -M 2>/dev/null || httpd -M 2>/dev/null');
            if (is_string($output) && trim($output) !== '') {
                $modules = array_map('strtolower', preg_split('/\s+/', $output) ?: []);
                $source = 'apachectl -M';
            }
        }

        if ($modules === null) {
            return [
                'status' => 'unable',
                'source' => '',
                'proxy_loaded' => null,
                'proxy_wstunnel_loaded' => null,
                'message' => 'PHP could not inspect Apache modules on this host.',
            ];
        }

        $proxy = $this->moduleListed($modules, 'proxy');
        $wstunnel = $this->moduleListed($modules, 'proxy_wstunnel');
        return [
            'status' => $proxy && $wstunnel ? 'verified' : 'not_verified',
            'source' => $source,
            'proxy_loaded' => $proxy,
            'proxy_wstunnel_loaded' => $wstunnel,
            'message' => $proxy && $wstunnel
                ? 'Apache proxy and proxy_wstunnel modules are loaded.'
                : 'Apache proxy and/or proxy_wstunnel modules were not detected.',
        ];
    }

    private function configDiagnostic(string $server, string $host, int $port): array
    {
        $paths = $server === 'apache'
            ? ['/etc/apache2/sites-enabled', '/etc/apache2/conf-enabled', '/etc/httpd/conf.d', '/etc/httpd/conf']
            : ['/etc/nginx/sites-enabled', '/etc/nginx/conf.d', '/etc/nginx/nginx.conf'];

        $scan = $this->scanConfigFiles($paths);
        if (!$scan['readable']) {
            return [
                'status' => 'unable',
                'checked_paths' => $paths,
                'matched_files' => [],
                'message' => 'PHP could not read common ' . $server . ' configuration paths.',
            ];
        }

        $matched = [];
        foreach ($scan['files'] as $file => $content) {
            $normalised = strtolower($content);
            $hasPath = str_contains($normalised, '/ws/chat');
            $hasTarget = str_contains($normalised, $host . ':' . $port)
                || str_contains($normalised, 'localhost:' . $port)
                || str_contains($normalised, '127.0.0.1:' . $port);
            if ($server === 'apache') {
                $hasProxy = str_contains($normalised, 'proxypass') && str_contains($normalised, 'proxypassreverse');
                if ($hasPath && $hasTarget && $hasProxy) {
                    $matched[] = $file;
                }
            } else {
                $hasProxy = str_contains($normalised, 'proxy_pass');
                $hasUpgrade = str_contains($normalised, 'upgrade') && str_contains($normalised, 'connection');
                if ($hasPath && $hasTarget && $hasProxy && $hasUpgrade) {
                    $matched[] = $file;
                }
            }
        }

        return [
            'status' => $matched ? 'verified' : 'not_verified',
            'checked_paths' => $paths,
            'matched_files' => $matched,
            'message' => $matched
                ? ucfirst($server) . ' config appears to include a /ws/chat reverse proxy.'
                : ucfirst($server) . ' /ws/chat reverse proxy config was not found in readable common paths.',
        ];
    }

    private function scanConfigFiles(array $paths): array
    {
        $files = [];
        $readable = false;
        $maxFiles = 200;
        foreach ($paths as $path) {
            if (count($files) >= $maxFiles) {
                break;
            }
            if (is_file($path) && is_readable($path)) {
                $content = $this->readConfigFile($path);
                if ($content !== null) {
                    $readable = true;
                    $files[$path] = $content;
                }
                continue;
            }
            if (!is_dir($path) || !is_readable($path)) {
                continue;
            }
            $readable = true;
            try {
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
                );
                foreach ($iterator as $file) {
                    if (count($files) >= $maxFiles) {
                        break 2;
                    }
                    if (!$file->isFile() || !$file->isReadable()) {
                        continue;
                    }
                    $filename = $file->getPathname();
                    if (!preg_match('/(\.conf|sites-enabled\/[^\/]+)$/', $filename)) {
                        continue;
                    }
                    $content = $this->readConfigFile($filename);
                    if ($content !== null) {
                        $files[$filename] = $content;
                    }
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return ['readable' => $readable, 'files' => $files];
    }

    private function readConfigFile(string $path): ?string
    {
        if (!is_readable($path) || filesize($path) > 262144) {
            return null;
        }
        $content = @file_get_contents($path);
        return is_string($content) ? $content : null;
    }

    private function readSmallFile(string $path): ?string
    {
        if (!is_readable($path)) {
            return null;
        }
        $content = @file_get_contents($path, false, null, 0, 4096);
        return is_string($content) ? $content : null;
    }

    private function moduleListed(array $modules, string $name): bool
    {
        foreach ($modules as $module) {
            if (str_contains($module, $name)) {
                return true;
            }
        }
        return false;
    }

    private function functionAvailable(string $name): bool
    {
        if (!function_exists($name)) {
            return false;
        }
        $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
        return !in_array($name, $disabled, true);
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

    private function storeMessage(string $scope, int $targetId, int $senderAgentId, string $body): array
    {
        $body = EmoticonNormalizer::text(trim($body));
        $maxLength = max(1, (int)$this->config()['max_message_length']);
        if ($body === '') {
            throw new HttpException('Message cannot be empty', 422);
        }
        if (mb_strlen($body) > $maxLength) {
            throw new HttpException("Message cannot exceed {$maxLength} characters", 422);
        }

        $rendered = $this->renderer->render($body, (bool)$this->config()['allow_external_links']);
        $channelId = $scope === 'channel' ? $targetId : null;
        $threadId = $scope === 'direct' ? $targetId : null;

        $id = $this->db->insert(
            "INSERT INTO chat_messages (message_scope, channel_id, thread_id, sender_agent_id, body_text, body_rendered_html)
             VALUES (?, ?, ?, ?, ?, ?)",
            [$scope, $channelId, $threadId, $senderAgentId, $body, $rendered]
        );

        if ($scope === 'direct') {
            $this->db->execute("UPDATE chat_threads SET last_message_at = NOW() WHERE id = ?", [$targetId]);
        }

        $mentions = $this->resolveMentions($body);
        foreach ($mentions as $agentId) {
            $this->db->execute(
                "INSERT IGNORE INTO chat_message_mentions (message_id, mentioned_agent_id) VALUES (?, ?)",
                [$id, $agentId]
            );
        }

        return $this->messageById($id);
    }

    private function notifyForChannelMessage(array $message, int $channelId, int $senderAgentId): void
    {
        $notifications = new NotificationService();
        $memberIds = array_map('intval', array_column($this->db->fetchAll(
            "SELECT m.agent_id
               FROM chat_channel_members m
               JOIN agents a ON a.id = m.agent_id AND a.is_active = 1
              WHERE m.channel_id = ?",
            [$channelId]
        ), 'agent_id'));
        $memberLookup = array_flip($memberIds);
        $mentioned = array_values(array_filter(
            array_map('intval', array_column($this->mentionsForMessage((int)$message['id']), 'mentioned_agent_id')),
            fn(int $id): bool => $id !== $senderAgentId && isset($memberLookup[$id])
        ));

        if ($mentioned) {
            $notifications->onChatMention($mentioned, $message);
        }

        $channelRecipients = $this->db->fetchAll(
            "SELECT m.agent_id
               FROM chat_channel_members m
               JOIN chat_channel_notification_preferences p
                 ON p.agent_id = m.agent_id
                AND p.channel_id = m.channel_id
                AND p.notify_enabled = 1
               JOIN agents a ON a.id = m.agent_id AND a.is_active = 1
              WHERE m.channel_id = ?
                AND m.agent_id <> ?",
            [$channelId, $senderAgentId]
        );
        $recipientIds = array_values(array_diff(
            array_map('intval', array_column($channelRecipients, 'agent_id')),
            $mentioned
        ));

        if ($recipientIds) {
            $notifications->onChatChannelMessage($recipientIds, $message);
        }
    }

    private function notifyForDirectMessage(array $message, int $recipientId): void
    {
        $notifications = new NotificationService();
        $notifications->onChatDirectMessage($recipientId, $message);
    }

    private function resolveMentions(string $body): array
    {
        preg_match_all('~(?<![\w])@([a-z0-9][a-z0-9_-]{1,79})\b~i', $body, $matches);
        $handles = array_values(array_unique(array_map(
            static fn(string $handle): string => strtolower($handle),
            $matches[1] ?? []
        )));
        if (!$handles) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($handles), '?'));
        $rows = $this->db->fetchAll(
            "SELECT id FROM agents WHERE chat_handle IN ({$placeholders}) AND is_active = 1",
            $handles
        );
        return array_map('intval', array_column($rows, 'id'));
    }

    private function mentionsForMessage(int $messageId): array
    {
        return $this->db->fetchAll(
            "SELECT mentioned_agent_id FROM chat_message_mentions WHERE message_id = ?",
            [$messageId]
        );
    }

    private function fetchMessages(string $scope, int $targetId, int $limit, ?int $afterId): array
    {
        $limit = max(1, min(250, $limit));
        $column = $scope === 'channel' ? 'channel_id' : 'thread_id';
        $params = [$scope, $targetId];
        $where = "m.message_scope = ? AND m.{$column} = ? AND m.deleted_at IS NULL";
        $order = 'm.id DESC';

        if ($afterId !== null && $afterId > 0) {
            $where .= ' AND m.id > ?';
            $params[] = $afterId;
            $order = 'm.id ASC';
        }

        return $this->db->fetchAll(
            "SELECT m.*, a.name AS sender_name, a.chat_handle AS sender_chat_handle
               FROM chat_messages m
               JOIN agents a ON a.id = m.sender_agent_id
              WHERE {$where}
           ORDER BY {$order}
              LIMIT {$limit}",
            $params
        );
    }

    private function messageById(int $id): array
    {
        return $this->db->fetch(
            "SELECT m.*, a.name AS sender_name, a.chat_handle AS sender_chat_handle
               FROM chat_messages m
               JOIN agents a ON a.id = m.sender_agent_id
              WHERE m.id = ?",
            [$id]
        ) ?: [];
    }

    private function adminChannel(int $id): array
    {
        $channel = $this->db->fetch("SELECT * FROM chat_channels WHERE id = ?", [$id]);
        if (!$channel) {
            throw new HttpException('Chat channel not found', 404);
        }
        $channel['members'] = $this->channelMembers($id);
        return $channel;
    }

    private function channelMember(int $channelId, int $agentId): ?array
    {
        return $this->db->fetch(
            "SELECT m.*, c.is_active
               FROM chat_channel_members m
               JOIN chat_channels c ON c.id = m.channel_id
              WHERE m.channel_id = ? AND m.agent_id = ? AND c.is_active = 1",
            [$channelId, $agentId]
        );
    }

    private function ensureChannelMember(int $channelId, int $agentId): array
    {
        $this->ensureEnabled();
        $member = $this->channelMember($channelId, $agentId);
        if (!$member) {
            throw new HttpException('You do not have access to this chat channel', 403);
        }
        return $member;
    }

    private function directThread(int $threadId, int $agentId): array
    {
        $thread = $this->ensureDirectParticipant($threadId, $agentId);
        $otherId = (int)$thread['agent_one_id'] === $agentId ? (int)$thread['agent_two_id'] : (int)$thread['agent_one_id'];
        $other = $this->db->fetch(
            "SELECT id, name, email, chat_handle, is_active FROM agents WHERE id = ?",
            [$otherId]
        ) ?: [];
        $thread['other_agent'] = $other;
        return $thread;
    }

    private function ensureDirectParticipant(int $threadId, int $agentId): array
    {
        $this->ensureEnabled();
        $thread = $this->db->fetch(
            "SELECT * FROM chat_threads WHERE id = ? AND (agent_one_id = ? OR agent_two_id = ?)",
            [$threadId, $agentId, $agentId]
        );
        if (!$thread) {
            throw new HttpException('You do not have access to this direct message thread', 403);
        }
        return $thread;
    }

    private function setChannelMembers(int $channelId, array $members, int $adminAgentId): void
    {
        $this->db->execute("DELETE FROM chat_channel_members WHERE channel_id = ?", [$channelId]);
        foreach ($members as $member) {
            $agentId = (int)($member['agent_id'] ?? 0);
            if ($agentId <= 0) {
                continue;
            }
            $this->db->execute(
                "INSERT INTO chat_channel_members (channel_id, agent_id, can_post, added_by_agent_id)
                 VALUES (?, ?, ?, ?)",
                [$channelId, $agentId, empty($member['can_post']) ? 0 : 1, $adminAgentId]
            );
        }
    }

    private function memberPayload(mixed $members): array
    {
        if (!is_array($members)) {
            return [];
        }

        return array_values(array_map(static function (mixed $member): array {
            if (is_array($member)) {
                return [
                    'agent_id' => (int)($member['agent_id'] ?? $member['id'] ?? 0),
                    'can_post' => !array_key_exists('can_post', $member) || !empty($member['can_post']),
                ];
            }
            return ['agent_id' => (int)$member, 'can_post' => true];
        }, $members));
    }

    private function pruneCounts(?string $scope, ?int $channelId): array
    {
        $defaultDays = (int)$this->config()['default_channel_retention_days'];
        $params = [];
        $where = "m.message_scope = 'channel' AND m.deleted_at IS NULL";
        if ($channelId !== null) {
            $where .= ' AND c.id = ?';
            $params[] = $channelId;
        }

        $channels = $scope === 'direct' ? [] : $this->db->fetchAll(
            "SELECT c.id AS channel_id,
                    c.name AS channel_name,
                    COALESCE(c.retention_days, ?) AS retention_days,
                    COUNT(m.id) AS message_count
               FROM chat_channels c
          LEFT JOIN chat_messages m
                 ON m.channel_id = c.id
                AND {$where}
                AND m.created_at < DATE_SUB(NOW(), INTERVAL COALESCE(c.retention_days, ?) DAY)
           GROUP BY c.id
           ORDER BY c.name ASC",
            array_merge([$defaultDays], $params, [$defaultDays])
        );

        $directDays = (int)$this->config()['direct_retention_days'];
        $direct = $scope === 'channel' ? ['retention_days' => $directDays, 'message_count' => 0] : (
            $this->db->fetch(
                "SELECT ? AS retention_days, COUNT(*) AS message_count
                   FROM chat_messages
                  WHERE message_scope = 'direct'
                    AND deleted_at IS NULL
                    AND created_at < DATE_SUB(NOW(), INTERVAL ? DAY)",
                [$directDays, $directDays]
            ) ?: ['retention_days' => $directDays, 'message_count' => 0]
        );

        return ['channels' => $channels, 'direct' => $direct];
    }

    private function deleteReadChatNotifications(int $agentId, string $scope, int $targetId): void
    {
        $typeClause = $scope === 'direct'
            ? "type = 'chat_direct_message'"
            : "type IN ('chat_channel_message','chat_mention')";
        $jsonPath = $scope === 'direct' ? '$.thread_id' : '$.channel_id';

        $this->db->execute(
            "DELETE FROM agent_notifications
              WHERE agent_id = ?
                AND {$typeClause}
                AND CAST(JSON_UNQUOTE(JSON_EXTRACT(data_json, '{$jsonPath}')) AS UNSIGNED) = ?",
            [$agentId, $targetId]
        );
    }

    private function audit(int $agentId, string $action, string $entityType, ?int $entityId, array $payload = []): void
    {
        $actorType = $agentId > 0 ? 'agent' : 'system';
        $actorId = $agentId > 0 ? $agentId : null;
        $this->db->execute(
            "INSERT INTO audit_log (actor_type, actor_id, action, subject_type, subject_id, payload, ip_address)
             VALUES (?, ?, ?, ?, ?, ?, ?)",
            [
                $actorType,
                $actorId,
                $action,
                $entityType,
                $entityId ?? 0,
                json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                (string)($payload['ip'] ?? ''),
            ]
        );
    }

    private function directPair(int $left, int $right): array
    {
        return $left < $right ? [$left, $right] : [$right, $left];
    }

    private function cleanName(string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', $value) ?? '');
    }

    private function slug(string $value): string
    {
        $slug = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $value) ?? '', '-'));
        if ($slug === '') {
            throw new HttpException('Channel slug is required', 422);
        }
        return substr($slug, 0, 100);
    }

    private function nullableString(mixed $value, int $max): ?string
    {
        $string = trim((string)($value ?? ''));
        return $string === '' ? null : mb_substr($string, 0, $max);
    }

    private function optionalPositiveInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        return max(1, (int)$value);
    }
}

#!/usr/bin/env php
<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$projectRoot = dirname(__DIR__);
require $projectRoot . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createUnsafeImmutable($projectRoot);
$dotenv->safeLoad();

use Andrea\Helpdesk\Auth\JwtService;
use Andrea\Helpdesk\Chat\ChatService;
use Andrea\Helpdesk\Core\Database;
use Andrea\Helpdesk\Settings\SettingsService;

date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'UTC');

$storagePath = rtrim(getenv('STORAGE_PATH') ?: ($projectRoot . '/storage'), '/');
$runtimeDir = $storagePath . '/runtime';
$logDir = $storagePath . '/logs';
@mkdir($runtimeDir, 0775, true);
@mkdir($logDir, 0775, true);

$pidFile = $runtimeDir . '/chat-websocket.pid';
$logFile = $logDir . '/chat-websocket.log';
$settings = SettingsService::getInstance();
$config = $settings->getChatConfig();
$host = getenv('CHAT_WEBSOCKET_HOST') ?: (string)$config['websocket_host'];
$port = (int)(getenv('CHAT_WEBSOCKET_PORT') ?: $config['websocket_port']);
$running = true;
$clients = [];
$lastHeartbeat = 0;

function chat_ws_log(string $message): void
{
    global $logFile;
    file_put_contents($logFile, '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function chat_ws_pid_alive(int $pid): bool
{
    if ($pid <= 0) return false;
    if (function_exists('posix_kill')) return @posix_kill($pid, 0);
    return is_dir('/proc/' . $pid);
}

function chat_ws_setting(string $key, mixed $value): void
{
    try {
        SettingsService::getInstance()->getRepository()->set($key, $value);
    } catch (Throwable $e) {
        chat_ws_log('setting update failed: ' . $e->getMessage());
    }
}

function chat_ws_heartbeat(): void
{
    chat_ws_setting('chat_websocket_status', 'running');
    chat_ws_setting('chat_websocket_pid', (string)getmypid());
    chat_ws_setting('chat_websocket_last_seen_at', date('Y-m-d H:i:s'));
}

function chat_ws_bool_setting_uncached(string $key): bool
{
    try {
        $row = Database::getInstance()->fetch("SELECT value FROM settings WHERE key_name = ?", [$key]);
        return !empty($row) && (int)$row['value'] === 1;
    } catch (Throwable) {
        return false;
    }
}

function chat_ws_frame(string $payload): string
{
    $length = strlen($payload);
    if ($length <= 125) {
        return chr(0x81) . chr($length) . $payload;
    }
    if ($length <= 65535) {
        return chr(0x81) . chr(126) . pack('n', $length) . $payload;
    }
    return chr(0x81) . chr(127) . pack('J', $length) . $payload;
}

function chat_ws_send($socket, array $payload): void
{
    @fwrite($socket, chat_ws_frame(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
}

function chat_ws_decode(string $data): ?array
{
    if (strlen($data) < 2) return null;
    $second = ord($data[1]);
    $masked = ($second & 0x80) === 0x80;
    $length = $second & 0x7f;
    $offset = 2;

    if ($length === 126) {
        if (strlen($data) < 4) return null;
        $length = unpack('n', substr($data, 2, 2))[1];
        $offset = 4;
    } elseif ($length === 127) {
        if (strlen($data) < 10) return null;
        $parts = unpack('N2', substr($data, 2, 8));
        $length = ($parts[1] * 4294967296) + $parts[2];
        $offset = 10;
    }

    $mask = '';
    if ($masked) {
        if (strlen($data) < $offset + 4) return null;
        $mask = substr($data, $offset, 4);
        $offset += 4;
    }

    if (strlen($data) < $offset + $length) return null;
    $payload = substr($data, $offset, $length);
    if ($masked) {
        for ($i = 0; $i < $length; $i++) {
            $payload[$i] = $payload[$i] ^ $mask[$i % 4];
        }
    }

    return json_decode($payload, true) ?: null;
}

function chat_ws_handshake($socket, string $request): bool
{
    if (!preg_match('/Sec-WebSocket-Key:\s*(.+)\r\n/i', $request, $matches)) {
        return false;
    }
    if (!preg_match('#^GET\s+/ws/chat(?:\s|\?)#i', $request)) {
        return false;
    }
    if (!chat_ws_origin_allowed($request)) {
        return false;
    }

    $accept = base64_encode(sha1(trim($matches[1]) . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
    $headers = "HTTP/1.1 101 Switching Protocols\r\n"
        . "Upgrade: websocket\r\n"
        . "Connection: Upgrade\r\n"
        . "Sec-WebSocket-Accept: {$accept}\r\n\r\n";
    fwrite($socket, $headers);
    return true;
}

function chat_ws_origin_allowed(string $request): bool
{
    if (!preg_match('/^Origin:\s*(.+)$/mi', $request, $matches)) {
        return true;
    }

    $originHost = parse_url(trim($matches[1]), PHP_URL_HOST);
    $appHost = parse_url((string)(getenv('APP_URL') ?: ''), PHP_URL_HOST);
    $requestHost = null;
    if (preg_match('/^Host:\s*(.+)$/mi', $request, $hostMatches)) {
        $requestHost = strtolower(preg_replace('/:\d+$/', '', trim($hostMatches[1])) ?: '');
    }

    $allowed = array_filter(array_map('strtolower', [$appHost, $requestHost]));
    return $originHost !== null && in_array(strtolower($originHost), $allowed, true);
}

function chat_ws_socket_id($socket): int
{
    return (int)$socket;
}

function chat_ws_broadcast(array $clients, array $agentIds, array $payload, ?int $excludeSocketId = null): void
{
    $allowed = array_flip(array_map('intval', $agentIds));
    foreach ($clients as $id => $client) {
        if ($excludeSocketId !== null && $id === $excludeSocketId) continue;
        $agentId = (int)($client['agent_id'] ?? 0);
        if ($agentId > 0 && isset($allowed[$agentId])) {
            chat_ws_send($client['socket'], $payload);
        }
    }
}

function chat_ws_channel_agents(int $channelId): array
{
    $rows = Database::getInstance()->fetchAll(
        "SELECT agent_id FROM chat_channel_members WHERE channel_id = ?",
        [$channelId]
    );
    return array_map('intval', array_column($rows, 'agent_id'));
}

function chat_ws_thread_agents(int $threadId): array
{
    $thread = Database::getInstance()->fetch(
        "SELECT agent_one_id, agent_two_id FROM chat_threads WHERE id = ?",
        [$threadId]
    );
    return $thread ? [(int)$thread['agent_one_id'], (int)$thread['agent_two_id']] : [];
}

if (is_file($pidFile)) {
    $existingPid = (int)trim((string)file_get_contents($pidFile));
    $lastSeen = (string)SettingsService::getInstance()->get('chat_websocket_last_seen_at', '');
    $healthy = $existingPid > 0
        && chat_ws_pid_alive($existingPid)
        && $lastSeen !== ''
        && strtotime($lastSeen) >= time() - 90;

    if ($healthy) {
        fwrite(STDERR, "A healthy chat websocket daemon is already running with PID {$existingPid}.\n");
        exit(0);
    }
}

file_put_contents($pidFile, (string)getmypid(), LOCK_EX);
chat_ws_setting('chat_websocket_last_started_at', date('Y-m-d H:i:s'));
chat_ws_heartbeat();

if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, static function () use (&$running): void { $running = false; });
    pcntl_signal(SIGINT, static function () use (&$running): void { $running = false; });
}

$server = @stream_socket_server("tcp://{$host}:{$port}", $errno, $errstr);
if (!$server) {
    chat_ws_setting('chat_websocket_status', 'failed');
    chat_ws_log("failed to listen on {$host}:{$port}: {$errstr} ({$errno})");
    @unlink($pidFile);
    exit(1);
}

stream_set_blocking($server, false);
chat_ws_log("listening on {$host}:{$port}");
$chat = new ChatService();
$jwt = new JwtService();

try {
    while ($running) {
        if (time() - $lastHeartbeat >= 15) {
            chat_ws_heartbeat();
            $lastHeartbeat = time();
        }

        if (chat_ws_bool_setting_uncached('chat_websocket_stop_requested')) {
            chat_ws_log('stop requested');
            break;
        }

        $read = [$server];
        foreach ($clients as $client) {
            $read[] = $client['socket'];
        }

        $write = null;
        $except = null;
        @stream_select($read, $write, $except, 1);

        foreach ($read as $socket) {
            if ($socket === $server) {
                $conn = @stream_socket_accept($server, 0);
                if ($conn) {
                    stream_set_blocking($conn, false);
                    $clients[chat_ws_socket_id($conn)] = [
                        'socket' => $conn,
                        'handshake' => false,
                        'agent_id' => null,
                        'agent' => null,
                    ];
                }
                continue;
            }

            $id = chat_ws_socket_id($socket);
            $data = @fread($socket, 8192);
            if ($data === '' || $data === false) {
                if (!empty($clients[$id]['agent_id'])) {
                    $agentId = (int)$clients[$id]['agent_id'];
                    chat_ws_broadcast($clients, array_keys($clients), [
                        'type' => 'presence.updated',
                        'agent_id' => $agentId,
                        'online' => false,
                    ], $id);
                }
                @fclose($socket);
                unset($clients[$id]);
                continue;
            }

            if (empty($clients[$id]['handshake'])) {
                if (!chat_ws_handshake($socket, $data)) {
                    @fclose($socket);
                    unset($clients[$id]);
                } else {
                    $clients[$id]['handshake'] = true;
                }
                continue;
            }

            $event = chat_ws_decode($data);
            if (!$event || empty($event['type'])) {
                chat_ws_send($socket, ['type' => 'error', 'message' => 'Invalid websocket event']);
                continue;
            }

            try {
                if ($event['type'] === 'auth') {
                    $payload = $jwt->verify((string)($event['token'] ?? ''));
                    if (($payload->type ?? '') !== 'agent') {
                        throw new RuntimeException('Agent token required');
                    }
                    $agent = Database::getInstance()->fetch(
                        "SELECT id, name, email, role, chat_handle FROM agents WHERE id = ? AND is_active = 1",
                        [(int)$payload->sub]
                    );
                    if (!$agent) {
                        throw new RuntimeException('Agent account not found or inactive');
                    }

                    $clients[$id]['agent_id'] = (int)$agent['id'];
                    $clients[$id]['agent'] = $agent;
                    chat_ws_send($socket, [
                        'type' => 'auth.ok',
                        'agent_id' => (int)$agent['id'],
                        'name' => $agent['name'],
                        'role' => $agent['role'],
                    ]);
                    chat_ws_broadcast($clients, array_map(static fn(array $client): int => (int)($client['agent_id'] ?? 0), $clients), [
                        'type' => 'presence.updated',
                        'agent_id' => (int)$agent['id'],
                        'online' => true,
                    ], $id);
                    continue;
                }

                $agentId = (int)($clients[$id]['agent_id'] ?? 0);
                if ($agentId <= 0) {
                    chat_ws_send($socket, ['type' => 'auth.failed', 'message' => 'Authenticate before sending chat events']);
                    continue;
                }

                if ($event['type'] === 'chat.channel.message.send') {
                    $message = $chat->createChannelMessage((int)($event['channel_id'] ?? 0), $agentId, (string)($event['body'] ?? ''));
                    chat_ws_broadcast($clients, chat_ws_channel_agents((int)$message['channel_id']), [
                        'type' => 'chat.channel.message.created',
                        'message' => $message,
                    ]);
                } elseif ($event['type'] === 'chat.direct.message.send') {
                    $message = $chat->createDirectMessage((int)($event['thread_id'] ?? 0), $agentId, (string)($event['body'] ?? ''));
                    chat_ws_broadcast($clients, chat_ws_thread_agents((int)$message['thread_id']), [
                        'type' => 'chat.direct.message.created',
                        'message' => $message,
                    ]);
                } elseif ($event['type'] === 'chat.typing') {
                    $scope = (string)($event['scope'] ?? 'channel');
                    $channelId = isset($event['channel_id']) ? (int)$event['channel_id'] : null;
                    $threadId = isset($event['thread_id']) ? (int)$event['thread_id'] : null;
                    $recipients = $chat->typingRecipients($agentId, $scope, $channelId, $threadId);
                    chat_ws_broadcast($clients, $recipients, [
                        'type' => 'chat.typing',
                        'scope' => $scope,
                        'channel_id' => $channelId,
                        'thread_id' => $threadId,
                        'agent_id' => $agentId,
                        'agent_name' => (string)($clients[$id]['agent']['name'] ?? 'Agent'),
                    ], $id);
                } elseif ($event['type'] === 'chat.read') {
                    $scope = (string)($event['scope'] ?? 'channel');
                    $channelId = isset($event['channel_id']) ? (int)$event['channel_id'] : null;
                    $threadId = isset($event['thread_id']) ? (int)$event['thread_id'] : null;
                    $chat->markRead(
                        $agentId,
                        $scope,
                        $channelId,
                        $threadId,
                        (int)($event['last_read_message_id'] ?? 0)
                    );
                    $recipients = $scope === 'direct'
                        ? chat_ws_thread_agents((int)$threadId)
                        : chat_ws_channel_agents((int)$channelId);
                    chat_ws_broadcast($clients, $recipients, [
                        'type' => 'chat.read.updated',
                        'scope' => $scope,
                        'channel_id' => $channelId,
                        'thread_id' => $threadId,
                        'agent_id' => $agentId,
                        'last_read_message_id' => (int)($event['last_read_message_id'] ?? 0),
                    ]);
                } elseif ($event['type'] === 'ping') {
                    chat_ws_send($socket, ['type' => 'pong', 'time' => time()]);
                } else {
                    chat_ws_send($socket, ['type' => 'error', 'message' => 'Unsupported websocket event type']);
                }
            } catch (Throwable $e) {
                if (($event['type'] ?? '') === 'auth') {
                    chat_ws_send($socket, ['type' => 'auth.failed', 'message' => $e->getMessage()]);
                    @fclose($socket);
                    unset($clients[$id]);
                } else {
                    chat_ws_send($socket, ['type' => 'error', 'message' => $e->getMessage()]);
                }
            }
        }
    }
} finally {
    foreach ($clients as $client) {
        @fclose($client['socket']);
    }
    @fclose($server);
    @unlink($pidFile);
    chat_ws_setting('chat_websocket_status', 'stopped');
    chat_ws_setting('chat_websocket_pid', '');
    chat_ws_setting('chat_websocket_stop_requested', '0');
    chat_ws_log('stopped');
}

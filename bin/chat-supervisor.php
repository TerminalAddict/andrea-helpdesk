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

use Andrea\Helpdesk\Settings\SettingsService;

date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'UTC');

$storagePath = rtrim(getenv('STORAGE_PATH') ?: ($projectRoot . '/storage'), '/');
$runtimeDir = $storagePath . '/runtime';
$logDir = $storagePath . '/logs';
@mkdir($runtimeDir, 0775, true);
@mkdir($logDir, 0775, true);

$lockFile = $runtimeDir . '/chat-supervisor.lock';
$pidFile = $runtimeDir . '/chat-websocket.pid';
$daemonLog = $logDir . '/chat-websocket.log';
$lock = fopen($lockFile, 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0);
}

function chat_supervisor_log(string $message): void
{
    echo '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
}

function chat_supervisor_pid_alive(int $pid): bool
{
    if ($pid <= 0) return false;
    if (function_exists('posix_kill')) return @posix_kill($pid, 0);
    return is_dir('/proc/' . $pid);
}

function chat_supervisor_stop_pid(int $pid): void
{
    if ($pid <= 0) return;
    if (function_exists('posix_kill')) {
        @posix_kill($pid, SIGTERM);
        return;
    }
    exec('kill ' . escapeshellarg((string)$pid) . ' 2>/dev/null');
}

function chat_supervisor_wait_for_stop(int $pid, int $seconds = 5): bool
{
    $deadline = time() + max(1, $seconds);
    while (time() <= $deadline) {
        if (!chat_supervisor_pid_alive($pid)) {
            return true;
        }
        usleep(200000);
    }
    return !chat_supervisor_pid_alive($pid);
}

function chat_supervisor_read_pid(string $pidFile): int
{
    return is_file($pidFile) ? (int)trim((string)file_get_contents($pidFile)) : 0;
}

function chat_supervisor_start(string $projectRoot, string $daemonLog): void
{
    $command = 'nohup ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($projectRoot . '/bin/chat-websocket-server.php')
        . ' >> ' . escapeshellarg($daemonLog) . ' 2>&1 & echo $!';
    $output = [];
    exec($command, $output);
    chat_supervisor_log('started chat websocket daemon pid=' . trim((string)($output[0] ?? 'unknown')));
}

try {
    $settings = SettingsService::getInstance();
    $repo = $settings->getRepository();
    $config = $settings->getChatConfig();
    $mode = $config['websocket_management_mode'];
    $pid = chat_supervisor_read_pid($pidFile);
    $alive = chat_supervisor_pid_alive($pid);
    $lastSeen = (string)$settings->get('chat_websocket_last_seen_at', '');
    $heartbeatFresh = $lastSeen !== '' && strtotime($lastSeen) >= time() - 90;

    if ($mode === 'external') {
        $repo->set('chat_websocket_status', ($alive && $heartbeatFresh) ? 'running' : ($alive ? 'stale' : 'stopped'));
        chat_supervisor_log('external mode: status only');
        exit(0);
    }

    $stopRequested = (bool)$settings->get('chat_websocket_stop_requested', false);
    $restartRequested = (bool)$settings->get('chat_websocket_restart_requested', false);
    $shouldRun = $config['enabled'] && $config['websocket_enabled'] && $config['websocket_autostart'];

    if (($stopRequested || !$shouldRun) && $alive) {
        chat_supervisor_stop_pid($pid);
        $stopped = chat_supervisor_wait_for_stop($pid);
        $repo->set('chat_websocket_status', $stopped ? 'stopped' : 'stale');
        if ($stopped) {
            $repo->set('chat_websocket_stop_requested', '0');
        }
        chat_supervisor_log(($stopped ? 'stopped' : 'stop pending for') . ' chat websocket daemon pid=' . $pid);
        exit(0);
    }

    if ($restartRequested && $alive) {
        chat_supervisor_stop_pid($pid);
        if (!chat_supervisor_wait_for_stop($pid)) {
            $repo->set('chat_websocket_status', 'stale');
            chat_supervisor_log('restart delayed; existing chat websocket daemon still alive pid=' . $pid);
            exit(1);
        }
        $repo->set('chat_websocket_restart_requested', '0');
        chat_supervisor_start($projectRoot, $daemonLog);
        exit(0);
    }

    if ($shouldRun && (!$alive || !$heartbeatFresh)) {
        if ($alive && !$heartbeatFresh) {
            chat_supervisor_stop_pid($pid);
            if (!chat_supervisor_wait_for_stop($pid)) {
                $repo->set('chat_websocket_status', 'stale');
                chat_supervisor_log('stale daemon still alive after stop request pid=' . $pid);
                exit(1);
            }
            $repo->set('chat_websocket_status', 'stale');
            chat_supervisor_log('restarting stale chat websocket daemon pid=' . $pid);
        }
        chat_supervisor_start($projectRoot, $daemonLog);
        exit(0);
    }

    $repo->set('chat_websocket_status', ($alive && $heartbeatFresh) ? 'running' : 'stopped');
    chat_supervisor_log('chat websocket status checked');
} catch (Throwable $e) {
    chat_supervisor_log('ERROR: ' . $e->getMessage());
    exit(1);
} finally {
    if (is_resource($lock)) {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

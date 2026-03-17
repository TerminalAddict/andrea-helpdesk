#!/usr/bin/env php
<?php
declare(strict_types=1);

// Prevent web access
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$projectRoot = dirname(__DIR__);
require $projectRoot . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createUnsafeImmutable($projectRoot);
$dotenv->safeLoad();

use Andrea\Helpdesk\Core\Database;
use Andrea\Helpdesk\Settings\SettingsService;
use Andrea\Helpdesk\IMAP\ImapAccountRepository;
use Andrea\Helpdesk\IMAP\ImapPoller;
use Andrea\Helpdesk\IMAP\MessageParser;
use Andrea\Helpdesk\IMAP\ThreadMatcher;

// Require JWT_SECRET
$jwtSecret = getenv('JWT_SECRET');
if (empty($jwtSecret) || strlen($jwtSecret) < 32) {
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] FATAL: JWT_SECRET must be set to a random string of at least 32 characters.' . PHP_EOL);
    exit(1);
}

// Set timezone
$tz = getenv('APP_TIMEZONE') ?: 'UTC';
date_default_timezone_set($tz);

// File lock to prevent overlapping runs
$lockFile = sys_get_temp_dir() . '/andrea-helpdesk-imap.lock';
$lock     = fopen($lockFile, 'w');

if (!flock($lock, LOCK_EX | LOCK_NB)) {
    echo '[' . date('Y-m-d H:i:s') . '] Another instance is already running. Exiting.' . PHP_EOL;
    exit(0);
}

// Rotate a log file, keeping only lines from the last $keepDays days
function rotateLog(string $logFile, int $keepDays = 3): void
{
    if (!file_exists($logFile)) return;

    $cutoff = strtotime("-{$keepDays} days");
    $lines  = file($logFile, FILE_IGNORE_NEW_LINES);
    if ($lines === false) return;

    $kept = array_filter($lines, function (string $line) use ($cutoff): bool {
        // Lines start with [YYYY-MM-DD HH:MM:SS]
        if (preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]/', $line, $m)) {
            return strtotime($m[1]) >= $cutoff;
        }
        return true; // keep lines that don't match the format
    });

    file_put_contents($logFile, implode(PHP_EOL, $kept) . (count($kept) ? PHP_EOL : ''));
}

$storagePath = rtrim(getenv('STORAGE_PATH') ?: (dirname(__DIR__) . '/storage'), '/');
rotateLog($storagePath . '/logs/imap.log');
rotateLog($storagePath . '/logs/app.log');

$totalProcessed = 0;

try {
    $db          = Database::getInstance();
    $settings    = SettingsService::getInstance();
    $accountRepo = new ImapAccountRepository();
    $accounts    = $accountRepo->findEnabled();

    if (empty($accounts)) {
        echo '[' . date('Y-m-d H:i:s') . '] No enabled IMAP accounts configured.' . PHP_EOL;
        exit(0);
    }

    foreach ($accounts as $account) {
        echo '[' . date('Y-m-d H:i:s') . "] Polling account: {$account['name']} ({$account['username']})" . PHP_EOL;

        $config = [
            'host'                => $account['host'],
            'port'                => $account['port'],
            'encryption'          => $account['encryption'],
            'username'            => $account['username'],
            'password'            => $accountRepo->getDecryptedPassword((int)$account['id']),
            'folder'              => $account['folder'],
            'delete_after_import' => (bool)$account['delete_after_import'],
            'tag_id'              => $account['tag_id'] ?: null,
        ];

        $poller = new ImapPoller($config, new MessageParser(), new ThreadMatcher($db));

        if (!$poller->connect()) {
            echo '[' . date('Y-m-d H:i:s') . "] Failed to connect to {$account['name']}. Skipping." . PHP_EOL;
            continue;
        }

        $accountRepo->recordConnected((int)$account['id']);

        $count = $poller->poll();
        $poller->disconnect();
        $accountRepo->recordPoll((int)$account['id'], $count);
        $totalProcessed += $count;

        echo '[' . date('Y-m-d H:i:s') . "] Account {$account['name']}: processed {$count} message(s)." . PHP_EOL;
    }

    echo '[' . date('Y-m-d H:i:s') . "] Done. Total processed: {$totalProcessed} message(s)." . PHP_EOL;
    exit(0);

} catch (\Throwable $e) {
    echo '[' . date('Y-m-d H:i:s') . '] FATAL: ' . $e->getMessage() . PHP_EOL;
    exit(1);
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}

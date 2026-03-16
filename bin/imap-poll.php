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

$dotenv = Dotenv\Dotenv::createImmutable($projectRoot);
$dotenv->safeLoad();

use Andrea\Helpdesk\Core\Database;
use Andrea\Helpdesk\Settings\SettingsService;
use Andrea\Helpdesk\IMAP\ImapPoller;
use Andrea\Helpdesk\IMAP\MessageParser;
use Andrea\Helpdesk\IMAP\ThreadMatcher;

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

try {
    // Initialise DB
    $db       = Database::getInstance();
    $settings = SettingsService::getInstance();

    $poller = new ImapPoller(
        $settings,
        new MessageParser(),
        new ThreadMatcher($db)
    );

    if (!$poller->connect()) {
        echo '[' . date('Y-m-d H:i:s') . '] IMAP connection failed. Check settings.' . PHP_EOL;
        exit(1);
    }

    $count = $poller->poll();
    $poller->disconnect();

    echo '[' . date('Y-m-d H:i:s') . "] Done. Processed {$count} message(s)." . PHP_EOL;
    exit(0);

} catch (\Throwable $e) {
    echo '[' . date('Y-m-d H:i:s') . '] FATAL: ' . $e->getMessage() . PHP_EOL;
    exit(1);
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}

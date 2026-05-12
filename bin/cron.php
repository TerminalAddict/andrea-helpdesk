#!/usr/bin/env php
<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$projectRoot = dirname(__DIR__);
$php = PHP_BINARY ?: 'php';

$scripts = [
    'chat-supervisor.php',
    'imap-poll.php',
];

$exitCode = 0;
foreach ($scripts as $script) {
    $path = $projectRoot . '/bin/' . $script;
    if (!is_file($path)) {
        echo '[' . date('Y-m-d H:i:s') . "] Missing cron script: {$script}" . PHP_EOL;
        $exitCode = 1;
        continue;
    }

    passthru(escapeshellarg($php) . ' ' . escapeshellarg($path), $code);
    if ($code !== 0) {
        $exitCode = $code;
    }
}

$storagePath = rtrim(getenv('STORAGE_PATH') ?: ($projectRoot . '/storage'), '/');
$runtimeDir = $storagePath . '/runtime';
@mkdir($runtimeDir, 0775, true);
$lastPruneFile = $runtimeDir . '/chat-prune-last-run.txt';
$today = date('Y-m-d');
if (!is_file($lastPruneFile) || trim((string)file_get_contents($lastPruneFile)) !== $today) {
    passthru(escapeshellarg($php) . ' ' . escapeshellarg($projectRoot . '/bin/chat-prune.php') . ' --run', $code);
    if ($code === 0) {
        file_put_contents($lastPruneFile, $today, LOCK_EX);
    } else {
        $exitCode = $code;
    }
}

exit($exitCode);

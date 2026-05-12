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

use Andrea\Helpdesk\Chat\ChatService;

$options = getopt('', ['dry-run', 'run', 'scope::', 'channel-id::']);
$run = array_key_exists('run', $options);
$scope = isset($options['scope']) ? (string)$options['scope'] : null;
$channelId = isset($options['channel-id']) ? (int)$options['channel-id'] : null;

if ($scope !== null && !in_array($scope, ['channel', 'direct'], true)) {
    fwrite(STDERR, "Invalid --scope. Use channel or direct.\n");
    exit(1);
}

$chat = new ChatService();
if (!$run) {
    echo json_encode($chat->prunePreview($scope, $channelId), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

echo json_encode($chat->prune($scope, $channelId, 0, 'cli'), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

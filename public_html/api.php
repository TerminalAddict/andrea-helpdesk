<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__);
require $projectRoot . '/vendor/autoload.php';

// Load environment
$dotenv = Dotenv\Dotenv::createUnsafeImmutable($projectRoot);
$dotenv->safeLoad();

use Andrea\Helpdesk\Core\App;
use Andrea\Helpdesk\Core\Response;
use Andrea\Helpdesk\Core\Exceptions\HttpException;

// Handle CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

try {
    $app = App::getInstance();
    $app->boot();
    $app->run();
} catch (HttpException $e) {
    http_response_code($e->getStatusCode());
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'errors'  => $e->getErrors(),
    ]);
} catch (\Throwable $e) {
    $debug = (bool)(getenv('APP_DEBUG') === 'true');
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $debug ? $e->getMessage() : 'Internal server error',
        'errors'  => $debug ? ['trace' => $e->getTraceAsString()] : [],
    ]);
}

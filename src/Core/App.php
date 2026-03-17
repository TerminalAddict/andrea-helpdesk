<?php
declare(strict_types=1);

namespace Andrea\Helpdesk\Core;

class App
{
    private static ?App $instance = null;

    private function __construct() {}

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function boot(): void
    {
        // Require JWT_SECRET before anything else
        $jwtSecret = getenv('JWT_SECRET');
        if (empty($jwtSecret) || strlen($jwtSecret) < 32) {
            http_response_code(500);
            exit('Server misconfiguration: JWT_SECRET must be set to a random string of at least 32 characters.');
        }

        // Set timezone
        $timezone = getenv('APP_TIMEZONE') ?: 'UTC';
        date_default_timezone_set($timezone);

        // Initialise DB connection (validates credentials on first call)
        Database::getInstance();

        // Debug mode
        if (getenv('APP_DEBUG') === 'true') {
            ini_set('display_errors', '1');
            error_reporting(E_ALL);
        } else {
            ini_set('display_errors', '0');
        }
    }

    public function run(): void
    {
        $request = Request::fromGlobals();
        $router  = new Router();
        Router::loadRoutes($router);
        $router->dispatch($request);
    }
}

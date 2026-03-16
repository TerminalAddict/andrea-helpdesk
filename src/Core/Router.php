<?php
declare(strict_types=1);

namespace Andrea\Helpdesk\Core;

use Andrea\Helpdesk\Core\Exceptions\HttpException;
use Andrea\Helpdesk\Core\Exceptions\NotFoundException;

class Router
{
    private array $routes = [];

    public function addRoute(string $method, string $path, string $controller, string $action, array $middleware = []): void
    {
        $this->routes[] = [
            'method'     => strtoupper($method),
            'path'       => $path,
            'controller' => $controller,
            'action'     => $action,
            'middleware' => $middleware,
        ];
    }

    public function dispatch(Request $request): void
    {
        $method = $request->method;
        $path   = $request->path;

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }

            $params = $this->matchPath($route['path'], $path);
            if ($params === null) {
                continue;
            }

            $request->params = $params;

            // Run middleware stack
            foreach ($route['middleware'] as $middlewareName) {
                Middleware::run($middlewareName, $request);
            }

            // Instantiate controller and call action
            $controllerClass = $route['controller'];
            $action          = $route['action'];

            if (!class_exists($controllerClass)) {
                throw new HttpException("Controller {$controllerClass} not found", 500);
            }

            $controller = new $controllerClass();

            if (!method_exists($controller, $action)) {
                throw new HttpException("Action {$action} not found on {$controllerClass}", 500);
            }

            $controller->$action($request, $params);
            return;
        }

        throw new NotFoundException("Route not found: {$method} {$path}");
    }

    /**
     * Match a route pattern against an actual path.
     * Returns array of named params if matched, null if no match.
     * Example: pattern "/api/tickets/:id" matches "/api/tickets/42" -> ['id' => '42']
     */
    private function matchPath(string $pattern, string $path): ?array
    {
        // Strip /api prefix from pattern for matching (request path already includes /api)
        $params  = [];
        $regexParts = [];

        foreach (explode('/', $pattern) as $segment) {
            if ($segment === '') continue;
            if (str_starts_with($segment, ':')) {
                $paramName    = substr($segment, 1);
                $regexParts[] = '(?P<' . $paramName . '>[^/]+)';
            } else {
                $regexParts[] = preg_quote($segment, '#');
            }
        }

        $regex = '#^/' . implode('/', $regexParts) . '/?$#';

        if (preg_match($regex, $path, $matches)) {
            foreach ($matches as $key => $value) {
                if (is_string($key)) {
                    $params[$key] = $value;
                }
            }
            return $params;
        }

        return null;
    }

    public static function loadRoutes(self $router): void
    {
        $routes = require dirname(__DIR__, 2) . '/config/routes.php';
        foreach ($routes as [$method, $path, $controller, $action, $middleware]) {
            $router->addRoute($method, $path, $controller, $action, $middleware);
        }
    }
}

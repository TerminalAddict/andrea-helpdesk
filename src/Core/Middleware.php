<?php
declare(strict_types=1);

namespace Andrea\Helpdesk\Core;

use Andrea\Helpdesk\Auth\JwtService;
use Andrea\Helpdesk\Core\Exceptions\AuthException;
use Andrea\Helpdesk\Core\Exceptions\HttpException;

class Middleware
{
    /**
     * Run named middleware against the request.
     * Throws AuthException or HttpException on failure.
     */
    public static function run(string $name, Request $request): void
    {
        [$type, $param] = explode(':', $name . ':') + ['', ''];

        match ($type) {
            'auth'       => self::handleAuth($param, $request),
            'role'       => self::handleRole($param, $request),
            'permission' => self::handlePermission($param, $request),
            default      => null,
        };
    }

    private static function handleAuth(string $type, Request $request): void
    {
        $token = $request->bearerToken();

        if (!$token) {
            throw new AuthException('No authentication token provided');
        }

        try {
            $jwtService = new JwtService();
            $payload    = $jwtService->verify($token);
        } catch (\Throwable $e) {
            throw new AuthException('Invalid or expired token');
        }

        if ($type === 'agent' && $payload->type !== 'agent') {
            throw new AuthException('Agent authentication required');
        }

        if ($type === 'customer' && $payload->type !== 'customer') {
            throw new AuthException('Customer authentication required');
        }

        $db = Database::getInstance();

        if ($payload->type === 'agent') {
            $agent = $db->fetch("SELECT * FROM agents WHERE id = ? AND is_active = 1", [$payload->sub]);
            if (!$agent) {
                throw new AuthException('Agent account not found or inactive');
            }
            unset($agent['password_hash']);
            $request->agent = (object)$agent;
        } elseif ($payload->type === 'customer') {
            $customer = $db->fetch("SELECT * FROM customers WHERE id = ? AND deleted_at IS NULL", [$payload->sub]);
            if (!$customer) {
                throw new AuthException('Customer account not found');
            }
            unset($customer['portal_password_hash']);
            $request->customer = (object)$customer;
        }
    }

    private static function handleRole(string $role, Request $request): void
    {
        // Ensure agent is already loaded
        if (!$request->agent) {
            self::handleAuth('agent', $request);
        }

        if ($role === 'admin' && $request->agent->role !== 'admin') {
            throw new HttpException('Admin access required', 403);
        }
    }

    private static function handlePermission(string $permission, Request $request): void
    {
        // Ensure agent is already loaded
        if (!$request->agent) {
            self::handleAuth('agent', $request);
        }

        // Admins bypass all permission checks
        if ($request->agent->role === 'admin') {
            return;
        }

        if (empty($request->agent->$permission)) {
            throw new HttpException('You do not have permission: ' . $permission, 403);
        }
    }
}

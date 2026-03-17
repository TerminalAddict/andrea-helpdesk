<?php
declare(strict_types=1);

namespace Andrea\Helpdesk\Auth;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Andrea\Helpdesk\Core\Database;
use Andrea\Helpdesk\Core\Exceptions\AuthException;

class JwtService
{
    private string $secret;
    private int $accessTtl;
    private int $refreshTtl;

    public function __construct()
    {
        $this->secret     = getenv('JWT_SECRET') ?: 'changeme-insecure-default';
        $this->accessTtl  = (int)(getenv('JWT_ACCESS_TTL') ?: 900);
        $this->refreshTtl = (int)(getenv('JWT_REFRESH_TTL') ?: 2592000);
    }

    /**
     * Issue a short-lived access token for an agent or customer.
     */
    public function issueAccessToken(array $payload): string
    {
        $now = time();
        $claims = array_merge($payload, [
            'iat' => $now,
            'exp' => $now + $this->accessTtl,
        ]);
        return JWT::encode($claims, $this->secret, 'HS256');
    }

    /**
     * Issue a refresh token, store hash in DB, return raw token.
     */
    public function issueRefreshToken(int $userId, string $type): string
    {
        $rawToken  = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);
        $expiresAt = date('Y-m-d H:i:s', time() + $this->refreshTtl);

        $db = Database::getInstance();

        if ($type === 'agent') {
            $db->execute(
                "INSERT INTO refresh_tokens (token_hash, agent_id, expires_at) VALUES (?, ?, ?)",
                [$tokenHash, $userId, $expiresAt]
            );
        } else {
            $db->execute(
                "INSERT INTO refresh_tokens (token_hash, customer_id, expires_at) VALUES (?, ?, ?)",
                [$tokenHash, $userId, $expiresAt]
            );
        }

        return $rawToken;
    }

    /**
     * Verify and decode an access token.
     */
    public function verify(string $token): object
    {
        try {
            return JWT::decode($token, new Key($this->secret, 'HS256'));
        } catch (\Throwable $e) {
            throw new AuthException('Invalid or expired token');
        }
    }

    /**
     * Rotate refresh token: revoke old, issue new access + refresh tokens.
     */
    public function refreshAccessToken(string $rawRefreshToken): array
    {
        $tokenHash = hash('sha256', $rawRefreshToken);
        $db        = Database::getInstance();

        $storedToken = $db->fetch(
            "SELECT * FROM refresh_tokens WHERE token_hash = ? AND revoked = 0 AND expires_at > NOW()",
            [$tokenHash]
        );

        if (!$storedToken) {
            throw new AuthException('Invalid or expired refresh token');
        }

        // Revoke old token
        $db->execute("UPDATE refresh_tokens SET revoked = 1 WHERE id = ?", [$storedToken['id']]);

        if ($storedToken['agent_id']) {
            $agent = $db->fetch("SELECT * FROM agents WHERE id = ? AND is_active = 1", [$storedToken['agent_id']]);
            if (!$agent) throw new AuthException('Agent not found or inactive');

            $accessToken  = $this->issueAccessToken($this->buildAgentPayload($agent));
            $refreshToken = $this->issueRefreshToken($agent['id'], 'agent');

            return [
                'access_token'  => $accessToken,
                'refresh_token' => $refreshToken,
                'user'          => $this->sanitiseAgent($agent),
            ];
        } else {
            $customer = $db->fetch("SELECT * FROM customers WHERE id = ? AND deleted_at IS NULL", [$storedToken['customer_id']]);
            if (!$customer) throw new AuthException('Customer not found');

            $accessToken  = $this->issueAccessToken($this->buildCustomerPayload($customer));
            $refreshToken = $this->issueRefreshToken($customer['id'], 'customer');

            return [
                'access_token'  => $accessToken,
                'refresh_token' => $refreshToken,
                'user'          => $this->sanitiseCustomer($customer),
            ];
        }
    }

    public function revokeRefreshToken(string $rawToken): void
    {
        $tokenHash = hash('sha256', $rawToken);
        Database::getInstance()->execute(
            "UPDATE refresh_tokens SET revoked = 1 WHERE token_hash = ?",
            [$tokenHash]
        );
    }

    public function buildAgentPayload(array $agent): array
    {
        return [
            'sub'                 => $agent['id'],
            'type'                => 'agent',
            'role'                => $agent['role'],
            'can_close_tickets'   => (bool)$agent['can_close_tickets'],
            'can_delete_tickets'  => (bool)$agent['can_delete_tickets'],
            'can_edit_customers'  => (bool)$agent['can_edit_customers'],
            'can_view_reports'    => (bool)$agent['can_view_reports'],
        ];
    }

    public function buildCustomerPayload(array $customer): array
    {
        return [
            'sub'  => $customer['id'],
            'type' => 'customer',
        ];
    }

    public function sanitiseAgent(array $agent): array
    {
        unset($agent['password_hash']);
        return $agent;
    }

    public function sanitiseCustomer(array $customer): array
    {
        $customer['has_password'] = !empty($customer['portal_password_hash']);
        unset($customer['portal_password_hash'], $customer['portal_token']);
        return $customer;
    }
}

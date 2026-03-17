<?php
declare(strict_types=1);

namespace Andrea\Helpdesk\Auth;

use Andrea\Helpdesk\Core\Database;
use Andrea\Helpdesk\Core\Request;
use Andrea\Helpdesk\Core\Response;
use Andrea\Helpdesk\Core\Exceptions\AuthException;
use Andrea\Helpdesk\Core\Exceptions\ValidationException;
use Andrea\Helpdesk\Notifications\EmailNotifier;

class AuthController
{
    private JwtService $jwt;
    private PasswordService $passwords;
    private Database $db;

    public function __construct()
    {
        $this->jwt       = new JwtService();
        $this->passwords = new PasswordService();
        $this->db        = Database::getInstance();
    }

    /**
     * POST /api/auth/login
     * Body: { email, password, type: 'agent'|'customer' }
     */
    public function login(Request $request): void
    {
        $data = $request->validate([
            'email'    => 'required|email',
            'password' => 'required',
            'type'     => 'required|in:agent,customer',
        ]);

        if ($data['type'] === 'agent') {
            $agent = $this->db->fetch(
                "SELECT * FROM agents WHERE email = ? AND is_active = 1",
                [$data['email']]
            );

            if (!$agent || !$this->passwords->verify($data['password'], $agent['password_hash'])) {
                throw new AuthException('Invalid email or password');
            }

            $this->db->execute("UPDATE agents SET last_login_at = NOW() WHERE id = ?", [$agent['id']]);

            $accessToken  = $this->jwt->issueAccessToken($this->jwt->buildAgentPayload($agent));
            $refreshToken = $this->jwt->issueRefreshToken($agent['id'], 'agent');

            Response::success([
                'access_token'  => $accessToken,
                'refresh_token' => $refreshToken,
                'user'          => $this->jwt->sanitiseAgent($agent),
            ]);
        } else {
            $customer = $this->db->fetch(
                "SELECT * FROM customers WHERE email = ? AND deleted_at IS NULL",
                [$data['email']]
            );

            if (!$customer || !$customer['portal_password_hash']) {
                throw new AuthException('Invalid email or password');
            }

            if (!$this->passwords->verify($data['password'], $customer['portal_password_hash'])) {
                throw new AuthException('Invalid email or password');
            }

            $accessToken  = $this->jwt->issueAccessToken($this->jwt->buildCustomerPayload($customer));
            $refreshToken = $this->jwt->issueRefreshToken($customer['id'], 'customer');

            Response::success([
                'access_token'  => $accessToken,
                'refresh_token' => $refreshToken,
                'user'          => $this->jwt->sanitiseCustomer($customer),
            ]);
        }
    }

    /**
     * POST /api/auth/refresh
     * Body: { refresh_token }
     */
    public function refresh(Request $request): void
    {
        $refreshToken = $request->input('refresh_token');
        if (!$refreshToken) {
            throw new ValidationException(['refresh_token' => ['refresh_token is required']]);
        }

        $result = $this->jwt->refreshAccessToken($refreshToken);
        Response::success($result);
    }

    /**
     * POST /api/auth/logout
     * Body: { refresh_token }
     */
    public function logout(Request $request): void
    {
        $refreshToken = $request->input('refresh_token');
        if ($refreshToken) {
            $this->jwt->revokeRefreshToken($refreshToken);
        }
        Response::success(null, 'Logged out');
    }

    /**
     * GET /api/auth/me
     */
    public function me(Request $request): void
    {
        if ($request->agent) {
            $agent = $this->db->fetch("SELECT * FROM agents WHERE id = ? AND is_active = 1", [$request->agent->sub]);
            if (!$agent) throw new AuthException();
            unset($agent['password_hash']);
            Response::success(['type' => 'agent', 'user' => $agent]);
        } elseif ($request->customer) {
            Response::success(['type' => 'customer', 'user' => (array)$request->customer]);
        } else {
            throw new AuthException();
        }
    }

    /**
     * POST /api/portal/auth/magic-link
     * Body: { email }
     */
    public function magicLink(Request $request): void
    {
        $email = $request->input('email', '');

        // Always return 200 to avoid leaking whether email exists
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::success(null, 'If this email exists, a login link has been sent.');
            return;
        }

        $customer = $this->db->fetch(
            "SELECT * FROM customers WHERE email = ? AND deleted_at IS NULL",
            [$email]
        );

        if ($customer) {
            $token   = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', time() + 3600);

            $this->db->execute(
                "UPDATE customers SET portal_token = ?, portal_token_expires = ? WHERE id = ?",
                [$token, $expires, $customer['id']]
            );

            try {
                $appUrl   = getenv('APP_URL') ?: 'https://your-helpdesk-domain';
                $link     = "{$appUrl}/#/portal/login?token={$token}&email=" . urlencode($email);
                $notifier = new EmailNotifier();
                $notifier->sendPortalInvite($customer, $link);
            } catch (\Throwable) {
                // Silently fail - don't leak info
            }
        }

        Response::success(null, 'If this email exists, a login link has been sent.');
    }
}

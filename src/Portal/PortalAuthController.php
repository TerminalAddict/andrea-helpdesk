<?php
declare(strict_types=1);

namespace Andrea\Helpdesk\Portal;

use Andrea\Helpdesk\Auth\JwtService;
use Andrea\Helpdesk\Auth\PasswordService;
use Andrea\Helpdesk\Core\Database;
use Andrea\Helpdesk\Core\Request;
use Andrea\Helpdesk\Core\Response;
use Andrea\Helpdesk\Core\Exceptions\AuthException;
use Andrea\Helpdesk\Core\Exceptions\ValidationException;

class PortalAuthController
{
    private Database $db;
    private JwtService $jwt;
    private PasswordService $passwords;

    public function __construct()
    {
        $this->db        = Database::getInstance();
        $this->jwt       = new JwtService();
        $this->passwords = new PasswordService();
    }

    /**
     * POST /api/portal/auth/verify-magic-link
     * Body: { token, email }
     */
    public function verifyMagicLink(Request $request): void
    {
        $token = $request->input('token', '');
        $email = $request->input('email', '');

        if (!$token || !$email) {
            throw new ValidationException(['token' => ['Token and email are required']]);
        }

        $customer = $this->db->fetch(
            "SELECT * FROM customers
             WHERE email = ?
               AND portal_token = ?
               AND portal_token_expires > NOW()
               AND deleted_at IS NULL",
            [$email, hash('sha256', $token)]
        );

        if (!$customer) {
            throw new AuthException('Invalid or expired magic link');
        }

        // Clear token
        $this->db->execute(
            "UPDATE customers SET portal_token = NULL, portal_token_expires = NULL WHERE id = ?",
            [$customer['id']]
        );

        $accessToken  = $this->jwt->issueAccessToken($this->jwt->buildCustomerPayload($customer));
        $refreshToken = $this->jwt->issueRefreshToken($customer['id'], 'customer');

        Response::success([
            'access_token'  => $accessToken,
            'refresh_token' => $refreshToken,
            'user'          => $this->jwt->sanitiseCustomer($customer),
        ]);
    }

    /**
     * POST /api/portal/auth/set-password
     * Requires auth:customer
     */
    public function setPassword(Request $request): void
    {
        $data = $request->validate([
            'password'         => 'required|min:8',
            'password_confirm' => 'required',
        ]);

        if ($data['password'] !== $data['password_confirm']) {
            throw new ValidationException(['password_confirm' => ['Passwords do not match']]);
        }

        $hash = $this->passwords->hash($data['password']);
        $this->db->execute(
            "UPDATE customers SET portal_password_hash = ? WHERE id = ?",
            [$hash, $request->customer->id]
        );

        Response::success(null, 'Password updated successfully');
    }

    /**
     * POST /api/portal/auth/change-password
     * Requires auth:customer. Verifies current password before setting new one.
     */
    public function changePassword(Request $request): void
    {
        $data = $request->validate([
            'current_password' => 'required',
            'password'         => 'required|min:8',
            'password_confirm' => 'required',
        ]);

        if ($data['password'] !== $data['password_confirm']) {
            throw new ValidationException(['password_confirm' => ['Passwords do not match']]);
        }

        $customer = $this->db->fetch(
            "SELECT * FROM customers WHERE id = ? AND deleted_at IS NULL",
            [$request->customer->id]
        );

        if (!$customer || !$this->passwords->verify($data['current_password'], $customer['portal_password_hash'] ?? '')) {
            throw new AuthException('Current password is incorrect');
        }

        $hash = $this->passwords->hash($data['password']);
        $this->db->execute(
            "UPDATE customers SET portal_password_hash = ? WHERE id = ?",
            [$hash, $request->customer->id]
        );

        Response::success(null, 'Password changed successfully');
    }
}

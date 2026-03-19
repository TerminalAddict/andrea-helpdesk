<?php
declare(strict_types=1);

namespace Andrea\Helpdesk\Customers;

use Andrea\Helpdesk\Auth\PasswordService;
use Andrea\Helpdesk\Notifications\EmailNotifier;

class CustomerService
{
    public function __construct(
        private CustomerRepository $repo,
        private PasswordService $passwords = new PasswordService()
    ) {}

    public function upsertByEmail(string $email, string $name = '', array $extra = []): array
    {
        return $this->repo->upsertByEmail($email, $name, $extra);
    }

    public function sendPortalInvite(int $customerId): bool
    {
        $customer = $this->repo->findById($customerId);
        if (!$customer) return false;

        $token   = bin2hex(random_bytes(32));
        $expires = new \DateTime('+1 hour');
        $this->repo->setPortalToken($customerId, hash('sha256', $token), $expires);

        $appUrl = rtrim(\Andrea\Helpdesk\Settings\SettingsService::getInstance()->get('app_url') ?: getenv('APP_URL') ?: '', '/');
        $link   = "{$appUrl}/#/portal/login?token={$token}&email=" . urlencode($customer['email']);

        try {
            $notifier = new EmailNotifier();
            return $notifier->sendPortalInvite($customer, $link);
        } catch (\Throwable) {
            return false;
        }
    }

    public function setPortalPassword(int $customerId, string $password): bool
    {
        $hash = $this->passwords->hash($password);
        return (bool)\Andrea\Helpdesk\Core\Database::getInstance()->execute(
            "UPDATE customers SET portal_password_hash = ? WHERE id = ?",
            [$hash, $customerId]
        );
    }
}

<?php
declare(strict_types=1);

namespace Andrea\Helpdesk\Auth;

class PasswordService
{
    public function hash(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    public function verify(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    public function generateReset(): string
    {
        return bin2hex(random_bytes(32));
    }

    public function generateTemporary(): string
    {
        $chars    = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $password = '';
        for ($i = 0; $i < 12; $i++) {
            $password .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $password;
    }

    public function meetsRequirements(string $password): bool
    {
        return strlen($password) >= 8;
    }
}

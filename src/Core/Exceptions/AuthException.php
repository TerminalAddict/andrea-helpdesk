<?php
declare(strict_types=1);

namespace Andrea\Helpdesk\Core\Exceptions;

class AuthException extends HttpException
{
    public function __construct(string $message = 'Unauthorised')
    {
        parent::__construct($message, 401);
    }
}

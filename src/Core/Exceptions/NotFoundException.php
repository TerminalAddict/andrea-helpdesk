<?php
declare(strict_types=1);

namespace Andrea\Helpdesk\Core\Exceptions;

class NotFoundException extends HttpException
{
    public function __construct(string $message = 'Not found')
    {
        parent::__construct($message, 404);
    }
}

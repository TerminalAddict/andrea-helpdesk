<?php
declare(strict_types=1);

namespace Andrea\Helpdesk\Core;

class VersionController
{
    public function index(): void
    {
        $path = dirname(__DIR__, 2) . '/version.json';
        $data = json_decode((string)file_get_contents($path), true);
        Response::success($data ?? ['version' => 'unknown']);
    }
}

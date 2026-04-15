<?php
declare(strict_types=1);

namespace Andrea\Helpdesk\Core;

class VersionController
{
    private VersionService $versions;

    public function __construct()
    {
        $this->versions = new VersionService();
    }

    public function index(): void
    {
        Response::success($this->versions->getInstalled());
    }

    public function latest(): void
    {
        Response::success($this->versions->getLatest());
    }
}

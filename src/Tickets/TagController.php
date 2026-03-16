<?php
declare(strict_types=1);

namespace Andrea\Helpdesk\Tickets;

use Andrea\Helpdesk\Core\Request;
use Andrea\Helpdesk\Core\Response;
use Andrea\Helpdesk\Core\Exceptions\ValidationException;

class TagController
{
    private TagRepository $repo;

    public function __construct()
    {
        $this->repo = new TagRepository();
    }

    public function index(Request $request): void
    {
        Response::success($this->repo->findAll());
    }

    public function store(Request $request): void
    {
        $data = $request->validate(['name' => 'required|max:60']);

        $existing = $this->repo->findByName($data['name']);
        if ($existing) {
            Response::success($existing, 'Tag already exists');
            return;
        }

        $id  = $this->repo->create($data['name']);
        $tag = $this->repo->findById($id);
        Response::created($tag, 'Tag created');
    }
}

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

    public function update(Request $request, array $params): void
    {
        $data = $request->validate(['name' => 'required|max:60']);
        $tag  = $this->repo->findById((int)$params['id']);
        if (!$tag) {
            \Andrea\Helpdesk\Core\Response::error('Tag not found', 404);
            return;
        }
        $this->repo->update((int)$params['id'], $data['name']);
        Response::success($this->repo->findById((int)$params['id']), 'Tag updated');
    }

    public function destroy(Request $request, array $params): void
    {
        $tag = $this->repo->findById((int)$params['id']);
        if (!$tag) {
            \Andrea\Helpdesk\Core\Response::error('Tag not found', 404);
            return;
        }
        $this->repo->delete((int)$params['id']);
        Response::success(null, 'Tag deleted');
    }
}

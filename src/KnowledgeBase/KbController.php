<?php
declare(strict_types=1);

namespace Andrea\Helpdesk\KnowledgeBase;

use Andrea\Helpdesk\Core\Request;
use Andrea\Helpdesk\Core\Response;
use Andrea\Helpdesk\Core\Exceptions\NotFoundException;

class KbController
{
    private KbRepository $repo;
    private KbService $service;

    public function __construct()
    {
        $this->repo    = new KbRepository();
        $this->service = new KbService($this->repo);
    }

    public function categories(Request $request): void
    {
        Response::success($this->repo->getCategories());
    }

    public function index(Request $request): void
    {
        $page    = max(1, (int)$request->input('page', 1));
        $perPage = min(50, max(1, (int)$request->input('per_page', 20)));

        $filters = ['q' => $request->input('q'), 'category_id' => $request->input('category_id')];

        // Public (unauthenticated) only sees published articles
        if (!$request->agent) {
            $filters['is_published'] = true;
        }

        $result = $this->repo->findAll(array_filter($filters), $page, $perPage);
        Response::paginated($result['items'], $result['total'], $page, $perPage);
    }

    public function show(Request $request, array $params): void
    {
        $slug = $params['slug'];
        $article = is_numeric($slug)
            ? $this->repo->findById((int)$slug)
            : $this->repo->findBySlug($slug);
        if (!$article) throw new NotFoundException('Article not found');

        // If no agent loaded yet, try optional token auth so agents can view drafts
        if (!$request->agent && $request->bearerToken()) {
            try {
                \Andrea\Helpdesk\Core\Middleware::run('auth:agent', $request);
            } catch (\Throwable) {}
        }

        // Unpublished articles only visible to agents
        if (!$article['is_published'] && !$request->agent) {
            throw new NotFoundException('Article not found');
        }

        $this->repo->incrementViewCount($article['id']);
        Response::success($article);
    }

    public function store(Request $request): void
    {
        $data = $request->validate(['title' => 'required', 'body_html' => 'required']);
        $data['category_id']  = $request->input('category_id');
        $data['is_published'] = (bool)$request->input('is_published', false);

        $article = $this->service->create($data, $request->agent->id);
        Response::created($article, 'Article created');
    }

    public function update(Request $request, array $params): void
    {
        $article = $this->repo->findById((int)$params['id']);
        if (!$article) throw new NotFoundException('Article not found');

        $data = [];
        foreach (['title', 'body_html', 'category_id', 'is_published'] as $field) {
            if ($request->input($field) !== null) {
                $data[$field] = $request->input($field);
            }
        }

        $updated = $this->service->update($article['id'], $data);
        Response::success($updated, 'Article updated');
    }

    public function publish(Request $request, array $params): void
    {
        $article = $this->repo->findById((int)$params['id']);
        if (!$article) throw new NotFoundException('Article not found');
        $this->service->publish($article['id']);
        Response::success(null, 'Article published');
    }

    public function destroy(Request $request, array $params): void
    {
        $article = $this->repo->findById((int)$params['id']);
        if (!$article) throw new NotFoundException('Article not found');
        $this->repo->softDelete($article['id']);
        Response::success(null, 'Article deleted');
    }

    public function storeCategory(Request $request): void
    {
        $data    = $request->validate(['name' => 'required']);
        $data['sort_order'] = (int)$request->input('sort_order', 0);
        $id      = $this->repo->createCategory($data);
        Response::created($this->repo->getCategories(), 'Category created');
    }

    public function updateCategory(Request $request, array $params): void
    {
        $data = [];
        foreach (['name', 'sort_order'] as $field) {
            if ($request->input($field) !== null) $data[$field] = $request->input($field);
        }
        $this->repo->updateCategory((int)$params['id'], $data);
        Response::success($this->repo->getCategories(), 'Category updated');
    }

    public function destroyCategory(Request $request, array $params): void
    {
        $id     = (int)$params['id'];
        $moveTo = $request->input('move_to_category_id'); // null = uncategorized, int = target category

        if ($moveTo !== null) {
            $this->repo->moveArticlesFromCategory($id, $moveTo ? (int)$moveTo : null);
        }

        $this->repo->deleteCategory($id);
        Response::success($this->repo->getCategories(), 'Category deleted');
    }
}

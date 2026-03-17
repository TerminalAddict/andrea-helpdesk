<?php
declare(strict_types=1);

namespace Andrea\Helpdesk\KnowledgeBase;

use Andrea\Helpdesk\Core\Database;

class KbRepository
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function findById(int $id): ?array
    {
        return $this->db->fetch(
            "SELECT a.*, c.name AS category_name, ag.name AS author_name
             FROM knowledge_base_articles a
             LEFT JOIN knowledge_base_categories c ON c.id = a.category_id
             LEFT JOIN agents ag ON ag.id = a.author_agent_id
             WHERE a.id = ? AND a.deleted_at IS NULL",
            [$id]
        );
    }

    public function findBySlug(string $slug): ?array
    {
        return $this->db->fetch(
            "SELECT a.*, c.name AS category_name, ag.name AS author_name
             FROM knowledge_base_articles a
             LEFT JOIN knowledge_base_categories c ON c.id = a.category_id
             LEFT JOIN agents ag ON ag.id = a.author_agent_id
             WHERE a.slug = ? AND a.deleted_at IS NULL",
            [$slug]
        );
    }

    public function findAll(array $filters = [], int $page = 1, int $perPage = 25): array
    {
        $where  = ['a.deleted_at IS NULL'];
        $params = [];

        if (!empty($filters['is_published'])) {
            $where[] = 'a.is_published = 1';
        }

        if (!empty($filters['category_id'])) {
            $where[]  = 'a.category_id = ?';
            $params[] = (int)$filters['category_id'];
        }

        if (!empty($filters['q'])) {
            $where[]  = 'MATCH(a.title, a.body_html) AGAINST(? IN BOOLEAN MODE)';
            $params[] = $filters['q'] . '*';
        }

        $whereClause = implode(' AND ', $where);
        $total       = $this->db->count(
            "SELECT COUNT(*) FROM knowledge_base_articles a WHERE {$whereClause}",
            $params
        );
        $offset = ($page - 1) * $perPage;

        $items = $this->db->fetchAll(
            "SELECT a.id, a.title, a.slug, a.is_published, a.view_count, a.created_at, a.updated_at,
                    a.category_id, c.name AS category_name, ag.name AS author_name
             FROM knowledge_base_articles a
             LEFT JOIN knowledge_base_categories c ON c.id = a.category_id
             LEFT JOIN agents ag ON ag.id = a.author_agent_id
             WHERE {$whereClause}
             ORDER BY a.title ASC
             LIMIT {$perPage} OFFSET {$offset}",
            $params
        );

        return ['items' => $items, 'total' => $total];
    }

    public function create(array $data): int
    {
        return $this->db->insert(
            "INSERT INTO knowledge_base_articles (category_id, title, slug, body_html, is_published, author_agent_id, source_ticket_id)
             VALUES (?, ?, ?, ?, ?, ?, ?)",
            [
                $data['category_id'] ?? null,
                $data['title'],
                $this->generateSlug($data['title']),
                $data['body_html'],
                $data['is_published'] ?? 0,
                $data['author_agent_id'] ?? null,
                $data['source_ticket_id'] ?? null,
            ]
        );
    }

    public function update(int $id, array $data): bool
    {
        $allowed = ['category_id', 'title', 'body_html', 'is_published'];
        $set     = [];
        $params  = [];

        foreach ($allowed as $col) {
            if (array_key_exists($col, $data)) {
                $set[]    = "{$col} = ?";
                $params[] = $data[$col];
            }
        }

        if (isset($data['title'])) {
            $set[]    = 'slug = ?';
            $params[] = $this->generateSlug($data['title'], $id);
        }

        if (empty($set)) return false;
        $params[] = $id;
        return $this->db->execute("UPDATE knowledge_base_articles SET " . implode(', ', $set) . " WHERE id = ?", $params);
    }

    public function softDelete(int $id): bool
    {
        return $this->db->execute("UPDATE knowledge_base_articles SET deleted_at = NOW() WHERE id = ?", [$id]);
    }

    public function incrementViewCount(int $id): bool
    {
        return $this->db->execute("UPDATE knowledge_base_articles SET view_count = view_count + 1 WHERE id = ?", [$id]);
    }

    public function getCategories(): array
    {
        return $this->db->fetchAll(
            "SELECT c.*, COUNT(a.id) AS article_count
             FROM knowledge_base_categories c
             LEFT JOIN knowledge_base_articles a ON a.category_id = c.id AND a.deleted_at IS NULL
             GROUP BY c.id
             ORDER BY c.sort_order ASC, c.name ASC"
        );
    }

    public function moveArticlesFromCategory(int $fromId, ?int $toId): bool
    {
        return $this->db->execute(
            "UPDATE knowledge_base_articles SET category_id = ? WHERE category_id = ? AND deleted_at IS NULL",
            [$toId, $fromId]
        );
    }

    public function createCategory(array $data): int
    {
        $slug = $this->generateCategorySlug($data['name']);
        return $this->db->insert(
            "INSERT INTO knowledge_base_categories (name, slug, sort_order) VALUES (?, ?, ?)",
            [$data['name'], $slug, $data['sort_order'] ?? 0]
        );
    }

    public function updateCategory(int $id, array $data): bool
    {
        $set    = [];
        $params = [];
        foreach (['name', 'sort_order'] as $col) {
            if (array_key_exists($col, $data)) {
                $set[]    = "{$col} = ?";
                $params[] = $data[$col];
            }
        }
        if (empty($set)) return false;
        $params[] = $id;
        return $this->db->execute("UPDATE knowledge_base_categories SET " . implode(', ', $set) . " WHERE id = ?", $params);
    }

    public function deleteCategory(int $id): bool
    {
        return $this->db->execute("DELETE FROM knowledge_base_categories WHERE id = ?", [$id]);
    }

    public function generateSlug(string $title, ?int $excludeId = null): string
    {
        $base = strtolower(preg_replace('/[^a-z0-9-]/i', '-', $title));
        $base = preg_replace('/-+/', '-', trim($base, '-'));
        $base = substr($base, 0, 200);

        $slug    = $base;
        $counter = 2;

        while (true) {
            $excludeClause = $excludeId ? "AND id != {$excludeId}" : '';
            $existing = $this->db->fetch(
                "SELECT id FROM knowledge_base_articles WHERE slug = ? {$excludeClause} AND deleted_at IS NULL",
                [$slug]
            );
            if (!$existing) break;
            $slug = $base . '-' . $counter++;
        }

        return $slug;
    }

    private function generateCategorySlug(string $name): string
    {
        $base    = strtolower(preg_replace('/[^a-z0-9-]/i', '-', $name));
        $base    = preg_replace('/-+/', '-', trim($base, '-'));
        $slug    = $base;
        $counter = 2;
        while ($this->db->fetch("SELECT id FROM knowledge_base_categories WHERE slug = ?", [$slug])) {
            $slug = $base . '-' . $counter++;
        }
        return $slug;
    }
}

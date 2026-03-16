<?php
declare(strict_types=1);

namespace Andrea\Helpdesk\Tickets;

use Andrea\Helpdesk\Core\Database;

class TagRepository
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function findAll(): array
    {
        return $this->db->fetchAll("SELECT * FROM tags ORDER BY name ASC");
    }

    public function findById(int $id): ?array
    {
        return $this->db->fetch("SELECT * FROM tags WHERE id = ?", [$id]);
    }

    public function findByName(string $name): ?array
    {
        return $this->db->fetch("SELECT * FROM tags WHERE name = ?", [$name]);
    }

    public function create(string $name): int
    {
        return $this->db->insert("INSERT INTO tags (name) VALUES (?)", [trim($name)]);
    }
}

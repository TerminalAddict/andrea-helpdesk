<?php
declare(strict_types=1);

namespace Andrea\Helpdesk\IMAP;

use Andrea\Helpdesk\Core\Database;
use Andrea\Helpdesk\Settings\SettingsService;

class ImapAccountRepository
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function findAll(): array
    {
        return $this->db->fetchAll(
            "SELECT a.*, t.name AS tag_name
             FROM imap_accounts a
             LEFT JOIN tags t ON t.id = a.tag_id
             ORDER BY a.id ASC"
        );
    }

    public function findEnabled(): array
    {
        return $this->db->fetchAll(
            "SELECT a.*, t.name AS tag_name
             FROM imap_accounts a
             LEFT JOIN tags t ON t.id = a.tag_id
             WHERE a.is_enabled = 1
             ORDER BY a.id ASC"
        );
    }

    public function findById(int $id): ?array
    {
        return $this->db->fetch(
            "SELECT a.*, t.name AS tag_name
             FROM imap_accounts a
             LEFT JOIN tags t ON t.id = a.tag_id
             WHERE a.id = ?",
            [$id]
        );
    }

    public function create(array $data): int
    {
        $password = !empty($data['password'])
            ? SettingsService::getInstance()->encrypt($data['password'])
            : null;

        return $this->db->insert(
            "INSERT INTO imap_accounts (name, host, port, encryption, username, password, folder, delete_after_import, tag_id, is_enabled)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $data['name'],
                $data['host'],
                (int)($data['port'] ?? 993),
                $data['encryption'] ?? 'ssl',
                $data['username'],
                $password,
                $data['folder'] ?? 'INBOX',
                (int)(bool)($data['delete_after_import'] ?? false),
                $data['tag_id'] ? (int)$data['tag_id'] : null,
                (int)(bool)($data['is_enabled'] ?? true),
            ]
        );
    }

    public function update(int $id, array $data): bool
    {
        $set    = [];
        $params = [];

        $stringCols = ['name', 'host', 'encryption', 'username', 'folder'];
        foreach ($stringCols as $col) {
            if (array_key_exists($col, $data)) {
                $set[]    = "{$col} = ?";
                $params[] = $data[$col];
            }
        }

        if (array_key_exists('port', $data)) {
            $set[]    = "port = ?";
            $params[] = (int)$data['port'];
        }

        if (array_key_exists('password', $data) && $data['password'] !== '') {
            $set[]    = "password = ?";
            $params[] = SettingsService::getInstance()->encrypt($data['password']);
        }

        foreach (['delete_after_import', 'is_enabled'] as $col) {
            if (array_key_exists($col, $data)) {
                $set[]    = "{$col} = ?";
                $params[] = (int)(bool)$data[$col];
            }
        }

        if (array_key_exists('tag_id', $data)) {
            $set[]    = "tag_id = ?";
            $params[] = $data['tag_id'] ? (int)$data['tag_id'] : null;
        }

        if (empty($set)) return false;
        $params[] = $id;
        return $this->db->execute("UPDATE imap_accounts SET " . implode(', ', $set) . " WHERE id = ?", $params);
    }

    public function delete(int $id): bool
    {
        return $this->db->execute("DELETE FROM imap_accounts WHERE id = ?", [$id]);
    }

    public function getDecryptedPassword(int $id): string
    {
        $row = $this->db->fetch("SELECT password FROM imap_accounts WHERE id = ?", [$id]);
        if (!$row || empty($row['password'])) return '';
        return SettingsService::getInstance()->decrypt($row['password']);
    }
}

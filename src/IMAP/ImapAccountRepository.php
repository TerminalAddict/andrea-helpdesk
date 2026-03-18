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
            "INSERT INTO imap_accounts (name, host, port, encryption, username, from_address, password, folder, delete_after_import, tag_id, is_enabled)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $data['name'],
                $data['host'],
                (int)($data['port'] ?? 993),
                $data['encryption'] ?? 'ssl',
                $data['username'],
                $data['from_address'] ? trim($data['from_address']) : null,
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

        $stringCols = ['name', 'host', 'encryption', 'username', 'from_address', 'folder'];
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

    /**
     * Given a list of tag IDs, return the first enabled IMAP account whose tag_id matches.
     * Used to resolve the From address for outgoing ticket emails.
     */
    public function findByTagIds(array $tagIds): ?array
    {
        if (empty($tagIds)) return null;
        $placeholders = implode(',', array_fill(0, count($tagIds), '?'));
        return $this->db->fetch(
            "SELECT * FROM imap_accounts WHERE tag_id IN ({$placeholders}) AND is_enabled = 1 ORDER BY id ASC LIMIT 1",
            $tagIds
        );
    }

    public function recordConnected(int $id): void
    {
        $this->db->execute(
            "UPDATE imap_accounts SET last_connected_at = NOW() WHERE id = ?",
            [$id]
        );
    }

    public function recordPoll(int $id, int $count): void
    {
        $this->db->execute(
            "UPDATE imap_accounts SET last_poll_at = NOW(), last_poll_count = ?" . ($count > 0 ? ", last_import_at = NOW()" : "") . " WHERE id = ?",
            [$count, $id]
        );
    }

    public function getDecryptedPassword(int $id): string
    {
        $row = $this->db->fetch("SELECT password FROM imap_accounts WHERE id = ?", [$id]);
        if (!$row || empty($row['password'])) return '';
        return SettingsService::getInstance()->decrypt($row['password']);
    }
}

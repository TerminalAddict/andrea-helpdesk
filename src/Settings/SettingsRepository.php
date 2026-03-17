<?php
declare(strict_types=1);

namespace Andrea\Helpdesk\Settings;

use Andrea\Helpdesk\Core\Database;

class SettingsRepository
{
    private Database $db;
    private array $cache = [];

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $row = $this->db->fetch("SELECT value, type FROM settings WHERE key_name = ?", [$key]);
        if (!$row) {
            return $default;
        }

        $value = $this->castValue($row['value'] ?? '', $row['type']);
        $this->cache[$key] = $value;
        return $value;
    }

    public function getGroup(string $group): array
    {
        $rows   = $this->db->fetchAll("SELECT key_name, value, type FROM settings WHERE group_name = ?", [$group]);
        $result = [];
        foreach ($rows as $row) {
            $result[$row['key_name']] = $this->castValue($row['value'] ?? '', $row['type']);
        }
        return $result;
    }

    public function getAll(): array
    {
        $rows   = $this->db->fetchAll("SELECT key_name, value, type FROM settings ORDER BY key_name");
        $result = [];
        foreach ($rows as $row) {
            $result[$row['key_name']] = $this->castValue($row['value'] ?? '', $row['type']);
        }
        return $result;
    }

    public function set(string $key, mixed $value): bool
    {
        unset($this->cache[$key]);
        $row = $this->db->fetch("SELECT type FROM settings WHERE key_name = ?", [$key]);
        $type = $row['type'] ?? 'string';
        return $this->db->execute(
            "UPDATE settings SET value = ? WHERE key_name = ?",
            [$this->prepareValue($value, $type), $key]
        );
    }

    public function setMany(array $data): bool
    {
        $this->db->beginTransaction();
        try {
            foreach ($data as $key => $value) {
                $this->set($key, $value);
            }
            $this->db->commit();
            return true;
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    private function castValue(string $value, string $type): mixed
    {
        return match ($type) {
            'boolean' => (bool)(int)$value,
            'integer' => (int)$value,
            'json'    => json_decode($value, true) ?? [],
            default   => $value,
        };
    }

    private function prepareValue(mixed $value, string $type): string
    {
        return match ($type) {
            'boolean' => $value ? '1' : '0',
            'integer' => (string)(int)$value,
            'json'    => json_encode($value),
            default   => (string)$value,
        };
    }
}

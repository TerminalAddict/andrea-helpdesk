<?php
declare(strict_types=1);

namespace Andrea\Helpdesk\Core;

use PDO;
use PDOException;

class Database
{
    private static ?Database $instance = null;
    private PDO $pdo;
    private CacheService $cache;
    private bool $cacheDirty = false;

    private function __construct()
    {
        $host      = getenv('DB_HOST') ?: 'localhost';
        $port      = getenv('DB_PORT') ?: '3306';
        $dbname    = getenv('DB_DATABASE') ?: '';
        $username  = getenv('DB_USERNAME') ?: '';
        $password  = getenv('DB_PASSWORD') ?: '';
        $charset   = $this->validatedIdentifier(getenv('DB_CHARSET') ?: 'utf8mb4', 'utf8mb4');
        $collation = $this->validatedIdentifier(getenv('DB_COLLATION') ?: 'utf8mb4_unicode_ci', 'utf8mb4_unicode_ci');

        $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset={$charset}";

        $this->pdo = new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);

        $this->pdo->exec("SET NAMES '{$charset}' COLLATE '{$collation}'");
        $this->cache = new CacheService($this->loadCacheConfig());
    }

    private function validatedIdentifier(string $value, string $default): string
    {
        return preg_match('/^[A-Za-z0-9_]+$/', $value) === 1 ? $value : $default;
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function fetch(string $sql, array $params = []): ?array
    {
        if ($this->isCacheableSelect($sql)) {
            $key = $this->cacheKey('fetch', $sql, $params);
            $cached = $this->cache->get($key);
            if ($cached !== null) {
                return $cached;
            }
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch();
        $result = $result ?: null;

        if (isset($key)) {
            $this->cache->set($key, $result);
        }
        return $result;
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        if ($this->isCacheableSelect($sql)) {
            $key = $this->cacheKey('fetchAll', $sql, $params);
            $cached = $this->cache->get($key);
            if ($cached !== null) {
                return is_array($cached) ? $cached : [];
            }
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetchAll();

        if (isset($key)) {
            $this->cache->set($key, $result);
        }
        return $result;
    }

    public function execute(string $sql, array $params = []): bool
    {
        $stmt = $this->pdo->prepare($sql);
        $result = $stmt->execute($params);
        if ($result && $this->isMutatingStatement($sql)) {
            $this->invalidateCache();
        }
        return $result;
    }

    public function executeAffected(string $sql, array $params = []): int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $affected = $stmt->rowCount();
        if ($affected > 0 && $this->isMutatingStatement($sql)) {
            $this->invalidateCache();
        }
        return $affected;
    }

    public function insert(string $sql, array $params = []): int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $this->invalidateCache();
        return (int)$this->pdo->lastInsertId();
    }

    public function count(string $sql, array $params = []): int
    {
        if ($this->isCacheableSelect($sql)) {
            $key = $this->cacheKey('count', $sql, $params);
            $cached = $this->cache->get($key);
            if ($cached !== null) {
                return (int)$cached;
            }
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $result = (int)$stmt->fetchColumn();

        if (isset($key)) {
            $this->cache->set($key, $result);
        }
        return $result;
    }

    public function beginTransaction(): void
    {
        $this->pdo->beginTransaction();
    }

    public function commit(): void
    {
        $this->pdo->commit();
        if ($this->cacheDirty) {
            $this->cache->flush();
            $this->cacheDirty = false;
        }
    }

    public function rollback(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        $this->cacheDirty = false;
    }

    public function getPdo(): PDO
    {
        return $this->pdo;
    }

    public function lastInsertId(): int
    {
        return (int)$this->pdo->lastInsertId();
    }

    public function getCacheStatus(): array
    {
        return $this->cache->status();
    }

    public function clearCache(): void
    {
        $this->cache->flush();
    }

    private function invalidateCache(): void
    {
        if (!$this->cache->isEnabled()) {
            return;
        }
        if ($this->pdo->inTransaction()) {
            $this->cacheDirty = true;
            return;
        }
        $this->cache->flush();
    }

    private function isCacheableSelect(string $sql): bool
    {
        if (!$this->cache->isEnabled() || $this->pdo->inTransaction()) {
            return false;
        }

        $normalised = strtolower(trim(preg_replace('/\s+/', ' ', $sql) ?: $sql));
        if (!str_starts_with($normalised, 'select')) {
            return false;
        }

        foreach (['last_insert_id()', ' for update', ' lock in share mode', ' get_lock(', ' release_lock('] as $blocked) {
            if (str_contains($normalised, $blocked)) {
                return false;
            }
        }
        return true;
    }

    private function isMutatingStatement(string $sql): bool
    {
        $normalised = strtolower(ltrim($sql));
        return (bool)preg_match('/^(insert|update|delete|replace|alter|create|drop|truncate|rename)\b/', $normalised);
    }

    private function cacheKey(string $mode, string $sql, array $params): string
    {
        return $mode . ':' . hash('sha256', $sql . "\n" . json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function loadCacheConfig(): array
    {
        $defaults = [
            'enabled' => false,
            'cache_home' => 'cache',
            'redis_host' => '127.0.0.1',
            'redis_port' => 6379,
            'redis_prefix' => 'andrea_helpdesk',
            'redis_database' => 1,
            'ttl' => 60,
        ];

        try {
            $stmt = $this->pdo->query("SELECT key_name, value, type FROM settings WHERE key_name IN ('cache_enabled','cache_home','cache_ttl_seconds','redis_host','redis_port','redis_prefix','redis_database','company_name')");
            foreach ($stmt ? $stmt->fetchAll() : [] as $row) {
                $key = (string)$row['key_name'];
                $value = $this->castSettingValue((string)($row['value'] ?? ''), (string)($row['type'] ?? 'string'));
                if ($key === 'cache_enabled') $defaults['enabled'] = $value;
                if ($key === 'cache_home') $defaults['cache_home'] = $value;
                if ($key === 'cache_ttl_seconds') $defaults['ttl'] = $value;
                if ($key === 'redis_host') $defaults['redis_host'] = $value;
                if ($key === 'redis_port') $defaults['redis_port'] = $value;
                if ($key === 'redis_prefix') $defaults['redis_prefix'] = $value;
                if ($key === 'redis_database') $defaults['redis_database'] = $value;
                if ($key === 'company_name' && empty($defaults['redis_prefix'])) $defaults['redis_prefix'] = $value;
            }
        } catch (\Throwable) {
            // The settings table may not exist yet during install/migration.
        }

        $envMap = [
            'CACHE' => 'enabled',
            'CACHEHOME' => 'cache_home',
            'CACHE_HOME' => 'cache_home',
            'CACHE_TTL_SECONDS' => 'ttl',
            'REDIS_HOST' => 'redis_host',
            'REDIS_PORT' => 'redis_port',
            'REDIS_PREFIX' => 'redis_prefix',
            'REDIS_DATABASE' => 'redis_database',
        ];
        foreach ($envMap as $env => $key) {
            $value = getenv($env);
            if ($value !== false && $value !== '') {
                $defaults[$key] = $value;
            }
        }

        return $defaults;
    }

    private function castSettingValue(string $value, string $type): mixed
    {
        return match ($type) {
            'boolean' => (bool)(int)$value,
            'integer' => (int)$value,
            'json' => json_decode($value, true) ?? [],
            default => $value,
        };
    }
}

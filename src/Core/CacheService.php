<?php
declare(strict_types=1);

namespace Andrea\Helpdesk\Core;

class CacheService
{
    private const DEFAULT_TTL = 60;

    private bool $enabled;
    private string $cacheHome;
    private string $redisHost;
    private int $redisPort;
    private string $redisPrefix;
    private int $redisDatabase;
    private int $ttl;
    private mixed $redis = null;
    private bool $redisAttempted = false;
    private bool $redisConnected = false;
    private string $redisError = '';

    public function __construct(array $config = [])
    {
        $root = dirname(__DIR__, 2);
        $defaultHome = getenv('STORAGE_PATH')
            ? rtrim((string)getenv('STORAGE_PATH'), '/') . '/cache'
            : $root . '/cache';

        $this->enabled = $this->toBool($config['enabled'] ?? false);
        $this->cacheHome = $this->resolvePath((string)($config['cache_home'] ?? $defaultHome), $root);
        $this->redisHost = trim((string)($config['redis_host'] ?? '127.0.0.1')) ?: '127.0.0.1';
        $this->redisPort = max(1, min(65535, (int)($config['redis_port'] ?? 6379)));
        $this->redisPrefix = $this->normalisePrefix((string)($config['redis_prefix'] ?? 'andrea_helpdesk'));
        $this->redisDatabase = max(0, min(15, (int)($config['redis_database'] ?? 1)));
        $this->ttl = max(5, min(86400, (int)($config['ttl'] ?? self::DEFAULT_TTL)));
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function get(string $key): mixed
    {
        if (!$this->enabled) {
            return null;
        }

        if ($this->connectRedis()) {
            $value = $this->redis->get($this->redisKey($key));
            return $value === false ? null : $this->decode($value);
        }

        return $this->getFile($key);
    }

    public function set(string $key, mixed $value, ?int $ttl = null): void
    {
        if (!$this->enabled) {
            return;
        }

        $ttl = $ttl !== null ? max(1, $ttl) : $this->ttl;
        $encoded = $this->encode($value);

        if ($this->connectRedis()) {
            $this->redis->setex($this->redisKey($key), $ttl, $encoded);
            return;
        }

        $this->setFile($key, $encoded, $ttl);
    }

    public function flush(): void
    {
        if (!$this->enabled) {
            return;
        }

        if ($this->connectRedis()) {
            $this->redis->incr($this->baseKey('version'));
            return;
        }

        $this->writeFile($this->cacheHome . '/version', (string)(time() . random_int(1000, 9999)));
    }

    public function status(): array
    {
        $extensionLoaded = class_exists('Redis');
        $redisConnected = $this->connectRedis();
        $fileWritable = $this->ensureCacheDirectory();
        $backend = $this->enabled ? ($redisConnected ? 'redis' : ($fileWritable ? 'file' : 'none')) : 'disabled';
        $healthy = !$this->enabled || $redisConnected || $fileWritable;

        return [
            'enabled' => $this->enabled,
            'healthy' => $healthy,
            'backend' => $backend,
            'ttl_seconds' => $this->ttl,
            'cache_home' => $this->cacheHome,
            'cache_home_writable' => $fileWritable,
            'redis_extension_loaded' => $extensionLoaded,
            'redis_host' => $this->redisHost,
            'redis_port' => $this->redisPort,
            'redis_database' => $this->redisDatabase,
            'redis_prefix' => $this->redisPrefix,
            'redis_connected' => $redisConnected,
            'redis_error' => $this->redisError,
            'message' => $this->statusMessage($extensionLoaded, $redisConnected, $fileWritable),
            'instructions' => $this->instructions($extensionLoaded, $redisConnected),
        ];
    }

    private function connectRedis(): bool
    {
        if ($this->redisConnected) {
            return true;
        }
        if ($this->redisAttempted) {
            return false;
        }

        $this->redisAttempted = true;
        if (!class_exists('Redis')) {
            $this->redisError = 'PHP extension redis is not installed or not enabled.';
            return false;
        }

        try {
            $redis = new \Redis();
            $redis->connect($this->redisHost, $this->redisPort, 0.25);
            if ($this->redisDatabase > 0) {
                $redis->select($this->redisDatabase);
            }
            $redis->ping();
            $this->redis = $redis;
            $this->redisConnected = true;
            return true;
        } catch (\Throwable $e) {
            $this->redisError = $e->getMessage();
            $this->redis = null;
            return false;
        }
    }

    private function getFile(string $key): mixed
    {
        $path = $this->filePath($key);
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }

        $contents = file_get_contents($path);
        if ($contents === false || !str_contains($contents, "\n")) {
            return null;
        }

        [$expires, $encoded] = explode("\n", $contents, 2);
        if ((int)$expires < time()) {
            @unlink($path);
            return null;
        }

        return $this->decode($encoded);
    }

    private function setFile(string $key, string $encoded, int $ttl): void
    {
        if (!$this->ensureCacheDirectory()) {
            return;
        }

        $this->writeFile($this->filePath($key), (string)(time() + $ttl) . "\n" . $encoded);
    }

    private function filePath(string $key): string
    {
        return $this->cacheHome . '/' . hash('sha256', $this->version() . ':' . $key) . '.cache';
    }

    private function version(): string
    {
        if ($this->connectRedis()) {
            $version = $this->redis->get($this->baseKey('version'));
            if ($version === false || $version === null || $version === '') {
                $this->redis->set($this->baseKey('version'), '1');
                return '1';
            }
            return (string)$version;
        }

        $path = $this->cacheHome . '/version';
        if (!is_file($path)) {
            $this->ensureCacheDirectory();
            $this->writeFile($path, '1');
            return '1';
        }
        return trim((string)file_get_contents($path)) ?: '1';
    }

    private function redisKey(string $key): string
    {
        return $this->baseKey('v' . $this->version() . ':' . hash('sha256', $key));
    }

    private function baseKey(string $key): string
    {
        return $this->redisPrefix . ':' . $key;
    }

    private function encode(mixed $value): string
    {
        return serialize($value);
    }

    private function decode(string $value): mixed
    {
        try {
            return unserialize($value, ['allowed_classes' => false]);
        } catch (\Throwable) {
            return null;
        }
    }

    private function ensureCacheDirectory(): bool
    {
        if (!is_dir($this->cacheHome)) {
            @mkdir($this->cacheHome, 0775, true);
        }
        return is_dir($this->cacheHome) && is_writable($this->cacheHome);
    }

    private function writeFile(string $path, string $contents): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents($path, $contents, LOCK_EX);
    }

    private function resolvePath(string $path, string $root): string
    {
        $path = trim($path);
        if ($path === '') {
            return getenv('STORAGE_PATH')
                ? rtrim((string)getenv('STORAGE_PATH'), '/') . '/cache'
                : $root . '/cache';
        }
        if ($path[0] === '/') {
            return rtrim($path, '/');
        }
        return rtrim($root . '/' . $path, '/');
    }

    private function normalisePrefix(string $prefix): string
    {
        $prefix = preg_replace('/[^A-Za-z0-9:_-]+/', '_', trim($prefix)) ?: 'andrea_helpdesk';
        return trim($prefix, ':_-') ?: 'andrea_helpdesk';
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    private function statusMessage(bool $extensionLoaded, bool $redisConnected, bool $fileWritable): string
    {
        if (!$this->enabled) {
            return 'Application cache is disabled.';
        }
        if ($redisConnected) {
            return 'Redis cache is enabled and reachable.';
        }
        if (!$extensionLoaded) {
            return 'Redis is configured but the PHP redis extension is missing. File cache fallback is being used if the cache directory is writable.';
        }
        if (!$fileWritable) {
            return 'Cache is enabled, but Redis is unavailable and the cache directory is not writable.';
        }
        return 'Redis is unavailable. File cache fallback is active.';
    }

    private function instructions(bool $extensionLoaded, bool $redisConnected): array
    {
        $items = [];
        if (!$extensionLoaded) {
            $items[] = 'Install the PHP redis extension, then restart PHP/Apache. Debian/Ubuntu: sudo apt install php-redis redis-server. RHEL/Fedora: sudo dnf install php-pecl-redis redis. Arch: sudo pacman -S php-redis redis. openSUSE: sudo zypper install php-redis redis. Gentoo: sudo emerge dev-php/pecl-redis dev-db/redis.';
        }
        if (!$redisConnected) {
            $items[] = 'Start and enable Redis locally. systemd: sudo systemctl enable --now redis-server or sudo systemctl enable --now redis. Confirm with redis-cli -h ' . $this->redisHost . ' -p ' . $this->redisPort . ' ping.';
        }
        $items[] = 'Use a unique Redis database number from 0-15 and a unique prefix for each Andrea Helpdesk install on the same Redis server.';
        return $items;
    }
}

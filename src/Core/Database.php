<?php
declare(strict_types=1);

namespace Andrea\Helpdesk\Core;

use PDO;
use PDOException;

class Database
{
    private static ?Database $instance = null;
    private PDO $pdo;

    private function __construct()
    {
        $host      = getenv('DB_HOST') ?: 'localhost';
        $port      = getenv('DB_PORT') ?: '3306';
        $dbname    = getenv('DB_DATABASE') ?: '';
        $username  = getenv('DB_USERNAME') ?: '';
        $password  = getenv('DB_PASSWORD') ?: '';
        $charset   = getenv('DB_CHARSET') ?: 'utf8mb4';
        $collation = getenv('DB_COLLATION') ?: 'utf8mb4_unicode_ci';

        $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset={$charset}";

        $this->pdo = new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);

        $this->pdo->exec("SET NAMES '{$charset}' COLLATE '{$collation}'");
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
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function execute(string $sql, array $params = []): bool
    {
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute($params);
    }

    public function insert(string $sql, array $params = []): int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$this->pdo->lastInsertId();
    }

    public function count(string $sql, array $params = []): int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    public function beginTransaction(): void
    {
        $this->pdo->beginTransaction();
    }

    public function commit(): void
    {
        $this->pdo->commit();
    }

    public function rollback(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    public function getPdo(): PDO
    {
        return $this->pdo;
    }

    public function lastInsertId(): int
    {
        return (int)$this->pdo->lastInsertId();
    }
}

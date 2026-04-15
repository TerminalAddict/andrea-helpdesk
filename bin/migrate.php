#!/usr/bin/env php
<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__);
require $projectRoot . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createUnsafeImmutable($projectRoot);
$dotenv->safeLoad();

$host     = getenv('DB_HOST') ?: 'localhost';
$port     = getenv('DB_PORT') ?: '3306';
$dbname   = getenv('DB_DATABASE') ?: '';
$username = getenv('DB_USERNAME') ?: '';
$password = getenv('DB_PASSWORD') ?: '';
$charset  = getenv('DB_CHARSET') ?: 'utf8mb4';

if (!$dbname || !$username) {
    echo "ERROR: DB_DATABASE and DB_USERNAME must be set in .env\n";
    exit(1);
}

try {
    $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset={$charset}";
    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    echo "Connected to database: {$dbname}\n";

    $schemaFile = $projectRoot . '/database/schema.sql';
    if (!file_exists($schemaFile)) {
        echo "ERROR: database/schema.sql not found\n";
        exit(1);
    }

    $sql = file_get_contents($schemaFile);

    // Split by semicolons, strip comment lines from each chunk
    $statements = array_filter(
        array_map(function($chunk) {
            $lines = array_filter(
                explode("\n", $chunk),
                fn($line) => !str_starts_with(ltrim($line), '--')
            );
            return trim(implode("\n", $lines));
        }, explode(';', $sql)),
        fn($s) => !empty($s)
    );

    $count = 0;
    foreach ($statements as $statement) {
        if (trim($statement) === '') continue;
        try {
            $pdo->exec($statement);
            $count++;
        } catch (PDOException $e) {
            // Skip duplicate key / already exists errors in non-strict mode
            if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
                continue;
            }
            echo "WARNING: " . $e->getMessage() . "\n";
        }
    }

    echo "Migration complete. Executed {$count} statements.\n";

    $pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
        filename   VARCHAR(255) NOT NULL PRIMARY KEY,
        applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $applied = array_column(
        $pdo->query("SELECT filename FROM schema_migrations")->fetchAll(),
        'filename'
    );

    $migrationFiles = glob($projectRoot . '/database/migrations/*.sql') ?: [];
    sort($migrationFiles);

    $migrationCount = 0;
    foreach ($migrationFiles as $file) {
        $name = basename($file);
        if ($name === '001_initial.sql' || in_array($name, $applied, true)) {
            continue;
        }

        $sql = file_get_contents($file);
        $statements = array_filter(
            array_map(function($chunk) {
                $lines = array_filter(
                    explode("\n", $chunk),
                    fn($line) => !str_starts_with(ltrim($line), '--')
                );
                return trim(implode("\n", $lines));
            }, explode(';', $sql)),
            fn($s) => !empty($s)
        );

        $inTransaction = false;
        try {
            $pdo->beginTransaction();
            $inTransaction = true;

            foreach ($statements as $statement) {
                try {
                    $pdo->exec($statement);
                } catch (PDOException $e) {
                    $code = isset($e->errorInfo[1]) ? (int)$e->errorInfo[1] : 0;
                    if (!in_array($code, [1060, 1061, 1068], true)) {
                        throw new RuntimeException("Migration {$name} failed: " . $e->getMessage(), 0, $e);
                    }
                }
            }

            if ($pdo->inTransaction()) {
                $pdo->commit();
                $inTransaction = false;
            }
        } catch (Throwable $e) {
            if ($inTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            echo "ERROR: " . $e->getMessage() . "\n";
            exit(1);
        }

        $stmt = $pdo->prepare("INSERT INTO schema_migrations (filename) VALUES (?)");
        $stmt->execute([$name]);
        $migrationCount++;
    }

    echo "Numbered migrations applied: {$migrationCount}\n";
} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}

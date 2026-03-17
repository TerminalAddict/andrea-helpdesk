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
} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}

#!/usr/bin/env php
<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__);
require $projectRoot . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable($projectRoot);
$dotenv->safeLoad();

$host     = getenv('DB_HOST') ?: 'localhost';
$port     = getenv('DB_PORT') ?: '3306';
$dbname   = getenv('DB_DATABASE') ?: '';
$username = getenv('DB_USERNAME') ?: '';
$password = getenv('DB_PASSWORD') ?: '';
$charset  = getenv('DB_CHARSET') ?: 'utf8mb4';

$adminName     = getenv('ADMIN_NAME') ?: 'Admin';
$adminEmail    = getenv('ADMIN_EMAIL') ?: '';
$adminPassword = getenv('ADMIN_PASSWORD') ?: '';

if (!$adminEmail || !$adminPassword) {
    echo "ERROR: ADMIN_EMAIL and ADMIN_PASSWORD must be set in .env\n";
    exit(1);
}

try {
    $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset={$charset}";
    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    // Check if admin already exists
    $stmt = $pdo->prepare("SELECT id FROM agents WHERE email = ?");
    $stmt->execute([$adminEmail]);
    if ($stmt->fetch()) {
        echo "Admin agent '{$adminEmail}' already exists. Skipping.\n";
        exit(0);
    }

    $hash = password_hash($adminPassword, PASSWORD_BCRYPT);

    $stmt = $pdo->prepare(
        "INSERT INTO agents (name, email, password_hash, role, can_close_tickets, can_delete_tickets, can_edit_customers, can_view_reports)
         VALUES (?, ?, ?, 'admin', 1, 1, 1, 1)"
    );
    $stmt->execute([$adminName, $adminEmail, $hash]);

    echo "Admin agent created:\n";
    echo "  Name:  {$adminName}\n";
    echo "  Email: {$adminEmail}\n";
    echo "  Role:  admin\n";
} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}

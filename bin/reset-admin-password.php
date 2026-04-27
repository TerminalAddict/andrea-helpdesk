#!/usr/bin/env php
<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__);
require $projectRoot . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createUnsafeImmutable($projectRoot);
$dotenv->safeLoad();

use Andrea\Helpdesk\Auth\PasswordService;

$host     = getenv('DB_HOST') ?: 'localhost';
$port     = getenv('DB_PORT') ?: '3306';
$dbname   = getenv('DB_DATABASE') ?: '';
$username = getenv('DB_USERNAME') ?: '';
$password = getenv('DB_PASSWORD') ?: '';
$charset  = getenv('DB_CHARSET') ?: 'utf8mb4';

function stdout(string $message): void
{
    fwrite(STDOUT, $message);
}

function stderr(string $message): void
{
    fwrite(STDERR, $message);
}

function prompt(string $message): string
{
    stdout($message);
    $value = fgets(STDIN);
    if ($value === false) {
        throw new RuntimeException('Unable to read input');
    }
    return trim($value);
}

function promptHidden(string $message): string
{
    stdout($message);
    $sttyMode = shell_exec('stty -g 2>/dev/null');
    if (is_string($sttyMode) && trim($sttyMode) !== '') {
        shell_exec('stty -echo 2>/dev/null');
    }
    try {
        $value = fgets(STDIN);
    } finally {
        if (is_string($sttyMode) && trim($sttyMode) !== '') {
            shell_exec('stty ' . escapeshellarg(trim($sttyMode)) . ' 2>/dev/null');
        }
        stdout(PHP_EOL);
    }
    if ($value === false) {
        throw new RuntimeException('Unable to read input');
    }
    return trim($value);
}

try {
    $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset={$charset}";
    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $admins = $pdo->query(
        "SELECT id, name, email, role, is_active
         FROM agents
         WHERE role = 'admin'
         ORDER BY id"
    )->fetchAll();

    if (!$admins) {
        stderr("No admin accounts were found.\n");
        exit(1);
    }

    stdout("Andrea Helpdesk - Reset Admin Password\n\n");
    stdout(str_pad('ID', 6) . str_pad('Name', 28) . str_pad('Email', 40) . str_pad('Role', 10) . "Status\n");
    stdout(str_repeat('-', 92) . "\n");
    foreach ($admins as $admin) {
        stdout(
            str_pad((string)$admin['id'], 6) .
            str_pad((string)$admin['name'], 28) .
            str_pad((string)$admin['email'], 40) .
            str_pad((string)$admin['role'], 10) .
            ((int)$admin['is_active'] === 1 ? 'Active' : 'Inactive') . "\n"
        );
    }
    stdout(PHP_EOL);

    $selectedId = (int)prompt('Select the admin ID to reset: ');
    $selected = null;
    foreach ($admins as $admin) {
        if ((int)$admin['id'] === $selectedId) {
            $selected = $admin;
            break;
        }
    }
    if (!$selected) {
        stderr("Selected admin ID was not found.\n");
        exit(1);
    }

    $passwords = new PasswordService();
    while (true) {
        $newPassword = promptHidden('Enter the new password (min 8 chars): ');
        if (!$passwords->meetsRequirements($newPassword)) {
            stderr("Password must be at least 8 characters long.\n");
            continue;
        }

        $confirmPassword = promptHidden('Confirm the new password: ');
        if ($newPassword !== $confirmPassword) {
            stderr("Passwords do not match. Try again.\n");
            continue;
        }
        break;
    }

    $hash = $passwords->hash($newPassword);

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("UPDATE agents SET password_hash = ? WHERE id = ?");
        $stmt->execute([$hash, $selectedId]);

        $stmt = $pdo->prepare("DELETE FROM refresh_tokens WHERE agent_id = ?");
        $stmt->execute([$selectedId]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    stdout("\nPassword reset successfully for admin:\n");
    stdout("  ID: {$selected['id']}\n");
    stdout("  Name: {$selected['name']}\n");
    stdout("  Email: {$selected['email']}\n");
    stdout("Existing refresh-token sessions for this admin were revoked.\n");
    exit(0);
} catch (Throwable $e) {
    stderr("ERROR: " . $e->getMessage() . "\n");
    exit(1);
}

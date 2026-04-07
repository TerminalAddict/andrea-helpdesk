#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * One-time script: add target="_blank" rel="noopener noreferrer" to all <a> tags
 * in existing replies.body_html records.
 *
 * Usage: php bin/fix-link-targets.php [--dry-run]
 */

$projectRoot = dirname(__DIR__);
require $projectRoot . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createUnsafeImmutable($projectRoot);
$dotenv->safeLoad();

$dryRun = in_array('--dry-run', $argv, true);

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

$dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset={$charset}";
$pdo = new PDO($dsn, $username, $password, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
]);

echo "Connected to {$dbname}\n";
if ($dryRun) echo "(dry-run mode — no changes will be written)\n";

function addLinkTargets(string $html): string
{
    if (!trim($html)) return $html;

    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML(
        '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>' . $html . '</body></html>',
        LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
    );
    libxml_clear_errors();

    foreach ($doc->getElementsByTagName('a') as $a) {
        $a->setAttribute('target', '_blank');
        $a->setAttribute('rel', 'noopener noreferrer');
    }

    $body = $doc->getElementsByTagName('body')->item(0);
    if (!$body) return $html;

    $result = '';
    foreach ($body->childNodes as $child) {
        $result .= $doc->saveHTML($child);
    }
    return $result;
}

$updated = 0;
$skipped = 0;

$rows = $pdo->query("SELECT id, body_html FROM replies WHERE body_html IS NOT NULL AND body_html != ''")->fetchAll();
echo "Processing " . count($rows) . " replies...\n";

foreach ($rows as $row) {
    $fixed = addLinkTargets($row['body_html']);
    if ($fixed === $row['body_html']) {
        $skipped++;
        continue;
    }
    if (!$dryRun) {
        $stmt = $pdo->prepare("UPDATE replies SET body_html = ? WHERE id = ?");
        $stmt->execute([$fixed, $row['id']]);
    }
    $updated++;
}

echo "Done. Updated: {$updated}, unchanged: {$skipped}\n";

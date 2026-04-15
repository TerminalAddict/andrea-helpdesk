#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Export a helpdesk snapshot payload for /public_html/test/.
 *
 * Usage:
 *   php bin/export-theme-snapshot.php
 *   php bin/export-theme-snapshot.php --output=/path/to/live-snapshot.json
 *
 * The output is written to /public_html/test/live-snapshot.json by default.
 */

const DEFAULT_OUTPUT = __DIR__ . '/../public_html/test/live-snapshot.json';
const MAX_LIMIT = 500;

$projectRoot = dirname(__DIR__);
require $projectRoot . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createUnsafeImmutable($projectRoot);
$dotenv->safeLoad();

$host      = getenv('DB_HOST') ?: 'localhost';
$port      = getenv('DB_PORT') ?: '3306';
$dbname    = getenv('DB_DATABASE') ?: '';
$username  = getenv('DB_USERNAME') ?: '';
$password  = getenv('DB_PASSWORD') ?: '';
$charset   = getenv('DB_CHARSET') ?: 'utf8mb4';
$collation = getenv('DB_COLLATION') ?: 'utf8mb4_unicode_ci';

$ticketLimit   = (int)(getenv('THEME_SNAPSHOT_TICKETS') ?: 250);
$customerLimit = (int)(getenv('THEME_SNAPSHOT_CUSTOMERS') ?: 200);
$agentLimit    = (int)(getenv('THEME_SNAPSHOT_AGENTS') ?: 120);
$activityLimit = (int)(getenv('THEME_SNAPSHOT_ACTIVITY') ?: 120);
$calendarLimit = (int)(getenv('THEME_SNAPSHOT_CALENDAR') ?: 160);

$ticketLimit   = max(1, min(MAX_LIMIT, $ticketLimit));
$customerLimit = max(1, min(MAX_LIMIT, $customerLimit));
$agentLimit    = max(1, min(MAX_LIMIT, $agentLimit));
$activityLimit = max(1, min(MAX_LIMIT, $activityLimit));
$calendarLimit = max(1, min(MAX_LIMIT, $calendarLimit));

$output = DEFAULT_OUTPUT;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--output=')) {
        $output = substr($arg, 9);
    }
}

if (!$dbname || !$username) {
    fwrite(STDERR, "ERROR: DB_DATABASE and DB_USERNAME must be set in .env (or environment).\n");
    exit(1);
}

try {
    $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset={$charset}";
    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    $pdo->exec("SET NAMES '{$charset}' COLLATE '{$collation}'");
} catch (PDOException $e) {
    fwrite(STDERR, "ERROR: Database connection failed: " . $e->getMessage() . "\n");
    exit(1);
}

try {
    $summary = $pdo->query(
        "SELECT
            COUNT(*) AS tickets_total,
            SUM(status NOT IN ('resolved', 'closed')) AS tickets_open,
            SUM(priority = 'overdue') AS tickets_overdue,
            (SELECT COUNT(*) FROM customers WHERE deleted_at IS NULL) AS customers_total,
            (SELECT COUNT(*) FROM agents WHERE is_active = 1) AS agents_active
         FROM tickets
         WHERE deleted_at IS NULL"
    )->fetch();

    $stmtTickets = $pdo->prepare(
        "SELECT
            t.ticket_number,
            t.subject,
            t.priority,
            t.status,
            t.channel,
            t.last_attention_at,
            t.created_at,
            t.updated_at,
            t.due_at,
            t.due_end,
            t.assigned_agent_id,
            c.name AS customer_name,
            c.email AS customer_email,
            a.name AS agent_name,
            COALESCE(r.replies, 0) AS reply_count,
            COALESCE(att.attachments, 0) AS attachment_count
         FROM tickets t
         LEFT JOIN customers c ON c.id = t.customer_id
         LEFT JOIN agents a ON a.id = t.assigned_agent_id
         LEFT JOIN (
             SELECT ticket_id, COUNT(*) AS replies
             FROM replies
             WHERE author_type != 'system'
             GROUP BY ticket_id
         ) r ON r.ticket_id = t.id
         LEFT JOIN (
             SELECT ticket_id, COUNT(*) AS attachments
             FROM attachments
             GROUP BY ticket_id
         ) att ON att.ticket_id = t.id
         WHERE t.deleted_at IS NULL
         ORDER BY t.updated_at DESC
         LIMIT :limit"
    );
    $stmtTickets->bindValue(':limit', $ticketLimit, PDO::PARAM_INT);
    $stmtTickets->execute();
    $tickets = $stmtTickets->fetchAll();

    $stmtCustomers = $pdo->prepare(
        "SELECT
            c.id,
            c.name,
            c.email,
            c.company,
            c.phone,
            c.created_at,
            COALESCE(t.last_ticket_at, c.created_at) AS last_ticket_at,
            COALESCE(t.open_tickets, 0) AS open_tickets,
            COALESCE(t.ticket_count, 0) AS ticket_count
         FROM customers c
         LEFT JOIN (
             SELECT
                 customer_id,
                 MAX(created_at) AS last_ticket_at,
                 SUM(status NOT IN ('resolved', 'closed')) AS open_tickets,
                 COUNT(*) AS ticket_count
             FROM tickets
             WHERE deleted_at IS NULL
             GROUP BY customer_id
         ) t ON t.customer_id = c.id
         WHERE c.deleted_at IS NULL
         ORDER BY c.name ASC, c.id ASC
         LIMIT :limit"
    );
    $stmtCustomers->bindValue(':limit', $customerLimit, PDO::PARAM_INT);
    $stmtCustomers->execute();
    $customers = $stmtCustomers->fetchAll();

    $stmtAgents = $pdo->prepare(
        "SELECT
            a.id,
            a.name,
            a.email,
            a.role,
            a.theme,
            a.last_login_at,
            a.is_active,
            COALESCE(aStats.total_assigned, 0) AS total_assigned,
            COALESCE(aStats.active_tickets, 0) AS active_tickets,
            COALESCE(aStats.overdue_tickets, 0) AS overdue_tickets
         FROM agents a
         LEFT JOIN (
             SELECT
                 assigned_agent_id,
                 COUNT(*) AS total_assigned,
                 SUM(status NOT IN ('resolved', 'closed')) AS active_tickets,
                 SUM(priority = 'overdue') AS overdue_tickets
             FROM tickets
             WHERE deleted_at IS NULL
             GROUP BY assigned_agent_id
         ) aStats ON aStats.assigned_agent_id = a.id
         WHERE a.is_active = 1
         ORDER BY a.name ASC, a.id ASC
         LIMIT :limit"
    );
    $stmtAgents->bindValue(':limit', $agentLimit, PDO::PARAM_INT);
    $stmtAgents->execute();
    $agents = $stmtAgents->fetchAll();

    $stmtActivity = $pdo->prepare(
        "SELECT
            r.created_at,
            COALESCE(a.name, c.name, 'System') AS actor,
            CASE
                WHEN r.author_type = 'agent' THEN 'Agent reply'
                WHEN r.author_type = 'customer' THEN 'Customer reply'
                ELSE 'System event'
            END AS action,
            t.ticket_number,
            t.priority,
            t.status
         FROM replies r
         JOIN tickets t ON t.id = r.ticket_id
         LEFT JOIN agents a ON a.id = r.agent_id
         LEFT JOIN customers c ON c.id = r.customer_id
         WHERE t.deleted_at IS NULL
         ORDER BY r.created_at DESC
         LIMIT :limit"
    );
    $stmtActivity->bindValue(':limit', $activityLimit, PDO::PARAM_INT);
    $stmtActivity->execute();
    $activity = $stmtActivity->fetchAll();

    $stmtCalendar = $pdo->prepare(
        "SELECT
            t.ticket_number,
            t.subject,
            t.priority,
            t.status,
            COALESCE(t.due_at, t.due_end, t.created_at) AS event_date,
            c.name AS customer_name,
            a.name AS agent_name
         FROM tickets t
         JOIN customers c ON c.id = t.customer_id
         LEFT JOIN agents a ON a.id = t.assigned_agent_id
         WHERE t.deleted_at IS NULL
         ORDER BY event_date DESC
         LIMIT :limit"
    );
    $stmtCalendar->bindValue(':limit', $calendarLimit, PDO::PARAM_INT);
    $stmtCalendar->execute();
    $calendar = $stmtCalendar->fetchAll();

function castCounts(array $rows): array
{
    return array_map(static function(array $item): array {
        foreach (['reply_count', 'attachment_count', 'open_tickets', 'ticket_count', 'total_assigned', 'active_tickets', 'overdue_tickets', 'is_active'] as $numericKey) {
            if (array_key_exists($numericKey, $item)) {
                $item[$numericKey] = (int)$item[$numericKey];
            }
        }
        return $item;
    }, $rows);
}

$payload = [
    'generated_at' => date('Y-m-d H:i:s'),
    'summary' => [
        'tickets_total'   => (int)($summary['tickets_total']   ?? 0),
        'tickets_open'    => (int)($summary['tickets_open']    ?? 0),
        'tickets_overdue' => (int)($summary['tickets_overdue'] ?? 0),
        'customers_total' => (int)($summary['customers_total'] ?? 0),
        'agents_active'   => (int)($summary['agents_active']   ?? 0),
    ],
    'tickets'   => castCounts($tickets),
    'customers' => castCounts($customers),
    'agents'    => castCounts($agents),
    'activity'  => $activity,
    'calendar'  => $calendar,
];

    if (!is_dir(dirname($output))) {
        mkdir(dirname($output), 0755, true);
    }
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        fwrite(STDERR, "ERROR: Failed to encode snapshot JSON.\n");
        exit(1);
    }
    file_put_contents($output, $json . "\n");

    echo "Snapshot written to {$output}\n";
    echo "Rows exported - tickets: " . count($payload['tickets']) .
         ", customers: " . count($payload['customers']) .
         ", agents: " . count($payload['agents']) .
         ", activity rows: " . count($payload['activity']) .
         ", calendar rows: " . count($payload['calendar']) . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    exit(1);
}

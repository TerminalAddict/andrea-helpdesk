<?php
declare(strict_types=1);
session_start();

// ── Paths ─────────────────────────────────────────────────────────────────────
define('ROOT',        realpath(__DIR__ . '/../../'));
define('LOCK_FILE',   ROOT . '/install.lock');
define('ENV_FILE',    ROOT . '/.env');
define('SCHEMA_FILE', ROOT . '/database/schema.sql');
define('VENDOR_DIR',  ROOT . '/public_html/assets/vendor');
define('PHP_VENDOR',  ROOT . '/vendor/autoload.php');

const BOOTSTRAP_VERSION       = '5.3.8';
const BOOTSTRAP_ICONS_VERSION = '1.13.1';
const JQUERY_VERSION          = '4.0.0';
const DOMPURIFY_VERSION       = '3.2.4';

// ── Already installed? ────────────────────────────────────────────────────────
if (file_exists(LOCK_FILE) || file_exists(ENV_FILE)) {
    render('already_installed');
    exit;
}

// ── Step routing ──────────────────────────────────────────────────────────────
$step   = (int)($_POST['step'] ?? $_GET['step'] ?? 1);
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if ($step === 2) {
        $_SESSION['db'] = [
            'host'     => trim($_POST['db_host'] ?? 'localhost'),
            'port'     => (int)($_POST['db_port'] ?? 3306),
            'database' => trim($_POST['db_database'] ?? ''),
            'username' => trim($_POST['db_username'] ?? ''),
            'password' => $_POST['db_password'] ?? '',
        ];
        $err = testDb($_SESSION['db']);
        if ($err) {
            $errors[] = $err;
        } else {
            $step = 3;
        }

    } elseif ($step === 3) {
        $_SESSION['app'] = [
            'url'            => rtrim(trim($_POST['app_url'] ?? ''), '/'),
            'timezone'       => trim($_POST['app_timezone'] ?? 'UTC'),
            'storage_path'   => rtrim(trim($_POST['storage_path'] ?? ''), '/'),
            'jwt_secret'     => bin2hex(random_bytes(32)),
            'admin_name'     => trim($_POST['admin_name'] ?? ''),
            'admin_email'    => strtolower(trim($_POST['admin_email'] ?? '')),
            'admin_password' => $_POST['admin_password'] ?? '',
        ];
        $errors = validateApp($_SESSION['app'], $_POST['admin_password_confirm'] ?? '');
        if (empty($errors)) {
            $step = 4;
        }

    } elseif ($step === 4) {
        $results = runInstall($_SESSION['db'] ?? [], $_SESSION['app'] ?? []);
        $step    = 5;
    }
}

render($step === 5 ? 'done' : "step{$step}", $errors, $results ?? []);

// ── Helpers ───────────────────────────────────────────────────────────────────

function testDb(array $db): string
{
    if (empty($db['database'])) return 'Database name is required.';
    if (empty($db['username'])) return 'Database username is required.';
    try {
        $dsn = "mysql:host={$db['host']};port={$db['port']};dbname={$db['database']};charset=utf8mb4";
        new PDO($dsn, $db['username'], $db['password'], [PDO::ATTR_TIMEOUT => 5]);
        return '';
    } catch (\PDOException $e) {
        return 'Connection failed: ' . $e->getMessage();
    }
}

function validateApp(array $app, string $confirm): array
{
    $errors = [];
    if (empty($app['url']))           $errors[] = 'App URL is required.';
    if (empty($app['storage_path']))  $errors[] = 'Storage path is required.';
    if (empty($app['admin_name']))    $errors[] = 'Admin name is required.';
    if (!filter_var($app['admin_email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid admin email address is required.';
    if (strlen($app['admin_password']) < 8)  $errors[] = 'Admin password must be at least 8 characters.';
    if ($app['admin_password'] !== $confirm) $errors[] = 'Admin passwords do not match.';
    return $errors;
}

function getRequirements(): array
{
    $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
    $canExec  = function_exists('exec') && !in_array('exec', $disabled, true);
    $canDl    = function_exists('curl_init') || ini_get('allow_url_fopen');

    return [
        ['PHP ≥ 8.1',                     version_compare(PHP_VERSION, '8.1.0', '>='), PHP_VERSION],
        ['ext-pdo_mysql',                 extension_loaded('pdo_mysql'),  ''],
        ['ext-imap',                      extension_loaded('imap'),        ''],
        ['ext-mbstring',                  extension_loaded('mbstring'),    ''],
        ['ext-openssl',                   extension_loaded('openssl'),     ''],
        ['curl or allow_url_fopen',       $canDl,   $canDl ? 'available' : 'not available — asset download will fail'],
        ['database/schema.sql exists',    file_exists(SCHEMA_FILE), ''],
        ['Project root writable',         is_writable(ROOT),        ROOT],
        ['assets/vendor/ parent writable',is_writable(dirname(VENDOR_DIR)) || is_writable(ROOT . '/public_html'),
                                          VENDOR_DIR],
        ['exec() available (Composer)',   $canExec, $canExec ? 'yes' : 'no — upload vendor/ manually if needed'],
    ];
}

function allPassed(array $reqs): bool
{
    // exec() and imap are warnings, not blockers
    $optional = ['exec() available (Composer)', 'ext-imap'];
    foreach ($reqs as [$label, $ok]) {
        if (!$ok && !in_array($label, $optional, true)) return false;
    }
    return true;
}

function runInstall(array $db, array $app): array
{
    $results = [];
    $pdo     = null;

    // 1. Create storage directories
    foreach ([
        $app['storage_path'],
        $app['storage_path'] . '/attachments',
        $app['storage_path'] . '/logs',
    ] as $dir) {
        if (is_dir($dir)) {
            $results[] = ['skip', "Directory exists: {$dir}"];
        } elseif (mkdir($dir, 0775, true)) {
            $results[] = ['ok', "Created: {$dir}"];
        } else {
            $results[] = ['error', "Could not create: {$dir}"];
            return $results;
        }
    }
    foreach (['app.log', 'imap.log'] as $log) {
        $f = $app['storage_path'] . '/logs/' . $log;
        if (!file_exists($f)) touch($f);
    }

    // 2. Write .env
    $app['_date'] = date('Y-m-d H:i:s');
    $env = buildEnv($db, $app);
    if (file_put_contents(ENV_FILE, $env) !== false) {
        $results[] = ['ok', 'Created .env'];
    } else {
        $results[] = ['error', 'Failed to write .env — check directory permissions'];
        return $results;
    }

    // 3. Run DB migrations
    try {
        $dsn = "mysql:host={$db['host']};port={$db['port']};dbname={$db['database']};charset=utf8mb4";
        $pdo = new \PDO($dsn, $db['username'], $db['password'], [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);
        $sql        = (string)file_get_contents(SCHEMA_FILE);
        $statements = array_filter(array_map('trim', splitSql($sql)));
        foreach ($statements as $stmt) {
            $pdo->exec($stmt);
        }
        $results[] = ['ok', 'Database schema created'];
    } catch (\Throwable $e) {
        $results[] = ['error', 'Migration failed: ' . $e->getMessage()];
        return $results;
    }

    // 4. Seed admin account
    try {
        $hash = password_hash($app['admin_password'], PASSWORD_BCRYPT, ['cost' => 12]);
        $stmt = $pdo->prepare(
            "INSERT INTO agents (name, email, password_hash, role,
                                 can_close_tickets, can_delete_tickets,
                                 can_edit_customers, can_view_reports,
                                 can_manage_kb, can_manage_tags)
             VALUES (?, ?, ?, 'admin', 1, 1, 1, 1, 1, 1)
             ON DUPLICATE KEY UPDATE role = 'admin', password_hash = VALUES(password_hash)"
        );
        $stmt->execute([$app['admin_name'], $app['admin_email'], $hash]);

        // Sync timezone into settings table
        if (!empty($app['timezone'])) {
            $pdo->prepare("UPDATE settings SET value = ? WHERE key_name = 'timezone'")
                ->execute([$app['timezone']]);
        }
        $results[] = ['ok', "Admin account created: {$app['admin_email']}"];
    } catch (\Throwable $e) {
        $results[] = ['error', 'Admin seed failed: ' . $e->getMessage()];
    }

    // 5. Download frontend assets
    foreach (downloadAssets() as $r) {
        $results[] = $r;
    }

    // 6. Composer
    $results[] = installComposer();

    // 7. Write lock file — prevents installer re-running
    file_put_contents(LOCK_FILE, date('Y-m-d H:i:s') . "\n");
    $results[] = ['ok', 'Install lock written'];

    return $results;
}

function buildEnv(array $db, array $app): string
{
    $q = fn(string $v) => '"' . addslashes($v) . '"';
    return <<<ENV
# ============================================================
# Andrea Helpdesk — Environment Configuration
# Generated by web installer on {$app['_date']}
# ============================================================

# Application
APP_ENV=production
APP_DEBUG=false
APP_URL={$app['url']}
APP_TIMEZONE={$app['timezone']}

# Security
JWT_SECRET={$app['jwt_secret']}
JWT_ACCESS_TTL=900
JWT_REFRESH_TTL=2592000

# Database
DB_HOST={$db['host']}
DB_PORT={$db['port']}
DB_DATABASE={$db['database']}
DB_USERNAME={$db['username']}
DB_PASSWORD={$q($db['password'])}
DB_CHARSET=utf8mb4
DB_COLLATION=utf8mb4_unicode_ci

# Storage (absolute path, outside public_html)
STORAGE_PATH={$app['storage_path']}
MAX_ATTACHMENT_SIZE=10485760

# Admin Account (used by: make db-seed)
ADMIN_NAME={$q($app['admin_name'])}
ADMIN_EMAIL={$app['admin_email']}
ADMIN_PASSWORD=
ENV;
}

function splitSql(string $sql): array
{
    // Strip line comments
    $sql = preg_replace('/--[^\n]*/', '', $sql) ?? $sql;
    // Strip block comments
    $sql = preg_replace('/\/\*.*?\*\//s', '', $sql) ?? $sql;
    return explode(';', $sql);
}

function downloadAssets(): array
{
    $results = [];
    $v       = VENDOR_DIR;
    $assets  = [
        ["https://cdn.jsdelivr.net/npm/bootstrap@" . BOOTSTRAP_VERSION . "/dist/css/bootstrap.min.css",
         "{$v}/bootstrap/bootstrap.min.css"],
        ["https://cdn.jsdelivr.net/npm/bootstrap@" . BOOTSTRAP_VERSION . "/dist/js/bootstrap.bundle.min.js",
         "{$v}/bootstrap/bootstrap.bundle.min.js"],
        ["https://cdn.jsdelivr.net/npm/bootstrap-icons@" . BOOTSTRAP_ICONS_VERSION . "/font/bootstrap-icons.min.css",
         "{$v}/bootstrap-icons/bootstrap-icons.min.css"],
        ["https://cdn.jsdelivr.net/npm/bootstrap-icons@" . BOOTSTRAP_ICONS_VERSION . "/font/fonts/bootstrap-icons.woff2",
         "{$v}/bootstrap-icons/fonts/bootstrap-icons.woff2"],
        ["https://cdn.jsdelivr.net/npm/bootstrap-icons@" . BOOTSTRAP_ICONS_VERSION . "/font/fonts/bootstrap-icons.woff",
         "{$v}/bootstrap-icons/fonts/bootstrap-icons.woff"],
        ["https://code.jquery.com/jquery-" . JQUERY_VERSION . ".min.js",
         "{$v}/jquery/jquery.min.js"],
        ["https://cdn.jsdelivr.net/npm/dompurify@" . DOMPURIFY_VERSION . "/dist/purify.min.js",
         "{$v}/dompurify/purify.min.js"],
    ];

    foreach ($assets as [$url, $dest]) {
        $dir = dirname($dest);
        if (!is_dir($dir)) mkdir($dir, 0775, true);
        if (file_exists($dest)) {
            $results[] = ['skip', 'Asset already present: ' . basename($dest)];
            continue;
        }
        $data = fetchUrl($url);
        if ($data === false) {
            $results[] = ['warning', 'Could not download: ' . basename($dest) . " — fetch manually from {$url}"];
        } else {
            file_put_contents($dest, $data);
            $results[] = ['ok', 'Downloaded: ' . basename($dest)];
        }
    }
    return $results;
}

function installComposer(): array
{
    if (file_exists(PHP_VENDOR)) {
        return ['skip', 'vendor/ directory already exists — skipping Composer'];
    }

    $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
    if (!function_exists('exec') || in_array('exec', $disabled, true)) {
        return ['warning', 'exec() is disabled — please upload the vendor/ directory or run "composer install --no-dev" manually on the server'];
    }

    $phar = sys_get_temp_dir() . '/composer.phar';
    $data = fetchUrl('https://getcomposer.org/composer-stable.phar');
    if ($data === false) {
        return ['warning', 'Could not download Composer.phar — please run "composer install --no-dev" manually'];
    }
    file_put_contents($phar, $data);

    $root = escapeshellarg(ROOT);
    exec("php " . escapeshellarg($phar) . " install --no-dev --optimize-autoloader --working-dir={$root} 2>&1", $out, $code);

    if ($code !== 0) {
        return ['warning', 'Composer returned errors — please run "composer install --no-dev" manually. Output: ' . implode(' ', array_slice($out, -3))];
    }
    return ['ok', 'Composer dependencies installed'];
}

function fetchUrl(string $url): string|false
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'Andrea-Helpdesk-Installer/1.0',
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($body !== false && $code === 200) ? $body : false;
    }
    if (ini_get('allow_url_fopen')) {
        return @file_get_contents($url) ?: false;
    }
    return false;
}

function e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function guessAppUrl(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host;
}

function guessStoragePath(): string
{
    // Suggest a sibling of the project root, outside the web root
    return dirname(ROOT) . '/andrea-helpdesk-storage';
}

// ── View renderer ─────────────────────────────────────────────────────────────
function render(string $view, array $errors = [], array $results = []): void
{
    $reqs    = ($view === 'step1') ? getRequirements() : [];
    $allOk   = $reqs ? allPassed($reqs) : true;
    $db      = $_SESSION['db'] ?? [];
    $app     = $_SESSION['app'] ?? [];
    $step    = match($view) { 'step1' => 1, 'step2' => 2, 'step3' => 3, 'step4' => 4, 'done' => 5, default => 0 };
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Andrea Helpdesk — Installer</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@<?= BOOTSTRAP_VERSION ?>/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@<?= BOOTSTRAP_ICONS_VERSION ?>/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body { background: #f0f2f5; }
        .installer-wrap { max-width: 680px; margin: 2.5rem auto 4rem; }
        .step-bar { display: flex; align-items: center; margin-bottom: 2rem; }
        .step-dot { width: 34px; height: 34px; border-radius: 50%; display: flex; align-items: center;
                    justify-content: center; font-size: .8rem; font-weight: 700; flex-shrink: 0; }
        .step-dot.done    { background: #198754; color: #fff; }
        .step-dot.active  { background: #0d6efd; color: #fff; box-shadow: 0 0 0 4px rgba(13,110,253,.2); }
        .step-dot.pending { background: #dee2e6; color: #6c757d; }
        .step-line { flex: 1; height: 2px; background: #dee2e6; margin: 0 6px; }
        .step-line.done { background: #198754; }
        .result-row { padding: .35rem .8rem; border-radius: .375rem; font-size: .875rem; margin-bottom: .3rem; }
        .result-row.ok      { background: #d1e7dd; color: #0a3622; }
        .result-row.skip    { background: #e9ecef; color: #495057; }
        .result-row.warning { background: #fff3cd; color: #664d03; }
        .result-row.error   { background: #f8d7da; color: #58151c; }
    </style>
</head>
<body>
<div class="container installer-wrap">

    <div class="text-center mb-4">
        <i class="bi bi-headset text-primary" style="font-size:3rem;"></i>
        <h3 class="fw-bold mt-2 mb-0">Andrea Helpdesk</h3>
        <p class="text-muted small mb-0">Installation Wizard</p>
    </div>

    <?php if ($view === 'already_installed'): ?>
    <div class="card shadow-sm border-0">
        <div class="card-body text-center py-5">
            <i class="bi bi-lock-fill text-warning" style="font-size:3rem;"></i>
            <h4 class="mt-3 fw-bold">Already Installed</h4>
            <p class="text-muted">Andrea Helpdesk has already been set up on this server.<br>
               The installer cannot be run again.</p>
            <p class="small text-muted">If you need to reinstall, remove the <code>.env</code> and <code>install.lock</code> files from the project root first.<br>
               <strong>Warning:</strong> this will reset all configuration.</p>
            <a href="/" class="btn btn-primary mt-2"><i class="bi bi-arrow-left me-1"></i>Go to Helpdesk</a>
        </div>
    </div>

    <?php else: ?>

    <?php if ($step >= 1): ?>
    <div class="step-bar mb-4">
        <?php
        $steps = ['Requirements', 'Database', 'Settings', 'Install', 'Done'];
        foreach ($steps as $i => $label):
            $n = $i + 1;
            $cls = $n < $step ? 'done' : ($n === $step ? 'active' : 'pending');
            $icon = $n < $step ? '<i class="bi bi-check-lg"></i>' : $n;
        ?>
        <div class="text-center" style="flex: <?= $n < count($steps) ? '1' : '0' ?>; min-width: 34px;">
            <div class="step-dot <?= $cls ?> mx-auto"><?= $icon ?></div>
            <div class="small mt-1 <?= $cls === 'active' ? 'fw-semibold text-primary' : 'text-muted' ?>" style="font-size:.72rem;"><?= $label ?></div>
        </div>
        <?php if ($n < count($steps)): ?>
        <div class="step-line <?= $n < $step ? 'done' : '' ?>" style="margin-bottom:1.2rem;"></div>
        <?php endif; ?>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
        <i class="bi bi-exclamation-triangle-fill me-2"></i>
        <?php foreach ($errors as $err): ?><?= e($err) ?><br><?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php /* ── STEP 1: Requirements ─────────────────────────────────────── */ ?>
    <?php if ($view === 'step1'): ?>
    <div class="card shadow-sm border-0">
        <div class="card-header bg-white fw-semibold py-3">
            <i class="bi bi-clipboard-check me-2 text-primary"></i>System Requirements
        </div>
        <div class="card-body">
            <table class="table table-sm mb-3">
                <tbody>
                <?php foreach ($reqs as [$label, $ok, $note]):
                    $optional = in_array($label, ['exec() available (Composer)', 'ext-imap'], true);
                    $icon  = $ok ? '<i class="bi bi-check-circle-fill text-success"></i>'
                                 : ($optional ? '<i class="bi bi-exclamation-circle-fill text-warning"></i>'
                                              : '<i class="bi bi-x-circle-fill text-danger"></i>');
                ?>
                <tr>
                    <td width="16"><?= $icon ?></td>
                    <td><?= e($label) ?></td>
                    <td class="text-muted small"><?= e($note) ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php if ($allOk): ?>
            <form method="post">
                <input type="hidden" name="step" value="2">
                <button class="btn btn-primary w-100">Continue <i class="bi bi-arrow-right ms-1"></i></button>
            </form>
            <?php else: ?>
            <div class="alert alert-danger mb-0">
                <i class="bi bi-x-circle-fill me-2"></i>
                Please resolve the failed requirements above before continuing.
            </div>
            <?php endif; ?>
        </div>
    </div>

    <?php /* ── STEP 2: Database ──────────────────────────────────────────── */ ?>
    <?php elseif ($view === 'step2'): ?>
    <div class="card shadow-sm border-0">
        <div class="card-header bg-white fw-semibold py-3">
            <i class="bi bi-database me-2 text-primary"></i>Database Connection
        </div>
        <div class="card-body">
            <form method="post">
                <input type="hidden" name="step" value="2">
                <div class="row g-3">
                    <div class="col-md-8">
                        <label class="form-label">Host</label>
                        <input type="text" class="form-control" name="db_host"
                               value="<?= e($db['host'] ?? 'localhost') ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Port</label>
                        <input type="number" class="form-control" name="db_port"
                               value="<?= e((string)($db['port'] ?? '3306')) ?>" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Database Name</label>
                        <input type="text" class="form-control" name="db_database"
                               value="<?= e($db['database'] ?? '') ?>" required placeholder="helpdesk">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Username</label>
                        <input type="text" class="form-control" name="db_username"
                               value="<?= e($db['username'] ?? '') ?>" required autocomplete="off">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Password</label>
                        <input type="password" class="form-control" name="db_password"
                               value="<?= e($db['password'] ?? '') ?>" autocomplete="new-password">
                    </div>
                </div>
                <div class="mt-4">
                    <button class="btn btn-primary w-100">Test &amp; Continue <i class="bi bi-arrow-right ms-1"></i></button>
                </div>
            </form>
        </div>
    </div>

    <?php /* ── STEP 3: App Settings ───────────────────────────────────────── */ ?>
    <?php elseif ($view === 'step3'): ?>
    <div class="card shadow-sm border-0">
        <div class="card-header bg-white fw-semibold py-3">
            <i class="bi bi-gear me-2 text-primary"></i>Application Settings
        </div>
        <div class="card-body">
            <form method="post">
                <input type="hidden" name="step" value="3">
                <h6 class="text-muted text-uppercase small fw-semibold mb-3">General</h6>
                <div class="row g-3 mb-4">
                    <div class="col-12">
                        <label class="form-label">App URL <span class="text-danger">*</span></label>
                        <input type="url" class="form-control" name="app_url"
                               value="<?= e($app['url'] ?? guessAppUrl()) ?>" required
                               placeholder="https://support.yourdomain.com">
                        <div class="form-text">The public URL of this helpdesk, without a trailing slash.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Timezone</label>
                        <input type="text" class="form-control" name="app_timezone"
                               value="<?= e($app['timezone'] ?? date_default_timezone_get()) ?>"
                               placeholder="Pacific/Auckland">
                        <div class="form-text">PHP timezone identifier.</div>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Storage Path <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="storage_path"
                               value="<?= e($app['storage_path'] ?? guessStoragePath()) ?>" required>
                        <div class="form-text">Absolute server path <strong>outside</strong> public_html — for attachments and logs. Will be created if it doesn't exist.</div>
                    </div>
                </div>

                <h6 class="text-muted text-uppercase small fw-semibold mb-3">Admin Account</h6>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="admin_name"
                               value="<?= e($app['admin_name'] ?? '') ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Email <span class="text-danger">*</span></label>
                        <input type="email" class="form-control" name="admin_email"
                               value="<?= e($app['admin_email'] ?? '') ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Password <span class="text-danger">*</span></label>
                        <input type="password" class="form-control" name="admin_password" required
                               autocomplete="new-password" minlength="8">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Confirm Password <span class="text-danger">*</span></label>
                        <input type="password" class="form-control" name="admin_password_confirm" required
                               autocomplete="new-password">
                    </div>
                </div>

                <div class="mt-4">
                    <button class="btn btn-primary w-100">Review &amp; Install <i class="bi bi-arrow-right ms-1"></i></button>
                </div>
            </form>
        </div>
    </div>

    <?php /* ── STEP 4: Confirm & run ─────────────────────────────────────── */ ?>
    <?php elseif ($view === 'step4'): ?>
    <div class="card shadow-sm border-0">
        <div class="card-header bg-white fw-semibold py-3">
            <i class="bi bi-rocket-takeoff me-2 text-primary"></i>Ready to Install
        </div>
        <div class="card-body">
            <table class="table table-sm mb-4">
                <tr><th class="text-muted fw-normal" width="140">App URL</th><td><?= e($app['url'] ?? '') ?></td></tr>
                <tr><th class="text-muted fw-normal">Timezone</th><td><?= e($app['timezone'] ?? '') ?></td></tr>
                <tr><th class="text-muted fw-normal">Storage Path</th><td><?= e($app['storage_path'] ?? '') ?></td></tr>
                <tr><th class="text-muted fw-normal">Database</th><td><?= e(($db['username'] ?? '') . '@' . ($db['host'] ?? '') . '/' . ($db['database'] ?? '')) ?></td></tr>
                <tr><th class="text-muted fw-normal">Admin Email</th><td><?= e($app['admin_email'] ?? '') ?></td></tr>
            </table>
            <form method="post">
                <input type="hidden" name="step" value="4">
                <button class="btn btn-success w-100 btn-lg">
                    <i class="bi bi-play-fill me-1"></i>Run Installation
                </button>
            </form>
        </div>
    </div>

    <?php /* ── STEP 5: Done ──────────────────────────────────────────────── */ ?>
    <?php elseif ($view === 'done'): ?>
    <?php
    $hasErrors   = count(array_filter($results, fn($r) => $r[0] === 'error'));
    $hasWarnings = count(array_filter($results, fn($r) => $r[0] === 'warning'));
    ?>
    <div class="card shadow-sm border-0">
        <div class="card-header bg-white fw-semibold py-3">
            <i class="bi bi-terminal me-2 text-primary"></i>Installation Log
        </div>
        <div class="card-body">
            <?php foreach ($results as [$type, $msg]): ?>
            <div class="result-row <?= e($type) ?>">
                <?php if ($type === 'ok'):      ?><i class="bi bi-check-circle-fill me-2"></i>
                <?php elseif ($type === 'skip'):    ?><i class="bi bi-dash-circle me-2 text-secondary"></i>
                <?php elseif ($type === 'warning'): ?><i class="bi bi-exclamation-triangle-fill me-2"></i>
                <?php elseif ($type === 'error'):   ?><i class="bi bi-x-circle-fill me-2"></i>
                <?php endif; ?>
                <?= e($msg) ?>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="card-footer bg-white">
            <?php if ($hasErrors): ?>
            <div class="alert alert-danger mb-3">
                <i class="bi bi-x-circle-fill me-2"></i>
                Installation encountered errors. Review the log above and correct the issues before retrying.<br>
                Delete <code>.env</code> and <code>install.lock</code> from the project root to run the installer again.
            </div>
            <?php else: ?>
            <div class="alert <?= $hasWarnings ? 'alert-warning' : 'alert-success' ?> mb-3">
                <i class="bi bi-<?= $hasWarnings ? 'exclamation-triangle-fill' : 'check-circle-fill' ?> me-2"></i>
                <?php if ($hasWarnings): ?>
                    Installation completed with warnings. Review the log above — some steps may need manual attention.
                <?php else: ?>
                    Installation complete! Andrea Helpdesk is ready to use.
                <?php endif; ?>
            </div>
            <div class="alert alert-warning mb-3">
                <i class="bi bi-shield-exclamation me-2"></i>
                <strong>Security:</strong> Delete or restrict access to the <code>public_html/install/</code> directory now that installation is complete.
            </div>
            <a href="<?= e(($app['url'] ?? '') ?: '/') ?>" class="btn btn-primary w-100">
                <i class="bi bi-headset me-2"></i>Open Andrea Helpdesk
            </a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php endif; // not already_installed ?>

</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@<?= BOOTSTRAP_VERSION ?>/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php
} // end render()

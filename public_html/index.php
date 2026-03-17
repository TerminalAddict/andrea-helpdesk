<?php
$projectRoot = dirname(__DIR__);
require $projectRoot . '/vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createUnsafeImmutable($projectRoot);
$dotenv->safeLoad();

$pageTitle  = 'Andrea Helpdesk';
$faviconUrl = '/favicon.svg';
try {
    $pdo = new PDO(
        'mysql:host=' . (getenv('DB_HOST') ?: 'localhost') .
        ';port='      . (getenv('DB_PORT') ?: '3306') .
        ';dbname='    . getenv('DB_DATABASE') .
        ';charset=utf8mb4',
        getenv('DB_USERNAME'),
        getenv('DB_PASSWORD'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $rows = $pdo->query(
        "SELECT key_name, value FROM settings WHERE key_name IN ('company_name','favicon_url')"
    )->fetchAll(PDO::FETCH_KEY_PAIR);
    if (!empty($rows['company_name'])) $pageTitle  = $rows['company_name'];
    if (!empty($rows['favicon_url']))  $faviconUrl = $rows['favicon_url'];
} catch (Throwable) {}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <link id="app-favicon" rel="icon" href="<?= htmlspecialchars($faviconUrl) ?>">
    <link rel="stylesheet" href="/assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="/assets/vendor/bootstrap-icons/bootstrap-icons.min.css">
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>

<div id="navbar-container"></div>

<div id="app-wrapper">
    <div id="loading-screen">
        <div class="text-center">
            <div class="spinner-border text-primary" role="status" style="width:3rem;height:3rem;">
                <span class="visually-hidden">Loading...</span>
            </div>
            <p class="mt-3 text-muted">Loading Andrea Helpdesk...</p>
        </div>
    </div>
    <div id="app" class="container-fluid mt-0" style="display:none;"></div>
</div>

<!-- Toast container -->
<div id="toast-container" class="toast-container position-fixed bottom-0 end-0 p-3" style="z-index:11000;"></div>

<!-- Confirm modal -->
<div class="modal fade" id="confirmModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="confirmModalTitle">Confirm</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="confirmModalBody"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="confirmModalOk">Confirm</button>
            </div>
        </div>
    </div>
</div>

<script>
window.AppConfig = {
    apiBase: '/api',
    version: '1.0.0'
};
</script>

<script src="/assets/vendor/jquery/jquery.min.js"></script>
<script src="/assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>

<!-- API wrapper (must load first) -->
<script src="/assets/js/api.js"></script>

<!-- Components -->
<script src="/assets/js/components/navbar.js"></script>

<!-- Views -->
<script src="/assets/js/views/login.js"></script>
<script src="/assets/js/views/dashboard.js"></script>
<script src="/assets/js/views/tickets.js"></script>
<script src="/assets/js/views/ticket-detail.js"></script>
<script src="/assets/js/views/ticket-new.js"></script>
<script src="/assets/js/views/customers.js"></script>
<script src="/assets/js/views/customer-detail.js"></script>
<script src="/assets/js/views/agents.js"></script>
<script src="/assets/js/views/settings.js"></script>
<script src="/assets/js/views/reports.js"></script>
<script src="/assets/js/views/knowledge-base.js"></script>
<script src="/assets/js/views/portal.js"></script>

<!-- Main app router (must load last) -->
<script src="/assets/js/app.js"></script>

</body>
</html>

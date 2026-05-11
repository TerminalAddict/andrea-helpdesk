<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__);
require $projectRoot . '/vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createUnsafeImmutable($projectRoot);
$dotenv->safeLoad();

$settings = [
    'company_name' => 'Andrea Helpdesk',
    'favicon_url' => '/Andrea-Helpdesk-favicon.png',
    'pwa_icon_url' => '',
    'primary_color' => '#111b2d',
];

try {
    $pdo = new PDO(
        'mysql:host=' . (getenv('DB_HOST') ?: 'localhost') .
        ';port=' . (getenv('DB_PORT') ?: '3306') .
        ';dbname=' . getenv('DB_DATABASE') .
        ';charset=utf8mb4',
        getenv('DB_USERNAME'),
        getenv('DB_PASSWORD'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $rows = $pdo->query(
        "SELECT key_name, value FROM settings WHERE key_name IN ('company_name','favicon_url','pwa_icon_url','primary_color')"
    )->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach ($rows as $key => $value) {
        $settings[$key] = (string)$value;
    }
} catch (Throwable) {
    // Keep the manifest installable even if the database is unavailable.
}

$name = trim($settings['company_name']) ?: 'Andrea Helpdesk';
$themeColor = preg_match('/^#[0-9a-fA-F]{6}$/', $settings['primary_color']) ? $settings['primary_color'] : '#111b2d';
$configuredIcon = safeManifestUrl($settings['pwa_icon_url']) ?: safeManifestUrl($settings['favicon_url']);

$icons = $configuredIcon
    ? [
        ['src' => $configuredIcon, 'sizes' => 'any', 'type' => manifestIconType($configuredIcon), 'purpose' => 'any'],
        ['src' => $configuredIcon, 'sizes' => 'any', 'type' => manifestIconType($configuredIcon), 'purpose' => 'maskable'],
    ]
    : [
        ['src' => '/pwa-icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => '/pwa-icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => '/pwa-icon-maskable-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'maskable'],
        ['src' => '/pwa-icon-maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
    ];

header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: no-store');
echo json_encode([
    'name' => $name,
    'short_name' => mb_substr($name, 0, 24),
    'description' => $name . ' ticket management',
    'start_url' => '/#/',
    'scope' => '/',
    'display' => 'standalone',
    'background_color' => '#f3f6fb',
    'theme_color' => $themeColor,
    'orientation' => 'any',
    'icons' => $icons,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

function safeManifestUrl(string $url): string
{
    $url = trim($url);
    if ($url === '' || preg_match('/[\r\n]/', $url)) {
        return '';
    }
    if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
        return $url;
    }
    if (preg_match('#^https://#i', $url)) {
        return $url;
    }
    return '';
}

function manifestIconType(string $url): string
{
    $path = strtolower((string)(parse_url($url, PHP_URL_PATH) ?: ''));
    return match (true) {
        str_ends_with($path, '.svg') => 'image/svg+xml',
        str_ends_with($path, '.ico') => 'image/x-icon',
        str_ends_with($path, '.jpg'), str_ends_with($path, '.jpeg') => 'image/jpeg',
        default => 'image/png',
    };
}

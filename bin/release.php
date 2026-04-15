#!/usr/bin/env php
<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$today = date('Y-m-d');

$versionPath = $root . '/version.json';
$versionData = json_decode((string)file_get_contents($versionPath), true);
if (!is_array($versionData) || empty($versionData['version'])) {
    fwrite(STDERR, "Could not parse version.json\n");
    exit(1);
}

$parts = array_map('intval', explode('.', (string)$versionData['version']));
while (count($parts) < 3) {
    $parts[] = 0;
}
$parts[2]++;
$newVersion = implode('.', array_slice($parts, 0, 3));

$versionData['version'] = $newVersion;
$versionData['released'] = $today;
if (empty($versionData['description'])) {
    $versionData['description'] = "Release {$newVersion}";
}
file_put_contents(
    $versionPath,
    json_encode($versionData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL
);

updateFile(
    $root . '/docs/version.md',
    '/Current release: \*\*[^*]+\*\* \([^)]+\)/',
    "Current release: **{$newVersion}** ({$today})"
);

updateFile(
    $root . '/public_html/index.php',
    "/version: '\\d+\\.\\d+\\.\\d+'/",
    "version: '{$newVersion}'"
);

updateChangelog($root . '/docs/changelog.md', $newVersion, $today);

echo $newVersion . PHP_EOL;

function updateFile(string $path, string $pattern, string $replacement): void
{
    $contents = (string)file_get_contents($path);
    $updated = preg_replace($pattern, $replacement, $contents, 1, $count);
    if ($updated === null || $count !== 1) {
        fwrite(STDERR, "Failed to update {$path}\n");
        exit(1);
    }
    file_put_contents($path, $updated);
}

function updateChangelog(string $path, string $version, string $date): void
{
    $contents = (string)file_get_contents($path);
    $pattern = '/## \[Unreleased\](?: — [0-9-]+)?\n\n/';
    $replacement = "## [Unreleased]\n\n## [{$version}] — {$date}\n\n";
    $updated = preg_replace($pattern, $replacement, $contents, 1, $count);
    if ($updated === null || $count !== 1) {
        fwrite(STDERR, "Failed to update {$path}\n");
        exit(1);
    }
    file_put_contents($path, $updated);
}

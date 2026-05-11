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

$currentVersion = (string)$versionData['version'];

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

updateFile(
    $root . '/public_html/service-worker.js',
    "/const APP_VERSION = '\\d+\\.\\d+\\.\\d+';/",
    "const APP_VERSION = '{$newVersion}';"
);

updateChangelog($root . '/docs/changelog.md', $newVersion, $today, $currentVersion, $root);

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

function updateChangelog(string $path, string $version, string $date, string $currentVersion, string $root): void
{
    $contents = (string)file_get_contents($path);
    $pattern = '/## \[Unreleased\](?: — [0-9-]+)?\n\n(.*?)(?=\n---\n\n## \[|\z)/s';
    if (!preg_match($pattern, $contents, $matches)) {
        fwrite(STDERR, "docs/changelog.md must contain an [Unreleased] section before running make release\n");
        exit(1);
    }

    $releaseNotes = trim((string)$matches[1]);
    if ($releaseNotes === '') {
        $releaseNotes = generateReleaseNotesFromGit($root, $currentVersion);
    }

    $replacement = "## [Unreleased]\n\n---\n\n## [{$version}] — {$date}\n\n{$releaseNotes}\n\n";
    $updated = preg_replace($pattern, $replacement, $contents, 1, $count);
    if ($updated === null || $count !== 1) {
        fwrite(STDERR, "Failed to update {$path}\n");
        exit(1);
    }

    file_put_contents($path, $updated);
}

function generateReleaseNotesFromGit(string $root, string $currentVersion): string
{
    $tag = 'v' . $currentVersion;
    $tagExists = trim((string)shell_exec('cd ' . escapeshellarg($root) . ' && git rev-parse --verify --quiet ' . escapeshellarg($tag) . ' 2>/dev/null'));
    if ($tagExists === '') {
        fwrite(STDERR, "docs/changelog.md [Unreleased] section is empty and {$tag} was not found; add release notes before running make release\n");
        exit(1);
    }

    $cmd = 'cd ' . escapeshellarg($root) . ' && git log --format=%s ' . escapeshellarg($tag . '..HEAD');
    exec($cmd, $subjects, $code);
    if ($code !== 0) {
        fwrite(STDERR, "Could not read git history for changelog notes\n");
        exit(1);
    }

    $notes = [];
    foreach ($subjects as $subject) {
        $subject = trim((string)$subject);
        if ($subject === '' || preg_match('/^Bump version to \d+\.\d+\.\d+$/', $subject)) {
            continue;
        }
        $notes[] = '- ' . normalizeCommitSubject($subject);
    }

    if (!$notes) {
        fwrite(STDERR, "docs/changelog.md [Unreleased] section is empty and no non-release commits were found since {$tag}\n");
        exit(1);
    }

    return "### Changed\n" . implode("\n", $notes);
}

function normalizeCommitSubject(string $subject): string
{
    $subject = preg_replace('/\s+/', ' ', $subject) ?: $subject;
    $subject = trim($subject);
    if ($subject === '') {
        return $subject;
    }

    return strtoupper($subject[0]) . substr($subject, 1);
}

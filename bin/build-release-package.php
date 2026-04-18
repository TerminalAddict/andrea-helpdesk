#!/usr/bin/env php
<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$versionPath = $root . '/version.json';
$versionData = json_decode((string)file_get_contents($versionPath), true);

if (!is_array($versionData) || empty($versionData['version'])) {
    fwrite(STDERR, "Could not parse version.json\n");
    exit(1);
}

$version = (string)$versionData['version'];
$buildRoot = $root . '/build';
$releaseName = 'andrea-helpdesk-' . $version;
$releaseDir = $buildRoot . '/' . $releaseName;
$zipPath = $buildRoot . '/' . $releaseName . '-full.zip';
$checksumPath = $zipPath . '.sha256';

rrmdir($releaseDir);
@mkdir($buildRoot, 0775, true);
@unlink($zipPath);
@unlink($checksumPath);

$excludes = [
    '.git',
    '.github',
    'build',
    'docs/videos',
    'storage/attachments',
    'storage/logs',
    '.env',
    'Makefile.local',
    'install-cli.log',
    '.DS_Store',
    'Thumbs.db',
];

copyTree($root, $releaseDir, $root, $excludes);

@mkdir($releaseDir . '/storage/attachments', 0775, true);
@mkdir($releaseDir . '/storage/logs', 0775, true);
@touch($releaseDir . '/storage/logs/app.log');
@touch($releaseDir . '/storage/logs/imap.log');

ensureExists($releaseDir . '/vendor/autoload.php', 'vendor/autoload.php is missing. Run composer install --no-dev --optimize-autoloader before packaging.');
ensureExists($releaseDir . '/public_html/assets/vendor/bootstrap/bootstrap.min.css', 'Bootstrap asset is missing. Ensure frontend assets are present before packaging.');
ensureExists($releaseDir . '/public_html/assets/vendor/jquery/jquery.min.js', 'jQuery asset is missing. Ensure frontend assets are present before packaging.');
ensureExists($releaseDir . '/public_html/assets/vendor/dompurify/purify.min.js', 'DOMPurify asset is missing. Ensure frontend assets are present before packaging.');
ensureExists($releaseDir . '/public_html/assets/vendor/quill/quill.min.js', 'Quill asset is missing. Ensure frontend assets are present before packaging.');

$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "Could not create {$zipPath}\n");
    exit(1);
}

addDirToZip($zip, $releaseDir, $releaseDir);
$zip->close();

$hash = hash_file('sha256', $zipPath);
file_put_contents($checksumPath, $hash . '  ' . basename($zipPath) . PHP_EOL);

echo json_encode([
    'version' => $version,
    'release_dir' => $releaseDir,
    'zip' => $zipPath,
    'checksum' => $checksumPath,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

function ensureExists(string $path, string $message): void
{
    if (!file_exists($path)) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

function rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $item) {
        if ($item->isDir() && !$item->isLink()) {
            @rmdir($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }

    @rmdir($dir);
}

function copyTree(string $source, string $destination, string $root, array $excludes): void
{
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        $fullPath = $item->getPathname();
        $relative = ltrim(str_replace($root, '', $fullPath), DIRECTORY_SEPARATOR);
        if ($relative === '') {
            continue;
        }
        if (isExcluded($relative, $excludes)) {
            continue;
        }

        $target = $destination . '/' . str_replace(DIRECTORY_SEPARATOR, '/', $relative);
        if ($item->isDir() && !$item->isLink()) {
            @mkdir($target, 0775, true);
            continue;
        }

        @mkdir(dirname($target), 0775, true);
        if (!copy($fullPath, $target)) {
            fwrite(STDERR, "Failed to copy {$relative}\n");
            exit(1);
        }
    }
}

function isExcluded(string $relative, array $excludes): bool
{
    $relative = str_replace('\\', '/', $relative);
    foreach ($excludes as $exclude) {
        $exclude = trim(str_replace('\\', '/', $exclude), '/');
        if ($exclude === '') {
            continue;
        }
        if ($relative === $exclude || str_starts_with($relative, $exclude . '/')) {
            return true;
        }
    }
    return false;
}

function addDirToZip(ZipArchive $zip, string $baseDir, string $currentDir): void
{
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($currentDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        $path = $item->getPathname();
        $local = basename($baseDir) . '/' . ltrim(str_replace($baseDir, '', $path), DIRECTORY_SEPARATOR);
        $local = str_replace('\\', '/', $local);
        if ($item->isDir() && !$item->isLink()) {
            $zip->addEmptyDir(rtrim($local, '/'));
        } else {
            $zip->addFile($path, $local);
        }
    }
}

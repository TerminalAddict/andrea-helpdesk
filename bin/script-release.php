<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$scriptPath = $root . '/bin/install-cli.sh';
$contents = @file_get_contents($scriptPath);

if ($contents === false) {
    fwrite(STDERR, "Could not read bin/install-cli.sh\n");
    exit(1);
}

if (!preg_match('/^SCRIPT_VERSION="(\d+)\.(\d+)\.(\d+)"$/m', $contents, $matches)) {
    fwrite(STDERR, "Could not find SCRIPT_VERSION in bin/install-cli.sh\n");
    exit(1);
}

$major = (int)$matches[1];
$minor = (int)$matches[2];
$patch = (int)$matches[3] + 1;
$newVersion = sprintf('%d.%d.%d', $major, $minor, $patch);

$updated = preg_replace(
    '/^SCRIPT_VERSION="\d+\.\d+\.\d+"$/m',
    'SCRIPT_VERSION="' . $newVersion . '"',
    $contents,
    1
);

if ($updated === null || $updated === $contents) {
    fwrite(STDERR, "Failed to update SCRIPT_VERSION in bin/install-cli.sh\n");
    exit(1);
}

if (@file_put_contents($scriptPath, $updated) === false) {
    fwrite(STDERR, "Could not write bin/install-cli.sh\n");
    exit(1);
}

echo $newVersion;

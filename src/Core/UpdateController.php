<?php
declare(strict_types=1);

namespace Andrea\Helpdesk\Core;

class UpdateController
{
    private const DEFAULT_REPO_ZIP_URL = 'https://github.com/TerminalAddict/andrea-helpdesk/archive/refs/heads/main.zip';
    private const DEFAULT_REPO_PREFIX  = 'andrea-helpdesk-main'; // top-level dir inside the zip
    private const RELEASE_ZIP_TEMPLATE = 'https://github.com/TerminalAddict/andrea-helpdesk/releases/download/v%s/andrea-helpdesk-%s-full.zip';
    private const FULL_PACKAGE_BRIDGE_VERSION = '1.4.9';

    /** Dirs relative to root that must be writable for an update */
    private const WRITE_PATHS = [
        ''          => '/ (application root)',
        'public_html' => '/public_html/',
        'src'       => '/src/',
        'config'    => '/config/',
        'bin'       => '/bin/',
        'database'  => '/database/',
        'vendor'    => '/vendor/ (PHP dependencies)',
    ];

    /** Paths relative to root excluded when copying new files */
    private const BASE_EXCLUDE = [
        '.env', 'storage', '.git',
        'install.lock', 'Makefile.local',
        'docs/videos',
    ];

    // ── Preflight ─────────────────────────────────────────────────────────────

    public function preflight(): void
    {
        $root   = dirname(__DIR__, 2);
        $checks = [];

        [$upgradePathOk, $upgradePathDetail, $upgradePathFix] = $this->upgradePathCompatibility();
        $checks[] = $this->check(
            'Upgrade path compatibility',
            $upgradePathOk,
            $upgradePathDetail,
            $upgradePathFix
        );

        // PHP ZipArchive
        $ok = extension_loaded('zip');
        $checks[] = $this->check(
            'PHP ZipArchive extension',
            $ok,
            $ok ? 'Available' : 'Not loaded',
            'Enable the php-zip extension. Ubuntu/Debian: install the version-specific package matching your Apache PHP — run "php -v" to find the version, then e.g. sudo apt install php8.2-zip && sudo phpenmod zip && sudo service apache2 restart. If "php -v" shows a different version to what Apache uses, check: apache2 -v and ls /etc/php/. cPanel: enable "Zip" under MultiPHP INI Editor → PHP Extensions.'
        );

        // HTTP download
        $hasCurl = function_exists('curl_exec');
        $hasUrl  = (bool) ini_get('allow_url_fopen');
        $ok = $hasCurl || $hasUrl;
        $checks[] = $this->check(
            'HTTP download (cURL or allow_url_fopen)',
            $ok,
            $hasCurl ? 'cURL available' : ($hasUrl ? 'allow_url_fopen enabled' : 'Neither available'),
            'Install php-curl (sudo apt install php-curl) or set allow_url_fopen = On in php.ini / MultiPHP INI Editor.'
        );

        [$composerOk, $composerDetail] = $this->composerAvailable();
        $defaultPackage = $this->usingDefaultUpdatePackage();
        $releasePackageAvailable = null;
        $releasePackageDetail = '';
        $releasePackageFix = '';
        if ($defaultPackage && !$composerOk) {
            [$releasePackageAvailable, $releasePackageDetail, $releasePackageFix] = $this->latestReleasePackageAvailability();
        }
        $checks[] = $this->check(
            'PHP dependency update path',
            ($defaultPackage && ($composerOk || $releasePackageAvailable === true)) || $composerOk,
            $defaultPackage && $releasePackageAvailable !== false
                ? ($composerOk ? 'Full release package preferred; Composer also available' : 'Full release package preferred; Composer not required for normal updates')
                : ($composerOk ? $composerDetail : $composerDetail),
            'The updater must be able to update PHP dependencies. Use the default full release package, or install Composer and ensure the PHP process can run: composer install --no-dev --optimize-autoloader'
        );
        if ($defaultPackage && !$composerOk) {
            $checks[] = $this->check(
                'Full release package availability',
                $releasePackageAvailable === true,
                $releasePackageDetail,
                $releasePackageFix
            );
        }

        // Write permissions
        foreach (self::WRITE_PATHS as $rel => $label) {
            $path = $rel === '' ? $root : $root . '/' . $rel;
            $ok   = is_dir($path) && is_writable($path);
            $checks[] = $this->check(
                'Write permission: ' . $label,
                $ok,
                $ok ? 'Writable' : 'Not writable',
                "Set permissions: chmod 755 {$path}  — or chown the directory to the web server user (e.g. www-data). On shared hosting use your file manager to set the directory permission to 755."
            );
        }

        // Existing file overwrite checks
        foreach (self::WRITE_PATHS as $rel => $label) {
            $path = $rel === '' ? $root : $root . '/' . $rel;
            [$ok, $detail, $fix] = $this->checkOverwriteability($path);
            $checks[] = $this->check(
                'Overwrite existing files: ' . $label,
                $ok,
                $detail,
                $fix
            );
        }

        // Temp directory writable
        $tmp = sys_get_temp_dir();
        $ok  = is_writable($tmp);
        $checks[] = $this->check(
            "Temp directory writable ({$tmp})",
            $ok,
            $ok ? 'Writable' : 'Not writable',
            "The system temp directory ({$tmp}) is not writable by the web server. Contact your host to correct permissions."
        );

        // Disk space (50 MB minimum)
        $free  = disk_free_space($root);
        $ok    = $free !== false && $free >= 50 * 1024 * 1024;
        $freeMb = $free !== false ? round($free / 1024 / 1024) . ' MB free' : 'unknown';
        $checks[] = $this->check(
            'Disk space (50 MB minimum)',
            $ok,
            $freeMb,
            'Free up at least 50 MB of disk space on the server before updating.'
        );

        $ready = !in_array(false, array_column($checks, 'pass'), true);
        Response::json(['success' => true, 'data' => ['checks' => $checks, 'ready' => $ready]]);
    }

    // ── Run update ────────────────────────────────────────────────────────────

    public function run(): void
    {
        set_time_limit(120);

        $root    = dirname(__DIR__, 2);
        $log     = [];
        $zipPath = null;
        $tmpDir  = null;
        $lockFile = sys_get_temp_dir() . '/andrea-helpdesk-update.lock';
        $lock = null;

        try {
            // Lock to prevent concurrent updates
            $lock = fopen($lockFile, 'c');
            if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
                throw new \RuntimeException('Another update is already in progress. Please wait and try again.');
            }

            [$upgradePathOk, $upgradePathDetail, $upgradePathFix] = $this->upgradePathCompatibility();
            if (!$upgradePathOk) {
                throw new \RuntimeException($upgradePathDetail . ' ' . $upgradePathFix);
            }

            // 1. Download zip
            [$zipPath, $source] = $this->downloadUpdatePackage($log);

            // 2. Extract
            $log[] = 'Extracting…';
            $tmpDir = sys_get_temp_dir() . '/andrea-helpdesk-upd-' . time();
            $zip    = new \ZipArchive();
            if ($zip->open($zipPath) !== true) {
                throw new \RuntimeException('Failed to open downloaded zip archive.');
            }
            $zip->extractTo($tmpDir);
            $zip->close();
            unlink($zipPath);
            $zipPath = null;

            $srcDir = $this->findExtractedRoot($tmpDir, $source['prefix']);
            $log[] = 'Extracted successfully from ' . $source['label'] . '.';

            // 3. Copy files
            $hasPackagedVendor = is_file($srcDir . '/vendor/autoload.php');
            [$composerOk] = $this->composerAvailable();
            if (!$hasPackagedVendor && !$composerOk) {
                throw new \RuntimeException('This update archive does not include vendor dependencies and Composer is not available. No files were copied. Use the full GitHub release package or install Composer before updating.');
            }

            $exclude = self::BASE_EXCLUDE;
            if (!$hasPackagedVendor) {
                $exclude[] = 'vendor';
            }

            $log[] = 'Copying files…';
            $copied = $this->copyDir($srcDir, $root, $exclude);
            $log[] = "Copied {$copied} file(s).";

            // 4. Update Composer dependencies before database work so new PHP classes exist.
            $log[] = 'Updating PHP dependencies…';
            foreach ($this->updateDependencies($root, $hasPackagedVendor) as $line) {
                $log[] = $line;
            }

            // 5. Clean up temp
            $this->removeDir($tmpDir);
            $tmpDir = null;

            // 6. Schema (idempotent)
            $log[] = 'Updating database schema…';
            foreach ($this->runSchema($root) as $line) $log[] = $line;

            // 7. Migrations
            $log[] = 'Checking for new migrations…';
            foreach ($this->runMigrations($root) as $line) $log[] = $line;

            // 8. Flush opcode cache if available
            if (function_exists('opcache_reset')) {
                opcache_reset();
                $log[] = 'Opcode cache cleared.';
            }

            $log[] = 'done';
            Response::json(['success' => true, 'data' => ['log' => $log]]);

        } catch (\Throwable $e) {
            if ($zipPath && file_exists($zipPath)) @unlink($zipPath);
            if ($tmpDir  && is_dir($tmpDir))      $this->removeDir($tmpDir);
            $log[] = 'ERROR: ' . $e->getMessage();
            Response::json(['success' => false, 'message' => $e->getMessage(), 'data' => ['log' => $log]]);
        } finally {
            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
                @unlink($lockFile);
            }
        }
    }

    private function repoZipUrl(): string
    {
        $url = trim((string)(getenv('UPDATE_REPO_ZIP_URL') ?: self::DEFAULT_REPO_ZIP_URL));
        return filter_var($url, FILTER_VALIDATE_URL) ? $url : self::DEFAULT_REPO_ZIP_URL;
    }

    private function repoPrefix(): string
    {
        $prefix = trim((string)(getenv('UPDATE_REPO_PREFIX') ?: self::DEFAULT_REPO_PREFIX));
        return preg_match('/^[A-Za-z0-9._-]+$/', $prefix) === 1 ? $prefix : self::DEFAULT_REPO_PREFIX;
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function usingDefaultUpdatePackage(): bool
    {
        return trim((string)(getenv('UPDATE_REPO_ZIP_URL') ?: '')) === '';
    }

    private function downloadUpdatePackage(array &$log): array
    {
        $zipPath = sys_get_temp_dir() . '/andrea-helpdesk-' . time() . '.zip';
        $errors = [];
        $sources = $this->updateSources();
        if (!$sources) {
            throw new \RuntimeException('No compatible update package is available. Wait for the full GitHub release package to finish building, or install Composer and try again.');
        }

        foreach ($sources as $source) {
            $log[] = 'Downloading update from ' . $source['label'] . '…';
            $bytes = $this->downloadToFile($source['url'], $zipPath);
            if ($bytes !== false && $bytes >= 1024) {
                $log[] = 'Downloaded ' . round($bytes / 1024) . ' KB.';
                return [$zipPath, $source];
            }

            $errors[] = $source['label'];
            @unlink($zipPath);
        }

        throw new \RuntimeException('Failed to download update package from GitHub. Tried: ' . implode(', ', $errors));
    }

    private function updateSources(): array
    {
        if (!$this->usingDefaultUpdatePackage()) {
            return [[
                'url' => $this->repoZipUrl(),
                'prefix' => $this->repoPrefix(),
                'label' => 'configured update zip',
            ]];
        }

        $sources = [];
        try {
            $latest = (new VersionService())->getLatest();
            $version = (string)($latest['version'] ?? '');
            if (preg_match('/^\d+\.\d+\.\d+(?:[-+][A-Za-z0-9._-]+)?$/', $version) === 1) {
                $sources[] = [
                    'url' => sprintf(self::RELEASE_ZIP_TEMPLATE, $version, $version),
                    'prefix' => 'andrea-helpdesk-' . $version,
                    'label' => 'GitHub full release package v' . $version,
                ];
            }
        } catch (\Throwable) {
            // Fall back to the source archive below.
        }

        [$composerOk] = $this->composerAvailable();
        if (!$composerOk) {
            return $sources;
        }

        $sources[] = [
            'url' => self::DEFAULT_REPO_ZIP_URL,
            'prefix' => self::DEFAULT_REPO_PREFIX,
            'label' => 'GitHub source archive',
        ];

        return $sources;
    }

    private function latestReleasePackageAvailability(): array
    {
        try {
            $latest = (new VersionService())->getLatest();
            $version = (string)($latest['version'] ?? '');
            if (preg_match('/^\d+\.\d+\.\d+(?:[-+][A-Za-z0-9._-]+)?$/', $version) !== 1) {
                return [
                    false,
                    'Latest version metadata did not contain a valid version number.',
                    'Check version.json or update manually.',
                ];
            }
            $url = sprintf(self::RELEASE_ZIP_TEMPLATE, $version, $version);
            if ($this->remoteFileAvailable($url)) {
                return [
                    true,
                    "Full release package v{$version} is available.",
                    '',
                ];
            }

            return [
                false,
                "Full release package v{$version} is not available yet.",
                'Wait for the GitHub release package workflow to complete, then run the updater again. Do not use the source archive on hosts without Composer.',
            ];
        } catch (\Throwable $e) {
            return [
                false,
                'Could not verify the full release package: ' . $e->getMessage(),
                'Check the update metadata URL and try again.',
            ];
        }
    }

    private function remoteFileAvailable(string $url): bool
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_NOBODY         => true,
                CURLOPT_TIMEOUT        => 20,
                CURLOPT_USERAGENT      => 'Andrea-Helpdesk-Updater/1.0',
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_FAILONERROR    => false,
            ]);
            curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            return $status >= 200 && $status < 300;
        }

        $headers = @get_headers($url, true, stream_context_create(['http' => [
            'method' => 'HEAD',
            'timeout' => 20,
            'header' => "User-Agent: Andrea-Helpdesk-Updater/1.0\r\n",
            'follow_location' => 1,
        ]]));
        if (!is_array($headers) || empty($headers[0])) {
            return false;
        }
        $statusLine = is_array($headers[0]) ? end($headers[0]) : $headers[0];
        return is_string($statusLine) && preg_match('/\s2\d\d\s/', $statusLine) === 1;
    }

    private function upgradePathCompatibility(): array
    {
        if (!$this->usingDefaultUpdatePackage()) {
            return [
                true,
                'Custom update package configured; compatibility is managed by the installer administrator.',
                '',
            ];
        }

        try {
            $versions = new VersionService();
            $installed = (string)($versions->getInstalled()['version'] ?? 'unknown');
            $latestData = $versions->getLatest();
            $latest = (string)($latestData['version'] ?? 'unknown');
            $minimumFrom = (string)($latestData['minimum_update_from'] ?? self::FULL_PACKAGE_BRIDGE_VERSION);
            $minimumReason = trim((string)($latestData['minimum_update_reason'] ?? ''));
        } catch (\Throwable $e) {
            return [
                false,
                'Could not verify update compatibility: ' . $e->getMessage(),
                'Check the update metadata URL and try again before running the update.',
            ];
        }

        if (!$this->isComparableVersion($installed) || !$this->isComparableVersion($latest) || !$this->isComparableVersion($minimumFrom)) {
            return [
                false,
                "Could not verify update compatibility from installed version '{$installed}' to latest version '{$latest}' with minimum bridge '{$minimumFrom}'.",
                'Update manually or correct version.json before using the in-app updater.',
            ];
        }

        if (
            $versions->compare($installed, $minimumFrom) < 0
            && $versions->compare($latest, $minimumFrom) > 0
        ) {
            $reason = $minimumReason !== '' ? ' ' . $minimumReason : '';
            return [
                false,
                "Installed version {$installed} is older than the required updater bridge release {$minimumFrom}.{$reason}",
                "Update to {$minimumFrom} first, then run the updater again for newer releases.",
            ];
        }

        return [
            true,
            "Upgrade path is supported from {$installed} to {$latest}.",
            '',
        ];
    }

    private function isComparableVersion(string $version): bool
    {
        return preg_match('/^\d+\.\d+\.\d+(?:[-+][A-Za-z0-9._-]+)?$/', $version) === 1;
    }

    private function findExtractedRoot(string $tmpDir, string $expectedPrefix): string
    {
        $expected = $tmpDir . '/' . $expectedPrefix;
        if (is_dir($expected)) {
            return $expected;
        }

        $dirs = array_values(array_filter(glob($tmpDir . '/*') ?: [], 'is_dir'));
        if (count($dirs) === 1) {
            return $dirs[0];
        }

        throw new \RuntimeException('Unexpected zip structure — directory "' . $expectedPrefix . '" not found inside archive.');
    }

    private function updateDependencies(string $root, bool $hasPackagedVendor): array
    {
        $log = [];

        if ($hasPackagedVendor) {
            $log[] = 'Vendor dependencies were included in the update package.';
        }

        [$composerOk, $composerDetail] = $this->composerAvailable();
        if (!$composerOk) {
            if ($hasPackagedVendor) {
                $log[] = 'Composer not available; using packaged vendor dependencies.';
            } else {
                throw new \RuntimeException('The update package did not include vendor dependencies and Composer is not available. Install Composer, or update from the full GitHub release package instead of a source archive.');
            }
        } else {
            $log[] = $composerDetail;
            try {
                foreach ($this->runComposerInstall($root) as $line) {
                    $log[] = $line;
                }
            } catch (\Throwable $e) {
                if (!$hasPackagedVendor) {
                    throw $e;
                }
                $log[] = 'Composer dependency refresh failed, continuing with packaged vendor dependencies: ' . $e->getMessage();
            }
        }

        if (!is_file($root . '/vendor/autoload.php')) {
            throw new \RuntimeException('Composer autoload file is missing after dependency update: vendor/autoload.php');
        }

        return $log;
    }

    private function composerAvailable(): array
    {
        if (!function_exists('exec')) {
            return [false, 'PHP exec() is disabled, so Composer cannot be run by the updater'];
        }

        $output = [];
        $code = 1;
        @exec('command -v composer 2>&1', $output, $code);
        if ($code !== 0 || empty($output[0])) {
            return [false, 'Composer command not found'];
        }

        return [true, 'Composer available: ' . trim((string)$output[0])];
    }

    private function runComposerInstall(string $root): array
    {
        if (!is_file($root . '/composer.json')) {
            throw new \RuntimeException('composer.json is missing after update copy; cannot update PHP dependencies.');
        }

        $command = 'cd ' . escapeshellarg($root) . ' && composer install --no-dev --optimize-autoloader --no-interaction 2>&1';
        $output = [];
        $code = 1;
        @exec($command, $output, $code);

        $log = [];
        foreach ($output as $line) {
            $line = trim((string)$line);
            if ($line !== '') {
                $log[] = '  composer: ' . $line;
            }
        }

        if ($code !== 0) {
            throw new \RuntimeException('Composer dependency update failed. Run this manually from the application directory: composer install --no-dev --optimize-autoloader');
        }

        if (empty($log)) {
            $log[] = '  composer: dependencies are already up to date.';
        }

        return $log;
    }

    private function check(string $name, bool $pass, string $detail, string $fix): array
    {
        return ['name' => $name, 'pass' => $pass, 'detail' => $detail, 'fix' => $fix];
    }

    private function copyDir(string $src, string $dst, array $exclude): int
    {
        $count = 0;
        $iter  = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iter as $item) {
            $rel      = substr($item->getPathname(), strlen($src) + 1);
            if ($this->shouldExcludePath($rel, $exclude)) continue;

            $dest = $dst . DIRECTORY_SEPARATOR . $rel;
            if ($item->isDir()) {
                if (!is_dir($dest) && !mkdir($dest, 0755, true) && !is_dir($dest)) {
                    throw new \RuntimeException('Failed to create directory during update: ' . $dest);
                }
            } else {
                $dir = dirname($dest);
                if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
                    throw new \RuntimeException('Failed to create directory during update: ' . $dir);
                }
                if (!@copy($item->getPathname(), $dest)) {
                    $error = error_get_last();
                    $message = $error['message'] ?? 'unknown error';
                    throw new \RuntimeException("Failed to overwrite file during update: {$dest} ({$message})");
                }
                $count++;
            }
        }
        return $count;
    }

    private function shouldExcludePath(string $rel, array $exclude): bool
    {
        $normalized = str_replace('\\', '/', ltrim($rel, '/'));
        foreach ($exclude as $skip) {
            $skip = str_replace('\\', '/', trim($skip, '/'));
            if ($skip === '') {
                continue;
            }
            if ($normalized === $skip || str_starts_with($normalized, $skip . '/')) {
                return true;
            }
        }
        return false;
    }

    private function checkOverwriteability(string $path): array
    {
        if (!is_dir($path)) {
            return [false, 'Directory missing', "Create the directory and ensure the web server can traverse and write within it: {$path}"];
        }

        $sample = [];
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iter as $item) {
            if (!$item->isFile()) {
                continue;
            }
            $sample[] = $item->getPathname();
            if (count($sample) >= 8) {
                break;
            }
        }

        foreach ($sample as $file) {
            if (!is_writable($file)) {
                return [
                    false,
                    'Existing file not writable: ' . $file,
                    'The updater must be able to overwrite existing files. Prefer granting group write to the web server group on the app tree, for example: chgrp -R www-data ' . escapeshellarg(dirname(__DIR__, 2)) . ' && find ' . escapeshellarg(dirname(__DIR__, 2)) . " -type d -exec chmod 775 {} \\; && find " . escapeshellarg(dirname(__DIR__, 2)) . " -type f -exec chmod 664 {} \\;. If ownership cannot change, at minimum ensure the web server user/group has write permission on the files it must replace."
                ];
            }
        }

        return [true, empty($sample) ? 'No existing files yet' : 'Sample existing files are writable', ''];
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) return;
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iter as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }

    private function runSchema(string $root): array
    {
        $log  = [];
        $pdo  = Database::getInstance()->getPdo();
        $sql  = (string) file_get_contents($root . '/database/schema.sql');

        $statements = [];
        foreach (explode(';', $sql) as $chunk) {
            $stmt = trim($chunk);
            if ($stmt === '') continue;
            // Strip comment lines to check for real content; pass original to exec() (MySQL handles --)
            $stripped = trim(implode("\n", array_filter(
                explode("\n", $stmt),
                fn($l) => !str_starts_with(ltrim($l), '--')
            )));
            if ($stripped !== '') $statements[] = $stmt;
        }

        $count = 0;
        foreach ($statements as $stmt) {
            try {
                $pdo->exec($stmt);
                $count++;
            } catch (\PDOException $e) {
                if (str_contains($e->getMessage(), 'Duplicate entry')) continue;
                $log[] = '  Schema note: ' . $e->getMessage();
            }
        }
        $log[] = "Schema: {$count} statement(s) executed.";
        return $log;
    }

    private function runMigrations(string $root): array
    {
        $log = [];
        $db  = Database::getInstance();
        $pdo = $db->getPdo();

        // Ensure tracking table exists
        $pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
            filename    VARCHAR(255) NOT NULL PRIMARY KEY,
            applied_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $applied = array_column(
            $db->fetchAll("SELECT filename FROM schema_migrations"),
            'filename'
        );

        $files = glob($root . '/database/migrations/*.sql') ?: [];
        sort($files);

        $ran = 0;
        foreach ($files as $file) {
            $name = basename($file);
            if ($name === '001_initial.sql') continue; // covered by schema.sql
            if (in_array($name, $applied, true)) {
                continue;
            }

            $sql        = (string) file_get_contents($file);
            $statements = [];
            foreach (explode(';', $sql) as $chunk) {
                $stmt = trim($chunk);
                if ($stmt === '') continue;
                $stripped = trim(implode("\n", array_filter(
                    explode("\n", $stmt),
                    fn($l) => !str_starts_with(ltrim($l), '--')
                )));
                if ($stripped !== '') $statements[] = $stmt;
            }

            $ok = true;
            foreach ($statements as $stmt) {
                try {
                    $pdo->exec($stmt);
                } catch (\PDOException $e) {
                    $msg  = $e->getMessage();
                    // Use driver-specific error code (e.g. MySQL 1060); getCode() returns SQLSTATE string
                    $code = isset($e->errorInfo[1]) ? (int)$e->errorInfo[1] : 0;
                    // MySQL 1060 = Duplicate column name, 1061 = Duplicate key name, 1068 = Multiple primary key
                    // These mean the schema change was already applied manually — treat as success
                    if (in_array($code, [1060, 1061, 1068], true)) {
                        $log[] = "  Migration {$name}: already applied ({$msg})";
                    } else {
                        $log[] = "  Migration {$name} error: {$msg}";
                        $ok    = false;
                    }
                }
            }

            if ($ok) {
                $db->execute("INSERT INTO schema_migrations (filename) VALUES (?)", [$name]);
                $log[] = "  Applied: {$name}";
                $ran++;
            }
        }

        $log[] = $ran > 0 ? "{$ran} migration(s) applied." : 'No new migrations.';
        return $log;
    }

    private function httpGet(string $url): string|false
    {
        if (function_exists('curl_exec')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 60,
                CURLOPT_USERAGENT      => 'Andrea-Helpdesk-Updater/1.0',
                CURLOPT_FOLLOWLOCATION => true,
            ]);
            $body   = curl_exec($ch);
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            return ($body !== false && $status === 200) ? $body : false;
        }

        $ctx = stream_context_create(['http' => [
            'timeout'         => 60,
            'header'          => "User-Agent: Andrea-Helpdesk-Updater/1.0\r\n",
            'follow_location' => 1,
        ]]);
        return @file_get_contents($url, false, $ctx);
    }

    private function downloadToFile(string $url, string $dest): int|false
    {
        if (file_exists($dest)) {
            @unlink($dest);
        }

        if (function_exists('curl_init')) {
            $fp = fopen($dest, 'wb');
            if (!$fp) {
                return false;
            }

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_FILE           => $fp,
                CURLOPT_TIMEOUT        => 120,
                CURLOPT_USERAGENT      => 'Andrea-Helpdesk-Updater/1.0',
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_FAILONERROR    => true,
            ]);
            $ok = curl_exec($ch);
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            fclose($fp);

            if ($ok === false || $status !== 200 || !file_exists($dest)) {
                @unlink($dest);
                return false;
            }

            $size = filesize($dest);
            return $size === false ? false : $size;
        }

        $in = @fopen($url, 'rb', false, stream_context_create(['http' => [
            'timeout'         => 120,
            'header'          => "User-Agent: Andrea-Helpdesk-Updater/1.0\r\n",
            'follow_location' => 1,
        ]]));
        if (!$in) {
            return false;
        }

        $out = fopen($dest, 'wb');
        if (!$out) {
            fclose($in);
            return false;
        }

        $bytes = stream_copy_to_stream($in, $out);
        fclose($in);
        fclose($out);

        if ($bytes === false || $bytes < 1) {
            @unlink($dest);
            return false;
        }

        return $bytes;
    }
}

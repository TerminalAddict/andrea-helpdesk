<?php
declare(strict_types=1);

namespace Andrea\Helpdesk\Core;

class UpdateController
{
    private const DEFAULT_REPO_ZIP_URL = 'https://github.com/TerminalAddict/andrea-helpdesk/archive/refs/heads/main.zip';
    private const DEFAULT_REPO_PREFIX  = 'andrea-helpdesk-main'; // top-level dir inside the zip

    /** Dirs relative to root that must be writable for an update */
    private const WRITE_PATHS = [
        ''          => '/ (application root)',
        'public_html' => '/public_html/',
        'src'       => '/src/',
        'config'    => '/config/',
        'bin'       => '/bin/',
        'database'  => '/database/',
    ];

    /** Paths relative to root excluded when copying new files */
    private const EXCLUDE = [
        '.env', 'storage', 'vendor', '.git',
        'install.lock', 'Makefile.local',
    ];

    // ── Preflight ─────────────────────────────────────────────────────────────

    public function preflight(): void
    {
        $root   = dirname(__DIR__, 2);
        $checks = [];

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

            // 1. Download zip
            $log[] = 'Downloading update from GitHub…';
            $raw = $this->httpGet($this->repoZipUrl());
            if ($raw === false || strlen($raw) < 1024) {
                throw new \RuntimeException('Failed to download update package from GitHub.');
            }
            $zipPath = sys_get_temp_dir() . '/andrea-helpdesk-' . time() . '.zip';
            file_put_contents($zipPath, $raw);
            $log[] = 'Downloaded ' . round(strlen($raw) / 1024) . ' KB.';

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

            $srcDir = $tmpDir . '/' . $this->repoPrefix();
            if (!is_dir($srcDir)) {
                throw new \RuntimeException('Unexpected zip structure — directory "' . $this->repoPrefix() . '" not found inside archive.');
            }
            $log[] = 'Extracted successfully.';

            // 3. Copy files
            $log[] = 'Copying files…';
            $copied = $this->copyDir($srcDir, $root, self::EXCLUDE);
            $log[] = "Copied {$copied} file(s).";

            // 4. Clean up temp
            $this->removeDir($tmpDir);
            $tmpDir = null;

            // 5. Schema (idempotent)
            $log[] = 'Updating database schema…';
            foreach ($this->runSchema($root) as $line) $log[] = $line;

            // 6. Migrations
            $log[] = 'Checking for new migrations…';
            foreach ($this->runMigrations($root) as $line) $log[] = $line;

            // 7. Flush opcode cache if available
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
            $topLevel = explode(DIRECTORY_SEPARATOR, $rel)[0];
            if (in_array($topLevel, $exclude, true)) continue;

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
}

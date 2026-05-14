<?php
declare(strict_types=1);

namespace Andrea\Helpdesk\Core;

class DependencyRepairService
{
    private const RELEASE_ZIP_TEMPLATE = 'https://github.com/TerminalAddict/andrea-helpdesk/releases/download/v%s/andrea-helpdesk-%s-full.zip';
    private const DEV_RELEASE_ZIP_TEMPLATE = 'https://github.com/TerminalAddict/andrea-helpdesk/releases/download/dev-v%s/andrea-helpdesk-%s-full.zip';

    public function ensureClasses(array $classes): array
    {
        $missing = $this->missingClasses($classes);
        if (!$missing) {
            return ['repaired' => false, 'available' => true, 'message' => 'Dependencies available.'];
        }

        [$composerOk, $composerDetail] = $this->composerAvailable();
        if (!$composerOk) {
            $packageResult = $this->repairFromReleasePackage();
            if (!$packageResult['success']) {
                return ['repaired' => false, 'available' => false, 'message' => $composerDetail . ' ' . $packageResult['message']];
            }
            return $this->verifyAfterRepair($classes, 'Full release package dependency repair completed.');
        }

        $root = dirname(__DIR__, 2);
        $result = $this->runComposerInstall($root);
        if (!$result['success']) {
            $packageResult = $this->repairFromReleasePackage();
            if (!$packageResult['success']) {
                return ['repaired' => false, 'available' => false, 'message' => $result['message'] . ' ' . $packageResult['message']];
            }
            return $this->verifyAfterRepair($classes, 'Full release package dependency repair completed after Composer failed.');
        }

        return $this->verifyAfterRepair($classes, 'Composer dependencies repaired.');
    }

    private function verifyAfterRepair(array $classes, string $successMessage): array
    {
        $root = dirname(__DIR__, 2);
        $autoload = $root . '/vendor/autoload.php';
        if (is_file($autoload)) {
            require $autoload;
        }

        $stillMissing = $this->missingClasses($classes);
        if ($stillMissing) {
            return [
                'repaired' => true,
                'available' => false,
                'message' => 'Dependency repair ran, but required classes are still missing: ' . implode(', ', $stillMissing),
            ];
        }

        return ['repaired' => true, 'available' => true, 'message' => $successMessage];
    }

    private function missingClasses(array $classes): array
    {
        return array_values(array_filter(
            array_map('strval', $classes),
            fn(string $class): bool => $class !== '' && !class_exists($class)
        ));
    }

    private function composerAvailable(): array
    {
        if (!function_exists('exec')) {
            return [false, 'PHP exec() is disabled, so Composer cannot be run by the application. Run composer install --no-dev --optimize-autoloader manually or install from the full release package.'];
        }

        $output = [];
        $code = 1;
        @exec('command -v composer 2>&1', $output, $code);
        if ($code !== 0 || empty($output[0])) {
            return [false, 'Composer command not found. Run composer install --no-dev --optimize-autoloader manually or install from the full release package.'];
        }

        return [true, trim((string)$output[0])];
    }

    private function runComposerInstall(string $root): array
    {
        if (!is_file($root . '/composer.json')) {
            return ['success' => false, 'message' => 'composer.json is missing; cannot repair PHP dependencies.'];
        }

        $command = 'cd ' . escapeshellarg($root) . ' && composer install --no-dev --optimize-autoloader --no-interaction 2>&1';
        $output = [];
        $code = 1;
        @exec($command, $output, $code);

        if ($code !== 0) {
            $tail = array_slice(array_values(array_filter(array_map('trim', $output))), -3);
            $detail = $tail ? ' Last output: ' . implode(' ', $tail) : '';
            return ['success' => false, 'message' => 'Composer dependency repair failed.' . $detail];
        }

        return ['success' => true, 'message' => 'Composer install completed.'];
    }

    private function repairFromReleasePackage(): array
    {
        $root = dirname(__DIR__, 2);
        $version = $this->installedVersion($root);
        if ($version === '') {
            return ['success' => false, 'message' => 'Could not determine installed version for full release package repair.'];
        }
        if (!extension_loaded('zip')) {
            return ['success' => false, 'message' => 'PHP ZipArchive extension is not loaded, so the full release package cannot be extracted.'];
        }

        $vendorDir = $root . '/vendor';
        if (is_dir($vendorDir) && !is_writable($vendorDir)) {
            return ['success' => false, 'message' => 'The vendor directory is not writable by PHP, so dependencies cannot be repaired from the full release package.'];
        }
        if (!is_dir($vendorDir) && !is_writable($root)) {
            return ['success' => false, 'message' => 'The application root is not writable by PHP, so the vendor directory cannot be created.'];
        }

        $zipPath = sys_get_temp_dir() . '/andrea-helpdesk-dependencies-' . time() . '.zip';
        $tmpDir = sys_get_temp_dir() . '/andrea-helpdesk-dependencies-' . time();

        try {
            $url = str_contains($version, '-dev.')
                ? sprintf(self::DEV_RELEASE_ZIP_TEMPLATE, $version, $version)
                : sprintf(self::RELEASE_ZIP_TEMPLATE, $version, $version);
            $bytes = $this->downloadToFile($url, $zipPath);
            if ($bytes === false || $bytes < 1024) {
                return ['success' => false, 'message' => 'Could not download the full release package for dependency repair.'];
            }

            $zip = new \ZipArchive();
            if ($zip->open($zipPath) !== true) {
                return ['success' => false, 'message' => 'Could not open the full release package zip.'];
            }
            $zip->extractTo($tmpDir);
            $zip->close();

            $packageRoot = $tmpDir . '/andrea-helpdesk-' . $version;
            if (!is_dir($packageRoot)) {
                $dirs = array_values(array_filter(glob($tmpDir . '/*') ?: [], 'is_dir'));
                $packageRoot = count($dirs) === 1 ? $dirs[0] : $packageRoot;
            }

            $packageVendor = $packageRoot . '/vendor';
            if (!is_file($packageVendor . '/autoload.php')) {
                return ['success' => false, 'message' => 'The full release package did not contain vendor/autoload.php.'];
            }

            $this->copyDir($packageVendor, $vendorDir);
            return ['success' => true, 'message' => 'Dependencies repaired from the full release package.'];
        } finally {
            if (file_exists($zipPath)) {
                @unlink($zipPath);
            }
            if (is_dir($tmpDir)) {
                $this->removeDir($tmpDir);
            }
        }
    }

    private function installedVersion(string $root): string
    {
        $data = json_decode((string)@file_get_contents($root . '/version.json'), true);
        $version = is_array($data) ? (string)($data['version'] ?? '') : '';
        return preg_match('/^\d+\.\d+\.\d+(?:[-+][A-Za-z0-9._-]+)?$/', $version) === 1 ? $version : '';
    }

    private function downloadToFile(string $url, string $dest): int|false
    {
        if (function_exists('curl_init')) {
            $fp = fopen($dest, 'wb');
            if (!$fp) {
                return false;
            }

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_FILE => $fp,
                CURLOPT_TIMEOUT => 120,
                CURLOPT_USERAGENT => 'Andrea-Helpdesk-DependencyRepair/1.0',
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_FAILONERROR => true,
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

        if (!(bool)ini_get('allow_url_fopen')) {
            return false;
        }

        $in = @fopen($url, 'rb', false, stream_context_create(['http' => [
            'timeout' => 120,
            'header' => "User-Agent: Andrea-Helpdesk-DependencyRepair/1.0\r\n",
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

    private function copyDir(string $src, string $dst): void
    {
        if (!is_dir($dst) && !mkdir($dst, 0755, true) && !is_dir($dst)) {
            throw new \RuntimeException('Failed to create vendor directory during dependency repair.');
        }

        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iter as $item) {
            $rel = substr($item->getPathname(), strlen($src) + 1);
            $dest = $dst . DIRECTORY_SEPARATOR . $rel;
            if ($item->isDir()) {
                if (!is_dir($dest) && !mkdir($dest, 0755, true) && !is_dir($dest)) {
                    throw new \RuntimeException('Failed to create dependency directory: ' . $dest);
                }
                continue;
            }

            $dir = dirname($dest);
            if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new \RuntimeException('Failed to create dependency directory: ' . $dir);
            }
            if (!@copy($item->getPathname(), $dest)) {
                throw new \RuntimeException('Failed to copy dependency file: ' . $dest);
            }
        }
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iter as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}

<?php
declare(strict_types=1);

namespace Andrea\Helpdesk\Core;

class DependencyRepairService
{
    public function ensureClasses(array $classes): array
    {
        $missing = $this->missingClasses($classes);
        if (!$missing) {
            return ['repaired' => false, 'available' => true, 'message' => 'Dependencies available.'];
        }

        [$composerOk, $composerDetail] = $this->composerAvailable();
        if (!$composerOk) {
            return ['repaired' => false, 'available' => false, 'message' => $composerDetail];
        }

        $root = dirname(__DIR__, 2);
        $result = $this->runComposerInstall($root);
        if (!$result['success']) {
            return ['repaired' => false, 'available' => false, 'message' => $result['message']];
        }

        $autoload = $root . '/vendor/autoload.php';
        if (is_file($autoload)) {
            require $autoload;
        }

        $stillMissing = $this->missingClasses($classes);
        if ($stillMissing) {
            return [
                'repaired' => true,
                'available' => false,
                'message' => 'Composer ran, but required classes are still missing: ' . implode(', ', $stillMissing),
            ];
        }

        return ['repaired' => true, 'available' => true, 'message' => 'Composer dependencies repaired.'];
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
}

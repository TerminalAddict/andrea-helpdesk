<?php
declare(strict_types=1);

namespace Andrea\Helpdesk\Core;

use Andrea\Helpdesk\Core\Exceptions\HttpException;
use Andrea\Helpdesk\Settings\SettingsRepository;

class VersionService
{
    public const CHANNEL_STABLE = 'stable';
    public const CHANNEL_DEVELOPMENT = 'development';

    private const DEFAULT_STABLE_LATEST_URL = 'https://raw.githubusercontent.com/TerminalAddict/andrea-helpdesk/main/version.json';
    private const DEFAULT_DEVELOPMENT_LATEST_URL = 'https://raw.githubusercontent.com/TerminalAddict/andrea-helpdesk/development/version.json';

    public function getInstalled(): array
    {
        $path = dirname(__DIR__, 2) . '/version.json';
        $data = json_decode((string)file_get_contents($path), true);
        $data = is_array($data) ? $data : ['version' => 'unknown'];
        $data['channel'] = $this->installedChannel((string)($data['version'] ?? ''));
        return $data;
    }

    public function getLatest(?string $channel = null): array
    {
        $channel = $this->normalizeChannel($channel ?: $this->getUpdateChannel());
        $raw = $this->httpGet($this->latestUrl($channel));
        if ($raw === false) {
            throw new HttpException('Could not reach update metadata source', 502);
        }

        $data = json_decode($raw, true);
        if (!is_array($data) || empty($data['version'])) {
            throw new HttpException('Invalid version data received from GitHub', 502);
        }

        $installed = $this->getInstalled();
        $installedVersion = (string)($installed['version'] ?? 'unknown');
        $latestVersion = (string)$data['version'];
        $comparison = $this->compare($latestVersion, $installedVersion);

        $data['channel'] = $channel;
        $data['installed_version'] = $installedVersion;
        $data['installed_channel'] = (string)($installed['channel'] ?? $this->installedChannel($installedVersion));
        $data['update_available'] = $comparison > 0;
        $data['waiting_for_stable'] = $channel === self::CHANNEL_STABLE
            && $this->installedChannel($installedVersion) === self::CHANNEL_DEVELOPMENT
            && $comparison < 0;
        if ($data['waiting_for_stable']) {
            $data['message'] = 'This install is ahead of the stable channel. It will receive stable updates again when stable reaches or exceeds the installed development version.';
        }

        return $data;
    }

    public function latestUrl(?string $channel = null): string
    {
        $override = trim((string)(getenv('UPDATE_VERSION_URL') ?: ''));
        if ($override !== '' && filter_var($override, FILTER_VALIDATE_URL)) {
            return $override;
        }

        return $this->normalizeChannel($channel ?: $this->getUpdateChannel()) === self::CHANNEL_DEVELOPMENT
            ? self::DEFAULT_DEVELOPMENT_LATEST_URL
            : self::DEFAULT_STABLE_LATEST_URL;
    }

    public function getUpdateChannel(): string
    {
        $env = getenv('UPDATE_CHANNEL');
        if (is_string($env) && $env !== '') {
            return $this->normalizeChannel($env);
        }

        try {
            $repo = new SettingsRepository();
            return $this->normalizeChannel((string)$repo->get('update_channel', self::CHANNEL_STABLE));
        } catch (\Throwable) {
            return self::CHANNEL_STABLE;
        }
    }

    public function normalizeChannel(string $channel): string
    {
        $channel = strtolower(trim($channel));
        return in_array($channel, [self::CHANNEL_STABLE, self::CHANNEL_DEVELOPMENT], true)
            ? $channel
            : self::CHANNEL_STABLE;
    }

    public function installedChannel(string $version): string
    {
        return preg_match('/-dev(?:\.|$)/i', $version) === 1
            ? self::CHANNEL_DEVELOPMENT
            : self::CHANNEL_STABLE;
    }

    public function releaseTag(string $version, ?string $channel = null): string
    {
        return $this->normalizeChannel($channel ?: $this->installedChannel($version)) === self::CHANNEL_DEVELOPMENT
            ? 'dev-v' . $version
            : 'v' . $version;
    }

    public function compare(string $a, string $b): int
    {
        $left = $this->parseVersion($a);
        $right = $this->parseVersion($b);
        if (!$left || !$right) {
            return version_compare($a, $b);
        }

        foreach (['major', 'minor', 'patch'] as $part) {
            $diff = $left[$part] <=> $right[$part];
            if ($diff !== 0) {
                return $diff;
            }
        }

        if ($left['dev'] === null && $right['dev'] === null) {
            return 0;
        }
        if ($left['dev'] === null) {
            return 1;
        }
        if ($right['dev'] === null) {
            return -1;
        }
        return $left['dev'] <=> $right['dev'];
    }

    /**
     * @return array{major:int,minor:int,patch:int,dev:int|null}|null
     */
    private function parseVersion(string $version): ?array
    {
        if (!preg_match('/^(\d+)\.(\d+)\.(\d+)(?:-dev\.(\d+))?$/', trim($version), $m)) {
            return null;
        }

        return [
            'major' => (int)$m[1],
            'minor' => (int)$m[2],
            'patch' => (int)$m[3],
            'dev' => isset($m[4]) && $m[4] !== '' ? (int)$m[4] : null,
        ];
    }

    private function httpGet(string $url): string|false
    {
        if (function_exists('curl_exec')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 5,
                CURLOPT_USERAGENT      => 'Andrea-Helpdesk-UpdateCheck/1.0',
                CURLOPT_FOLLOWLOCATION => true,
            ]);
            $body   = curl_exec($ch);
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            return ($body !== false && $status === 200) ? $body : false;
        }

        $ctx = stream_context_create(['http' => [
            'timeout' => 5,
            'header'  => "User-Agent: Andrea-Helpdesk-UpdateCheck/1.0\r\n",
        ]]);

        return @file_get_contents($url, false, $ctx);
    }
}

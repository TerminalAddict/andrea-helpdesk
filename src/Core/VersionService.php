<?php
declare(strict_types=1);

namespace Andrea\Helpdesk\Core;

use Andrea\Helpdesk\Core\Exceptions\HttpException;

class VersionService
{
    private const DEFAULT_LATEST_URL = 'https://raw.githubusercontent.com/TerminalAddict/andrea-helpdesk/main/version.json';

    public function getInstalled(): array
    {
        $path = dirname(__DIR__, 2) . '/version.json';
        $data = json_decode((string)file_get_contents($path), true);
        return is_array($data) ? $data : ['version' => 'unknown'];
    }

    public function getLatest(): array
    {
        $raw = $this->httpGet($this->latestUrl());
        if ($raw === false) {
            throw new HttpException('Could not reach update metadata source', 502);
        }

        $data = json_decode($raw, true);
        if (!is_array($data) || empty($data['version'])) {
            throw new HttpException('Invalid version data received from GitHub', 502);
        }

        return $data;
    }

    public function latestUrl(): string
    {
        $url = trim((string)(getenv('UPDATE_VERSION_URL') ?: self::DEFAULT_LATEST_URL));
        return filter_var($url, FILTER_VALIDATE_URL) ? $url : self::DEFAULT_LATEST_URL;
    }

    public function compare(string $a, string $b): int
    {
        $left  = array_map('intval', explode('.', $a));
        $right = array_map('intval', explode('.', $b));
        $len   = max(count($left), count($right));

        for ($i = 0; $i < $len; $i++) {
            $diff = ($left[$i] ?? 0) <=> ($right[$i] ?? 0);
            if ($diff !== 0) {
                return $diff;
            }
        }

        return 0;
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

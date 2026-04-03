<?php
declare(strict_types=1);

namespace Andrea\Helpdesk\Core;

use Andrea\Helpdesk\Core\Exceptions\HttpException;

class VersionController
{
    private const LATEST_URL = 'https://raw.githubusercontent.com/TerminalAddict/andrea-helpdesk/main/version.json';

    public function index(): void
    {
        $path = dirname(__DIR__, 2) . '/version.json';
        $data = json_decode((string)file_get_contents($path), true);
        Response::success($data ?? ['version' => 'unknown']);
    }

    public function latest(): void
    {
        $raw = $this->httpGet(self::LATEST_URL);
        if ($raw === false) {
            throw new HttpException('Could not reach GitHub', 502);
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || empty($data['version'])) {
            throw new HttpException('Invalid version data received from GitHub', 502);
        }
        Response::success($data);
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

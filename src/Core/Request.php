<?php
declare(strict_types=1);

namespace Andrea\Helpdesk\Core;

use Andrea\Helpdesk\Core\Exceptions\ValidationException;

class Request
{
    public string $method;
    public string $path;
    public array $query;
    public array $body;
    public array $files;
    public array $headers;
    public array $params = [];
    public ?object $agent = null;
    public ?object $customer = null;

    private function __construct() {}

    public static function fromGlobals(): self
    {
        $request = new self();
        $request->method  = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $request->path    = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $request->query   = $_GET;
        $request->files   = $_FILES;

        // Normalise path: strip query string, remove double slashes
        $request->path = '/' . ltrim(preg_replace('#/+#', '/', $request->path), '/');

        // Parse body
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (str_contains($contentType, 'application/json')) {
            $raw = file_get_contents('php://input');
            $request->body = json_decode($raw, true) ?? [];
        } else {
            $request->body = $_POST;
        }

        // Collect headers
        $request->headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $header = str_replace('_', '-', strtolower(substr($key, 5)));
                $request->headers[$header] = $value;
            }
        }

        return $request;
    }

    public function getHeader(string $name): ?string
    {
        $key = strtolower(str_replace('_', '-', $name));
        return $this->headers[$key] ?? null;
    }

    public function bearerToken(): ?string
    {
        $auth = $this->getHeader('authorization') ?? '';
        if (str_starts_with($auth, 'Bearer ')) {
            return substr($auth, 7);
        }
        return null;
    }

    public function ip(): string
    {
        return $_SERVER['HTTP_X_FORWARDED_FOR']
            ?? $_SERVER['REMOTE_ADDR']
            ?? '0.0.0.0';
    }

    public function isJson(): bool
    {
        return str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json');
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $this->query[$key] ?? $default;
    }

    public function validate(array $rules): array
    {
        $errors = [];
        $data   = [];

        foreach ($rules as $field => $rule) {
            $value    = $this->input($field);
            $ruleList = explode('|', $rule);

            foreach ($ruleList as $r) {
                [$ruleName, $ruleParam] = explode(':', $r . ':') + ['', ''];

                switch (trim($ruleName)) {
                    case 'required':
                        if ($value === null || $value === '') {
                            $errors[$field][] = "{$field} is required";
                        }
                        break;
                    case 'email':
                        if ($value && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                            $errors[$field][] = "{$field} must be a valid email";
                        }
                        break;
                    case 'min':
                        if ($value !== null && strlen((string)$value) < (int)$ruleParam) {
                            $errors[$field][] = "{$field} must be at least {$ruleParam} characters";
                        }
                        break;
                    case 'max':
                        if ($value !== null && strlen((string)$value) > (int)$ruleParam) {
                            $errors[$field][] = "{$field} must not exceed {$ruleParam} characters";
                        }
                        break;
                    case 'in':
                        $allowed = explode(',', $ruleParam);
                        if ($value !== null && !in_array($value, $allowed, true)) {
                            $errors[$field][] = "{$field} must be one of: " . implode(', ', $allowed);
                        }
                        break;
                    case 'integer':
                        if ($value !== null && !filter_var($value, FILTER_VALIDATE_INT)) {
                            $errors[$field][] = "{$field} must be an integer";
                        }
                        break;
                }
            }

            $data[$field] = $value;
        }

        if (!empty($errors)) {
            throw new ValidationException($errors);
        }

        return $data;
    }
}

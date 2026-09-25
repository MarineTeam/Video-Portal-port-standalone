<?php

declare(strict_types=1);

namespace App\Core;

/**
 * One HTTP request, with the path made relative to the base path the app is
 * installed under — so every route is written as if the site sat at the
 * domain root, and example.org/church/ works without a route knowing.
 */
final class Request
{
    /** @var array<string, mixed> */
    private array $attributes = [];

    /** @var array<string, mixed>|null */
    private ?array $json = null;

    /**
     * @param array<string, string> $query
     * @param array<string, mixed> $post
     * @param array<string, string> $cookies
     * @param array<string, string> $headers lower-cased names
     * @param array<string, mixed> $files
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly array $post = [],
        public readonly array $cookies = [],
        public readonly array $headers = [],
        public readonly string $body = '',
        public readonly string $ip = '0.0.0.0',
        public readonly bool $https = false,
        public readonly string $host = 'localhost',
        public readonly array $files = [],
        public readonly string $id = '',
    ) {
    }

    /**
     * Builds the request from PHP's globals.
     *
     * X-Forwarded-Proto and X-Forwarded-For are only believed when the admin
     * said the site sits behind a proxy: on shared hosting without one, any
     * visitor can send those headers.
     */
    public static function fromGlobals(string $basePath, bool $trustProxy): self
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = (string) $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }
        if (isset($_SERVER['CONTENT_LENGTH'])) {
            $headers['content-length'] = (string) $_SERVER['CONTENT_LENGTH'];
        }

        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        if ($trustProxy) {
            if (isset($headers['x-forwarded-proto'])) {
                $https = strtolower(trim(explode(',', $headers['x-forwarded-proto'])[0])) === 'https';
            }
            if (isset($headers['x-forwarded-for'])) {
                $candidate = trim(explode(',', $headers['x-forwarded-for'])[0]);
                if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                    $ip = $candidate;
                }
            }
        }

        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = rawurldecode((string) parse_url($uri, PHP_URL_PATH));
        $path = self::stripBase($path, $basePath);

        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $body = in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)
            && !str_starts_with($headers['content-type'] ?? '', 'multipart/form-data')
            ? (string) file_get_contents('php://input')
            : '';

        /** @var array<string, string> $query */
        $query = array_map(fn ($v) => is_string($v) ? $v : '', $_GET);
        return new self(
            method: $method,
            path: $path,
            query: $query,
            post: $_POST,
            cookies: array_map('strval', $_COOKIE),
            headers: $headers,
            body: $body,
            ip: $ip,
            https: $https,
            host: strtolower((string) ($headers['host'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost')),
            files: $_FILES,
            id: bin2hex(random_bytes(8)),
        );
    }

    public static function stripBase(string $path, string $basePath): string
    {
        $basePath = rtrim($basePath, '/');
        if ($basePath !== '' && ($path === $basePath || str_starts_with($path, $basePath . '/'))) {
            $path = substr($path, strlen($basePath));
        }
        $path = '/' . ltrim($path, '/');
        // A path is never allowed to walk; routes only ever see a clean one.
        if (str_contains($path, "\0")) {
            return '/';
        }
        return $path === '/' ? '/' : rtrim($path, '/');
    }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    public function query(string $key, ?string $default = null): ?string
    {
        $value = $this->query[$key] ?? null;
        return is_string($value) ? $value : $default;
    }

    public function cookie(string $name): ?string
    {
        return $this->cookies[$name] ?? null;
    }

    public function isJson(): bool
    {
        return str_contains($this->header('content-type', '') ?? '', 'application/json');
    }

    public function isApi(): bool
    {
        return str_starts_with($this->path, '/api/') || $this->path === '/api';
    }

    public function wantsJson(): bool
    {
        return $this->isApi() || str_contains($this->header('accept', '') ?? '', 'application/json');
    }

    /** @return array<string, mixed> */
    public function json(): array
    {
        if ($this->json === null) {
            $decoded = $this->body === '' ? [] : json_decode($this->body, true);
            $this->json = is_array($decoded) ? $decoded : [];
        }
        return $this->json;
    }

    /**
     * The request's input: a JSON body, or a form's fields.
     *
     * @return array<string, mixed>
     */
    public function input(): array
    {
        return $this->isJson() ? $this->json() : $this->post;
    }

    public function isSafeMethod(): bool
    {
        return in_array($this->method, ['GET', 'HEAD', 'OPTIONS'], true);
    }

    public function withAttribute(string $key, mixed $value): self
    {
        $this->attributes[$key] = $value;
        return $this;
    }

    public function attribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    public function bearerToken(): ?string
    {
        $header = $this->header('authorization') ?? '';
        return preg_match('/^Bearer\s+(\S+)$/i', $header, $m) ? $m[1] : null;
    }
}

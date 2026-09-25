<?php

declare(strict_types=1);

namespace App\Core;

/**
 * An HTTP response: a status, headers, and either a string body or a
 * callable that streams one (a file, a feed, a backup).
 */
final class Response
{
    /** @var array<string, string> */
    public array $headers = [];

    /** @var list<string> Set-Cookie lines */
    public array $cookies = [];

    /** @var (callable(): void)|null */
    public $stream = null;

    public function __construct(public int $status = 200, public string $body = '', array $headers = [])
    {
        foreach ($headers as $name => $value) {
            $this->header($name, $value);
        }
    }

    public function header(string $name, string $value): self
    {
        // No CR or LF in a header, whatever reached here: that is a split.
        $this->headers[self::canonical($name)] = str_replace(["\r", "\n"], '', $value);
        return $this;
    }

    public function withoutHeader(string $name): self
    {
        unset($this->headers[self::canonical($name)]);
        return $this;
    }

    public function getHeader(string $name): ?string
    {
        return $this->headers[self::canonical($name)] ?? null;
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($status, $body, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public static function text(string $body, int $status = 200, string $type = 'text/plain; charset=utf-8'): self
    {
        return new self($status, $body, ['Content-Type' => $type]);
    }

    public static function json(mixed $data, int $status = 200): self
    {
        $body = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        return new self($status, $body, ['Content-Type' => 'application/json; charset=utf-8']);
    }

    /** The error shape every API route answers with: {error, code}. */
    public static function error(string $message, int $status, ?string $code = null): self
    {
        return self::json(['error' => $message, 'code' => $code ?? self::codeFor($status)], $status);
    }

    public static function redirect(string $location, int $status = 303): self
    {
        return new self($status, '', ['Location' => $location]);
    }

    public static function notFound(): self
    {
        return self::error('Not found', 404);
    }

    /** @param callable(): void $writer */
    public static function stream(callable $writer, int $status = 200, array $headers = []): self
    {
        $response = new self($status, '', $headers);
        $response->stream = $writer;
        return $response;
    }

    /**
     * @param array{expires?: int, path?: string, secure?: bool, httponly?: bool, samesite?: string, maxAge?: int} $options
     */
    public function cookie(string $name, string $value, array $options = []): self
    {
        $parts = [rawurlencode($name) . '=' . rawurlencode($value)];
        if (isset($options['maxAge'])) {
            $parts[] = 'Max-Age=' . $options['maxAge'];
            $parts[] = 'Expires=' . gmdate('D, d M Y H:i:s \G\M\T', time() + $options['maxAge']);
        }
        $parts[] = 'Path=' . ($options['path'] ?? '/');
        if ($options['secure'] ?? false) {
            $parts[] = 'Secure';
        }
        if ($options['httponly'] ?? true) {
            $parts[] = 'HttpOnly';
        }
        $parts[] = 'SameSite=' . ($options['samesite'] ?? 'Lax');
        $this->cookies[] = implode('; ', $parts);
        return $this;
    }

    public function forgetCookie(string $name, string $path = '/', bool $secure = false): self
    {
        return $this->cookie($name, '', ['maxAge' => 0, 'path' => $path, 'secure' => $secure]);
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                header("$name: $value", true);
            }
            foreach ($this->cookies as $cookie) {
                header('Set-Cookie: ' . $cookie, false);
            }
        }
        if ($this->stream !== null) {
            ($this->stream)();
            return;
        }
        echo $this->body;
    }

    private static function canonical(string $name): string
    {
        return implode('-', array_map('ucfirst', explode('-', strtolower($name))));
    }

    private static function codeFor(int $status): string
    {
        return match ($status) {
            400 => 'bad_request',
            401 => 'unauthorized',
            403 => 'forbidden',
            404 => 'not_found',
            405 => 'method_not_allowed',
            409 => 'conflict',
            413 => 'too_large',
            415 => 'unsupported_media_type',
            422 => 'invalid',
            429 => 'rate_limited',
            503 => 'unavailable',
            default => $status >= 500 ? 'server_error' : 'error',
        };
    }
}

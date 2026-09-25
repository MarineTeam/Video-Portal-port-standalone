<?php

declare(strict_types=1);

namespace App\Core;

final class HttpResponse
{
    /** @param array<string, string> $headers lower-cased names */
    public function __construct(public readonly int $status, public readonly array $headers, public readonly string $body)
    {
    }

    public function ok(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /** @return array<mixed>|null */
    public function json(): ?array
    {
        $decoded = json_decode($this->body, true);
        return is_array($decoded) ? $decoded : null;
    }
}

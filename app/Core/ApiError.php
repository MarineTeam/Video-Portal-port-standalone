<?php

declare(strict_types=1);

namespace App\Core;

/**
 * An error a route means to answer with: its message is safe to show, and
 * its status is the response's. Anything else that escapes a route is
 * answered generically — an upstream message is never echoed.
 */
class ApiError extends \RuntimeException
{
    public function __construct(string $message, public readonly int $status = 400, public readonly ?string $errorCode = null)
    {
        parent::__construct($message);
    }

    public static function notFound(string $what = 'Not found'): self
    {
        return new self($what, 404);
    }

    public static function forbidden(string $message = 'Forbidden'): self
    {
        return new self($message, 403);
    }

    public static function unauthorized(string $message = 'Sign in required'): self
    {
        return new self($message, 401);
    }

    public static function invalid(string $message): self
    {
        return new self($message, 400, 'invalid');
    }

    public static function conflict(string $message): self
    {
        return new self($message, 409);
    }

    public static function tooMany(string $message = 'Too many requests. Please wait a moment.'): self
    {
        return new self($message, 429);
    }
}

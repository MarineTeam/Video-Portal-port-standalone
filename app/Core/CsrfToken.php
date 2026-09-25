<?php

declare(strict_types=1);

namespace App\Core;

/** The current request's CSRF token, for templates and the page's meta tag. */
final class CsrfToken
{
    private static ?\Closure $resolver = null;

    public static function resolveWith(\Closure $resolver): void
    {
        self::$resolver = $resolver;
    }

    public static function current(): string
    {
        return self::$resolver === null ? '' : (string) (self::$resolver)();
    }
}

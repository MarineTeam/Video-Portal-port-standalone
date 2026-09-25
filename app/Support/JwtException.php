<?php

declare(strict_types=1);

namespace App\Support;

/** A token that doesn't hold up; unknownKey says a JWKS refresh might help. */
final class JwtException extends \RuntimeException
{
    public function __construct(string $message, public readonly bool $unknownKey = false)
    {
        parent::__construct($message);
    }
}

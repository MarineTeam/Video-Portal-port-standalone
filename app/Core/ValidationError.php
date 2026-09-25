<?php

declare(strict_types=1);

namespace App\Core;

final class ValidationError extends \RuntimeException
{
    /** @param array<string, string> $fields field => sentence */
    public function __construct(public readonly array $fields, string $message = 'Some fields need attention.')
    {
        parent::__construct($fields === [] ? $message : (string) reset($fields));
    }
}

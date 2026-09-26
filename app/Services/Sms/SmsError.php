<?php

declare(strict_types=1);

namespace App\Services\Sms;

/**
 * Why one text did not go.
 *
 * It carries the provider's own sentence ("is not a valid phone number")
 * rather than a code, because the broadcast screen shows it beside the
 * recipient's name and somebody has to be able to act on it.
 */
final class SmsError extends \RuntimeException
{
    public function __construct(string $message, public readonly ?string $providerCode = null, public readonly bool $permanent = false)
    {
        parent::__construct($message);
    }

    /** A number that will never work, however many times it is tried. */
    public static function permanent(string $message, ?string $code = null): self
    {
        return new self($message, $code, true);
    }
}

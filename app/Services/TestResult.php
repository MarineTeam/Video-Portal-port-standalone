<?php

declare(strict_types=1);

namespace App\Services;

/**
 * The outcome of a provider's test, in words a volunteer can act on.
 * `needsCode` means a message went out carrying a code the admin must type
 * back — for mail() and SMS, where "accepted" proves nothing about delivery.
 */
final class TestResult
{
    /** @param list<string> $steps what was checked, in order */
    private function __construct(
        public readonly bool $ok,
        public readonly string $message,
        public readonly array $steps = [],
        public readonly ?string $expectedCodeHash = null,
    ) {
    }

    /** @param list<string> $steps */
    public static function ok(string $message, array $steps = []): self
    {
        return new self(true, $message, $steps);
    }

    /** @param list<string> $steps */
    public static function fail(string $message, array $steps = []): self
    {
        return new self(false, $message, $steps);
    }

    /** @param list<string> $steps */
    public static function needsCode(string $message, string $code, array $steps = []): self
    {
        return new self(true, $message, $steps, hash('sha256', $code));
    }

    public function needsConfirmation(): bool
    {
        return $this->expectedCodeHash !== null;
    }
}

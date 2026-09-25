<?php

declare(strict_types=1);

namespace App\Services\Email;

final class SendResult
{
    private function __construct(public readonly string $status, public readonly ?string $messageId, public readonly ?string $error)
    {
    }

    public static function sent(?string $messageId = null): self
    {
        return new self('sent', $messageId, null);
    }

    public static function failed(string $error): self
    {
        return new self('failed', null, $error);
    }

    /** Email isn't configured: recorded, not sent. */
    public static function skipped(string $why): self
    {
        return new self('skipped', null, $why);
    }

    public function ok(): bool
    {
        return $this->status === 'sent';
    }
}

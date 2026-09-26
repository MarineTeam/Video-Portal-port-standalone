<?php

declare(strict_types=1);

namespace App\Services\Sms;

/** What the provider called the message it accepted. */
final class SmsResult
{
    public function __construct(public readonly string $messageId, public readonly ?string $status = null)
    {
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Services\BaseProvider;
use App\Services\TestContext;
use App\Services\TestResult;

/**
 * Email not set up: a first-class state. Every send is a recorded no-op, so
 * /admin/email shows what would have gone out, and the screens that need
 * email say so.
 */
final class NoEmailProvider extends BaseProvider implements EmailProvider
{
    public static function slot(): string
    {
        return 'email';
    }

    public static function id(): string
    {
        return 'none';
    }

    public static function label(): string
    {
        return 'Not set up';
    }

    public static function limits(): string
    {
        return 'Nothing is sent. Password resets and magic links are unavailable; an administrator sets passwords instead.';
    }

    public function test(TestContext $context): TestResult
    {
        return TestResult::ok('Email is switched off; nothing will be sent.');
    }

    public function send(Message $message, string $from): SendResult
    {
        return SendResult::skipped('Email is not set up.');
    }
}

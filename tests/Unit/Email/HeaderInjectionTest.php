<?php

declare(strict_types=1);

namespace Tests\Unit\Email;

use App\Modules\Files\Images;
use App\Services\Email\Message;
use App\Services\Email\PhpMailProvider;
use App\Services\Email\SmtpProvider;
use PHPUnit\Framework\TestCase;

/**
 * A line break in an address or a subject is a second message.
 *
 * Every provider is handed a Message, so the check belongs in its
 * constructor rather than in ten providers: a new one cannot forget it,
 * because it cannot be given a Message that was never built.
 */
final class HeaderInjectionTest extends TestCase
{
    public function test_1_a_line_break_in_the_recipient_is_refused(): void
    {
        foreach ([
            "ruth@example.org\nBcc: everyone@example.org",
            "ruth@example.org\r\nBcc: everyone@example.org",
            "ruth@example.org\rX-Injected: 1",
            "ruth@example.org\0",
        ] as $to) {
            try {
                new Message($to, 'A subject', 'Some text');
                self::fail('a break in the recipient should be refused: ' . var_export($to, true));
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('line breaks', $e->getMessage());
            }
        }
    }

    public function test_2_a_line_break_in_the_subject_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Message('ruth@example.org', "Welcome\nBcc: everyone@example.org", 'Some text');
    }

    public function test_3_a_line_break_in_the_reply_address_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Message('ruth@example.org', 'A subject', 'text', null, "office@example.org\nBcc: x@y.z");
    }

    public function test_4_the_body_may_have_all_the_line_breaks_it_likes(): void
    {
        // The body is not a header; refusing breaks there would refuse every
        // real message.
        $message = new Message('ruth@example.org', 'A subject', "Dear Ruth,\n\nThe service is at ten.\n");
        self::assertStringContainsString("\n\n", $message->text);
    }

    public function test_5_a_send_as_address_with_a_break_is_refused_by_the_providers(): void
    {
        // The "send as" comes from settings rather than from a Message, so
        // each provider checks it where it reads it.
        $message = new Message('ruth@example.org', 'A subject', 'text');
        foreach ([new SmtpProvider([]), new PhpMailProvider([])] as $provider) {
            $result = $provider->send($message, "office@example.org\nBcc: everyone@example.org");
            self::assertSame('failed', $result->status, $provider::id());
            self::assertNotNull($result->error, $provider::id());
        }
    }

}

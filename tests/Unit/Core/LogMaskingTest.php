<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Log;
use PHPUnit\Framework\TestCase;

/**
 * What must not be in a log file.
 *
 * A log is the file most likely to be pasted into a support forum by
 * somebody trying to get help, and on shared hosting /admin/logs is how an
 * administrator reads it at all. Anything in it has effectively been
 * published, so the masking is a security rule rather than tidiness.
 */
final class LogMaskingTest extends TestCase
{
    public function test_1_a_bearer_token_is_masked_and_the_scheme_kept(): void
    {
        // The scheme stays because "which kind of credential was refused" is
        // the useful half and the only half that is safe.
        self::assertSame(
            'GET /api/v1 Authorization: Bearer [masked]',
            Log::mask('GET /api/v1 Authorization: Bearer mt_live_abc123DEF456.ghi-jkl_mno='),
        );
        self::assertSame('Basic [masked]', Log::mask('Basic dXNlcjpwYXNzd29yZA=='));
    }

    public function test_2_one_of_our_own_api_keys_is_masked_wherever_it_appears(): void
    {
        self::assertSame(
            'The key mt_live_[masked] was refused',
            Log::mask('The key mt_live_9f8e7d6c5b4a39281706 was refused'),
        );
    }

    public function test_3_a_secret_written_as_a_pair_is_masked_whatever_it_is_called(): void
    {
        foreach (['password', 'pass', 'secret', 'token', 'api_key', 'apikey', 'key'] as $name) {
            self::assertStringNotContainsString(
                'hunter2',
                Log::mask("$name=hunter2 and the rest of the line"),
                $name,
            );
        }
        self::assertSame('password="[masked]"', Log::mask('password="hunter2"'));
        self::assertSame('token: [masked]', Log::mask('token: abc.def.ghi'));
    }

    public function test_4_the_rest_of_the_line_survives_so_the_log_is_still_readable(): void
    {
        self::assertSame(
            'POST /auth/login email=ruth@example.org password=[masked] ip=203.0.113.9',
            Log::mask('POST /auth/login email=ruth@example.org password=s3cr3t! ip=203.0.113.9'),
        );
    }

    public function test_5_a_context_key_that_names_a_secret_is_masked_whatever_it_holds(): void
    {
        $masked = Log::maskArray([
            'route' => '/api/admin/services',
            'authorization' => 'Bearer abc',
            'Cookie' => 'mt_session=abc',
            'api_key' => 'k',
            'client_secret' => 'cs',
            'code_verifier' => 'cv',
            'app_key' => 'ak',
            'privateKey' => '-----BEGIN PRIVATE KEY-----',
            'sessionId' => 'abc',
        ]);
        self::assertSame('/api/admin/services', $masked['route'], 'and the useful part is kept');
        foreach (['authorization', 'Cookie', 'api_key', 'client_secret', 'code_verifier', 'app_key', 'privateKey', 'sessionId'] as $key) {
            self::assertSame('[masked]', $masked[$key], $key);
        }
    }

    public function test_6_a_secret_nested_inside_the_context_is_masked_too(): void
    {
        $masked = Log::maskArray([
            'provider' => ['id' => 'twilio', 'config' => ['auth_token' => 'abc', 'from' => '+15550000000']],
        ]);
        self::assertSame('[masked]', $masked['provider']['config']['auth_token']);
        self::assertSame('+15550000000', $masked['provider']['config']['from'], 'the number is not a secret');
        self::assertSame('twilio', $masked['provider']['id']);
    }

    public function test_7_a_secret_in_a_context_value_is_masked_even_under_an_innocent_key(): void
    {
        $masked = Log::maskArray(['message' => 'refused Authorization: Bearer abc123', 'url' => 'https://api.example.org/x?token=abc123']);
        self::assertStringNotContainsString('abc123', (string) $masked['message']);
        self::assertStringNotContainsString('abc123', (string) $masked['url']);
    }

    public function test_8_what_is_not_a_secret_is_left_alone(): void
    {
        // Masking too much makes a log useless, which is its own failure:
        // nobody can diagnose an outage from a page of [masked].
        $plain = 'Video cmu123 failed: the transcription service answered 503 after 12.4s';
        self::assertSame($plain, Log::mask($plain));

        $masked = Log::maskArray(['videoId' => 'cmu123', 'status' => 503, 'seconds' => 12.4, 'ok' => false]);
        self::assertSame(['videoId' => 'cmu123', 'status' => 503, 'seconds' => 12.4, 'ok' => false], $masked);
    }

    public function test_9_masking_does_not_change_a_line_that_has_nothing_to_hide_twice(): void
    {
        $once = Log::mask('token=abc secret=def');
        self::assertSame($once, Log::mask($once), 'a masked line stays masked rather than being chewed further');
    }
}

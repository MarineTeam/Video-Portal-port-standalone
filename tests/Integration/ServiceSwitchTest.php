<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * Switching a service, and the four ways the switch is refused.
 *
 * The rule the whole screen exists for: a provider is tested before it is
 * made active, and the pass is bound to the settings that were tested. A
 * church whose email silently stops working finds out the week somebody
 * needs a password reset, which is why "it saved" is not good enough and
 * "it answered" is.
 */
final class ServiceSwitchTest extends ServerTestCase
{
    protected static function prefix(): string
    {
        return 'svc_';
    }

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::install();
    }

    protected function setUp(): void
    {
        parent::setUp();
        self::connect(self::prefix())->run('DELETE FROM {{services}}');
        self::flushCache();
    }

    /** @return array{status: int, body: string, location: ?string} */
    private static function form(string $slot, string $provider, array $fields, string $action): array
    {
        $path = "/admin/providers/$slot/$provider/$action";
        $answer = self::http('POST', $path, ['_csrf' => self::csrf("/admin/providers/$slot/$provider")] + $fields);
        return ['status' => $answer['status'], 'body' => (string) $answer['body'], 'location' => $answer['location']];
    }

    private static function activeProvider(string $slot): ?string
    {
        $row = self::connect(self::prefix())->one('SELECT provider FROM {{services}} WHERE slot = ? AND active = 1', [$slot]);
        return $row === null ? null : (string) $row['provider'];
    }

    public function test_1_a_switch_with_no_test_behind_it_is_refused(): void
    {
        // Straight to the switch, skipping the test: the token is what a
        // pass leaves behind, and there isn't one.
        $answer = self::form('email', 'smtp', ['token' => '', 'host' => 'smtp.example.org'], 'switch');

        self::assertSame(303, $answer['status']);
        self::assertNull(self::activeProvider('email'), 'nothing was switched');
    }

    public function test_2_a_token_somebody_made_up_is_refused(): void
    {
        $answer = self::form('email', 'smtp', ['token' => 'not-a-real-token'], 'switch');
        self::assertSame(303, $answer['status']);
        self::assertNull(self::activeProvider('email'));
    }

    public function test_3_a_failing_test_hands_back_no_token_to_switch_with(): void
    {
        // smtp.invalid does not resolve, so the test cannot pass.
        $tested = self::form('email', 'smtp', [
            'host' => 'smtp.invalid', 'port' => '587', 'username' => 'u', 'password' => 'p', 'from' => 'church@example.org',
        ], 'test');

        // 422 rather than 200: the page comes back with the settings still
        // in it and the reason on it, which is the difference between "that
        // did not work" and "something went wrong".
        self::assertSame(422, $tested['status']);
        // A pass is the only thing that puts a token on the page.
        self::assertStringNotContainsString('name="token" value="ey', $tested['body']);
        self::assertNull(self::activeProvider('email'), 'and nothing is active');
    }

    public function test_4_sign_in_cannot_be_switched_to_a_provider_nobody_has_signed_in_with(): void
    {
        // The lockout this guard exists for: an administrator switching the
        // site to an external provider they have never proved they can get
        // through, and then being unable to sign in to switch it back.
        $answer = self::form('auth', 'oidc', ['token' => 'anything'], 'switch');
        self::assertSame(303, $answer['status']);
        self::assertNotSame('oidc', self::activeProvider('auth'));
    }

    public function test_5_local_sign_in_is_what_the_site_falls_back_to(): void
    {
        // Whatever else happens, the way back in is still there.
        self::assertSame(200, self::http('GET', '/auth/login', null, 'guest')['status']);
        self::assertStringContainsString('name="password"', self::http('GET', '/auth/login', null, 'guest')['body']);
    }

    public function test_6_the_screen_lists_every_slot_and_what_is_in_it(): void
    {
        $page = self::http('GET', '/admin/providers');
        self::assertSame(200, $page['status']);
        foreach (['Sign-in', 'Video', 'Email', 'Files'] as $slot) {
            self::assertStringContainsString($slot, $page['body'], $slot);
        }
    }

    public function test_7_a_secret_already_saved_is_never_echoed_back(): void
    {
        $db = self::connect(self::prefix());
        $db->insert('services', [
            'id' => \App\Core\Id::new(), 'slot' => 'email', 'provider' => 'resend', 'active' => 0,
            'config' => (string) json_encode(['api_key' => \App\Core\Crypto::encrypt('re_a_real_looking_key'), 'from' => 'church@example.org']),
        ]);
        self::flushCache();

        $page = self::http('GET', '/admin/providers/email/resend');
        self::assertSame(200, $page['status']);
        self::assertStringNotContainsString('re_a_real_looking_key', $page['body'], 'a saved secret is write-only');
        self::assertStringContainsString('church@example.org', $page['body'], 'while what is not a secret is shown');
    }

    public function test_8_only_an_administrator_reaches_any_of_it(): void
    {
        self::member('ruth@test.example', 'ruth');
        foreach (['/admin/providers', '/admin/providers/email/smtp'] as $path) {
            self::assertContains(self::http('GET', $path, null, 'ruth')['status'], [302, 303, 403], $path);
        }
    }
}

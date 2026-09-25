<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * The Webhooks plugin's admin API through a real server: administrators
 * only, public addresses only (checked when saved), and the secret stored
 * encrypted and never shown again.
 */
final class WebhooksTest extends ServerTestCase
{
    protected static function prefix(): string
    {
        return 'wh_';
    }

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::install();
        self::member('ruth@test.example', 'ruth');
    }

    public function test_only_public_addresses_are_saved(): void
    {
        foreach (['http://127.0.0.1/hook', 'http://10.0.0.5/hook', 'http://169.254.169.254/latest', 'http://intranet/hook', 'ftp://example.org/x'] as $url) {
            self::assertSame(400, self::api('POST', '/api/admin/webhooks', ['url' => $url])['status'], $url);
        }
        self::assertSame(201, self::api('POST', '/api/admin/webhooks', ['url' => 'https://hooks.example.org/church'])['status']);
    }

    public function test_the_secret_is_encrypted_and_never_shown(): void
    {
        $saved = self::api('POST', '/api/admin/webhooks', ['url' => 'https://hooks.example.org/signed', 'secret' => 'correct-horse'])['json'];
        self::assertTrue($saved['secretSet']);
        self::assertArrayNotHasKey('secret', $saved);
        self::assertStringNotContainsString('correct-horse', self::http('GET', '/admin/webhooks')['body']);
        self::assertStringNotContainsString('correct-horse', self::http('GET', '/api/admin/webhooks')['body']);
        $stored = (string) self::connect(self::prefix())->value('SELECT secret FROM {{webhooks}} WHERE id = ?', [$saved['id']]);
        self::assertStringNotContainsString('correct-horse', $stored);
        self::assertFalse(self::api('PATCH', '/api/admin/webhooks/' . $saved['id'], ['secret' => ''])['json']['secretSet']);
    }

    public function test_only_administrators(): void
    {
        self::assertSame(403, self::api('GET', '/api/admin/webhooks', null, 'ruth')['status']);
        self::assertSame(403, self::api('POST', '/api/admin/webhooks', ['url' => 'https://hooks.example.org/x'], 'ruth')['status']);
    }
}

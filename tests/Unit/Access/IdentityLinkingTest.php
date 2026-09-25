<?php

declare(strict_types=1);

namespace Tests\Unit\Access;

use App\Modules\Access\IdentityLinking;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** lib/identity-linking.test.ts */
final class IdentityLinkingTest extends TestCase
{
    #[TestDox('uses the sub\'s own user when the identity is already known')]
    public function testKnownSub(): void
    {
        self::assertSame(['action' => 'use', 'userId' => 'u1'], IdentityLinking::decideLinking('u1', null, true));
    }

    #[TestDox('prefers sub over email, so a provider-side email change is a rename not a new account')]
    public function testSubOverEmail(): void
    {
        self::assertSame(['action' => 'use', 'userId' => 'u1'], IdentityLinking::decideLinking('u1', 'u2', true));
    }

    #[TestDox('still trusts a known sub even when its email is unverified')]
    public function testKnownSubUnverified(): void
    {
        self::assertSame(['action' => 'use', 'userId' => 'u1'], IdentityLinking::decideLinking('u1', 'u2', false));
    }

    #[TestDox('links a new identity to an existing member when the email is verified')]
    public function testLinkVerified(): void
    {
        self::assertSame(['action' => 'link', 'userId' => 'u2'], IdentityLinking::decideLinking(null, 'u2', true));
    }

    #[TestDox('refuses to link a new identity whose email isn\'t verified')]
    public function testRefuseUnverified(): void
    {
        self::assertSame(['action' => 'refuse', 'userId' => null], IdentityLinking::decideLinking(null, 'u2', false));
    }

    #[TestDox('creates a member for a genuinely new identity')]
    public function testCreate(): void
    {
        self::assertSame(['action' => 'create', 'userId' => null], IdentityLinking::decideLinking(null, null, true));
    }

    #[TestDox('creates for an unverified identity when nothing collides with it')]
    public function testCreateUnverified(): void
    {
        self::assertSame(['action' => 'create', 'userId' => null], IdentityLinking::decideLinking(null, null, false));
    }

    #[TestDox('namespaces every provider\'s sub except Auth0\'s, which is kept verbatim')]
    public function testStoredSub(): void
    {
        self::assertSame('google-oauth2|123', IdentityLinking::storedSub('auth0', 'google-oauth2|123'));
        self::assertSame('oidc-google|123', IdentityLinking::storedSub('oidc-google', '123'));
        self::assertSame('local|u1', IdentityLinking::storedSub('local', 'u1'));
    }
}

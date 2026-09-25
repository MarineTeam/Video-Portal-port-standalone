<?php

declare(strict_types=1);

namespace Tests\Unit\Library;

use App\Modules\Library\ShareLinks;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** lib/share-links.test.ts */
final class ShareLinksTest extends TestCase
{
    private static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-09-25 12:00:00', new \DateTimeZone('UTC'));
    }

    /** @return array<string, mixed> */
    private static function link(array $over = []): array
    {
        return $over + ['id' => 'l1', 'visibility' => 'PUBLIC', 'revoked_at' => null, 'expires_at' => null];
    }

    #[TestDox('shareLinkPolicy lets anyone share content that is already public, granting nothing')]
    public function test_public_content(): void
    {
        $this->assertSame(['allowed' => true, 'grantsAccess' => false, 'reason' => null], ShareLinks::policy(false, false, false));
    }

    #[TestDox('shareLinkPolicy lets a plain member share restricted content as a plain link')]
    public function test_plain_member_plain_link(): void
    {
        $this->assertSame(['allowed' => true, 'grantsAccess' => false, 'reason' => null], ShareLinks::policy(true, false, false));
    }

    #[TestDox('shareLinkPolicy refuses a plain member asking to override a restriction')]
    public function test_plain_member_override_refused(): void
    {
        $p = ShareLinks::policy(true, false, true);
        $this->assertFalse($p['allowed']);
        $this->assertFalse($p['grantsAccess']);
    }

    #[TestDox('shareLinkPolicy grants access when a permitted sharer asks for the override')]
    public function test_permitted_override(): void
    {
        $this->assertTrue(ShareLinks::policy(true, true, true)['grantsAccess']);
    }

    #[TestDox("shareLinkPolicy withholds the grant when a permitted sharer doesn't ask for it")]
    public function test_permitted_without_override(): void
    {
        $this->assertFalse(ShareLinks::policy(true, true, false)['grantsAccess']);
    }

    #[TestDox("shareLinkPolicy ignores an override asked for on content that isn't restricted")]
    public function test_override_on_public(): void
    {
        $this->assertSame(['allowed' => true, 'grantsAccess' => false, 'reason' => null], ShareLinks::policy(false, true, true));
    }

    #[TestDox('shareLinkStatus reports an unknown token as invalid')]
    public function test_invalid(): void
    {
        $this->assertSame('invalid', ShareLinks::status(null, [], null, self::now()));
    }

    #[TestDox("shareLinkStatus opens a public link for a visitor who isn't logged in")]
    public function test_public_for_visitor(): void
    {
        $this->assertSame('ok', ShareLinks::status(self::link(), [], null, self::now()));
    }

    #[TestDox('shareLinkStatus reports a revoked link as revoked, ahead of any other check')]
    public function test_revoked_first(): void
    {
        $this->assertSame('revoked', ShareLinks::status(self::link(['revoked_at' => '2026-09-01 00:00:00', 'expires_at' => '2026-01-01 00:00:00', 'visibility' => 'PRIVATE']), [], null, self::now()));
    }

    #[TestDox('shareLinkStatus treats an expiry exactly now as expired')]
    public function test_expiry_now(): void
    {
        $this->assertSame('expired', ShareLinks::status(self::link(['expires_at' => '2026-09-25 12:00:00']), [], null, self::now()));
    }

    #[TestDox('shareLinkStatus still opens a link whose expiry is in the future')]
    public function test_future_expiry(): void
    {
        $this->assertSame('ok', ShareLinks::status(self::link(['expires_at' => '2026-09-25 12:00:01']), [], null, self::now()));
    }

    #[TestDox('shareLinkStatus sends an anonymous visitor to log in for a private link')]
    public function test_private_anonymous(): void
    {
        $this->assertSame('login', ShareLinks::status(self::link(['visibility' => 'PRIVATE']), ['a@x.test'], null, self::now()));
    }

    #[TestDox('shareLinkStatus opens a private link for a listed recipient, whatever the case of their email')]
    public function test_private_recipient(): void
    {
        $this->assertSame('ok', ShareLinks::status(self::link(['visibility' => 'PRIVATE']), ['ruth@x.test'], ' Ruth@X.test ', self::now()));
    }

    #[TestDox("shareLinkStatus refuses a private link for someone it wasn't shared with")]
    public function test_private_stranger(): void
    {
        $this->assertSame('wrong_recipient', ShareLinks::status(self::link(['visibility' => 'PRIVATE']), ['ruth@x.test'], 'boaz@x.test', self::now()));
    }

    #[TestDox('shareLinkStatus prefers revoked over the recipient check, so a revoked link leaks nothing about who it was for')]
    public function test_revoked_before_recipient(): void
    {
        $this->assertSame('revoked', ShareLinks::status(self::link(['visibility' => 'PRIVATE', 'revoked_at' => '2026-09-01 00:00:00']), ['ruth@x.test'], 'boaz@x.test', self::now()));
    }

    #[TestDox('parseRecipientEmails splits on commas, semicolons, and whitespace alike')]
    public function test_parse_split(): void
    {
        $this->assertSame(['a@x.test', 'b@x.test', 'c@x.test', 'd@x.test'], ShareLinks::parseRecipientEmails("a@x.test, b@x.test;c@x.test\n d@x.test"));
    }

    #[TestDox("parseRecipientEmails lowercases and de-duplicates, so the unique index can't trip")]
    public function test_parse_dedupe(): void
    {
        $this->assertSame(['ruth@x.test'], ShareLinks::parseRecipientEmails('Ruth@X.test ruth@x.test RUTH@x.TEST'));
    }

    #[TestDox("parseRecipientEmails drops anything that isn't an email address")]
    public function test_parse_drops_junk(): void
    {
        $this->assertSame(['ok@x.test'], ShareLinks::parseRecipientEmails('nope, @x.test, ok@x.test, <script>@x'));
    }

    #[TestDox('parseRecipientEmails returns nothing for empty input')]
    public function test_parse_empty(): void
    {
        $this->assertSame([], ShareLinks::parseRecipientEmails(''));
        $this->assertSame([], ShareLinks::parseRecipientEmails(null));
    }

    #[TestDox('expiryFromDays treats null, undefined, and 0 as never expiring')]
    public function test_expiry_never(): void
    {
        $this->assertNull(ShareLinks::expiryFromDays(null, self::now()));
        $this->assertNull(ShareLinks::expiryFromDays(0, self::now()));
    }

    #[TestDox('expiryFromDays returns a date the given number of days out')]
    public function test_expiry_days(): void
    {
        $this->assertSame('2026-10-02 12:00:00', ShareLinks::expiryFromDays(7, self::now())?->format('Y-m-d H:i:s'));
    }

    public function test_cookie_holds_tokens_only(): void
    {
        $this->assertSame(['abcdefghijklmnop1234'], ShareLinks::cookieTokens('abcdefghijklmnop1234, bad token!, abcdefghijklmnop1234,x'));
        $this->assertSame(32, strlen(ShareLinks::newToken()));
    }
}

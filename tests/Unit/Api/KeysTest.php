<?php

declare(strict_types=1);

namespace Tests\Unit\Api;

use App\Modules\Api\Keys;
use PHPUnit\Framework\TestCase;

/** lib/api-keys.test.ts */
final class KeysTest extends TestCase
{
    public function testANewKeyIsRecognisableAndLong(): void
    {
        $key = Keys::newKey();
        $this->assertStringStartsWith('mt_live_', $key);
        $this->assertGreaterThan(40, strlen($key));
    }

    public function testANewKeyIsDifferentEveryTime(): void
    {
        $made = [];
        for ($i = 0; $i < 50; $i++) {
            $made[Keys::newKey()] = true;
        }
        $this->assertCount(50, $made);
    }

    public function testANewKeyIsUrlSafe(): void
    {
        // So it survives a config file and a curl command untouched.
        for ($i = 0; $i < 20; $i++) {
            $this->assertMatchesRegularExpression('/^mt_live_[A-Za-z0-9_-]+$/', Keys::newKey());
        }
    }

    public function testHashingIsStableAndOneWay(): void
    {
        $key = 'mt_live_abcdefghijklmnopqrstuvwxyz012345';
        $this->assertSame(Keys::hash($key), Keys::hash($key));
        $this->assertSame(64, strlen(Keys::hash($key)));
        $this->assertStringNotContainsString('abcdefgh', Keys::hash($key));
    }

    public function testTwoKeysSharingAPrefixHashDifferently(): void
    {
        $this->assertNotSame(Keys::hash('mt_live_aaaaaaaaaaaaaaaaaaaa1'), Keys::hash('mt_live_aaaaaaaaaaaaaaaaaaaa2'));
    }

    public function testThePrefixKeepsEnoughToTellTwoKeysApartAndNoMore(): void
    {
        $key = Keys::newKey();
        $prefix = Keys::prefixOf($key);
        $this->assertSame(Keys::PREFIX_SHOWN, strlen($prefix));
        $this->assertStringStartsWith('mt_live_', $prefix);
        // Not enough to be worth anything to somebody reading a log.
        $this->assertLessThan(strlen($key) / 2, strlen($prefix));
    }

    public function testSameHashComparesEqualHashesAndRejectsDifferentOnes(): void
    {
        $this->assertTrue(Keys::sameHash(str_repeat('a', 64), str_repeat('a', 64)));
        $this->assertFalse(Keys::sameHash(str_repeat('a', 64), str_repeat('b', 64)));
    }

    public function testSameHashDoesNotThrowOnDifferentLengths(): void
    {
        $this->assertFalse(Keys::sameHash('abc', str_repeat('a', 64)));
        $this->assertFalse(Keys::sameHash('', 'a'));
    }

    public function testBearerFromReadsAWellFormedHeader(): void
    {
        $key = Keys::newKey();
        $this->assertSame($key, Keys::bearerFrom("Bearer $key"));
        $this->assertSame($key, Keys::bearerFrom("bearer   $key  "));
    }

    public function testBearerFromRefusesAnythingThatIsNotOursBeforeItReachesTheDatabase(): void
    {
        $this->assertNull(Keys::bearerFrom(null));
        $this->assertNull(Keys::bearerFrom(''));
        $this->assertNull(Keys::bearerFrom('Basic abc'), 'another scheme');
        $this->assertNull(Keys::bearerFrom('Bearer eyJhbGciOiJIUzI1NiJ9.e30.x'), 'somebody else’s JWT');
        $this->assertNull(Keys::bearerFrom('Bearer mt_live_short'), 'too short to be one of ours');
    }

    public function testScopesKnowTheirOwnAndNothingElse(): void
    {
        $this->assertSame(['content:read'], Keys::cleanScopes(['content:read', 'everything:write', 'admin']));
        $this->assertSame([], Keys::cleanScopes(['nonsense']));
    }

    public function testScopesDropRubbishAndDuplicates(): void
    {
        $this->assertSame(['content:read', 'events:read'], Keys::cleanScopes(['events:read', 'content:read', 'events:read', 42, null, '  content:read  ']));
        $this->assertSame([], Keys::cleanScopes('content:read'), 'a string is not a list of scopes');
    }

    public function testNoScopeEverImpliesAnother(): void
    {
        $this->assertFalse(Keys::allows(['events:read'], 'events:registrations'), 'forty people are coming is not their phone numbers');
        $this->assertFalse(Keys::allows(['events:registrations'], 'events:read'));
        $this->assertFalse(Keys::allows(['content:read', 'analytics:read', 'groups:read', 'schedules:read'], 'events:read'));
        $this->assertTrue(Keys::allows(['events:read'], 'events:read'));
    }

    public function testEveryScopeIsDescribedAndThePersonalOnesAreMarked(): void
    {
        foreach (Keys::SCOPES as $scope => $about) {
            $this->assertMatchesRegularExpression('/^[a-z]+:[a-z]+$/', $scope);
            $this->assertNotSame('', $about['label'], $scope);
            $this->assertNotSame('', $about['hint'], $scope);
            $this->assertIsBool($about['personal']);
        }
        $personal = array_keys(array_filter(Keys::SCOPES, fn (array $s) => $s['personal']));
        $this->assertContains('events:registrations', $personal);
        $this->assertContains('schedules:read', $personal);
    }

    public function testAKeyGrantsNothingAtAllByDefault(): void
    {
        foreach (array_keys(Keys::SCOPES) as $scope) {
            $this->assertFalse(Keys::allows([], $scope));
        }
    }

    public function testKeyStateIsOkForALiveKey(): void
    {
        $this->assertSame(Keys::OK, Keys::state(['revoked_at' => null, 'expires_at' => null]));
        $this->assertSame(Keys::OK, Keys::state(['revoked_at' => null, 'expires_at' => '2030-01-01 00:00:00'], '2026-06-10 12:00:00'));
    }

    public function testAKeyIsExpiredTheMomentItExpiresNotAfter(): void
    {
        $at = '2026-06-10 12:00:00';
        $this->assertSame(Keys::EXPIRED, Keys::state(['revoked_at' => null, 'expires_at' => $at], $at));
        $this->assertSame(Keys::OK, Keys::state(['revoked_at' => null, 'expires_at' => '2026-06-10 12:00:01'], $at));
    }

    public function testRevokedIsSaidAheadOfExpired(): void
    {
        $this->assertSame(
            Keys::REVOKED,
            Keys::state(['revoked_at' => '2026-01-01 00:00:00', 'expires_at' => '2020-01-01 00:00:00'], '2026-06-10 12:00:00'),
        );
    }

    public function testPageSizeClampsToSomethingADatabaseCanServe(): void
    {
        $this->assertSame(Keys::MAX_PAGE, Keys::pageSize(100000));
        $this->assertSame(1, Keys::pageSize(1));
        $this->assertSame(25, Keys::pageSize('25'));
    }

    public function testPageSizeAnswersAGuessWithTheDefaultRatherThanAnError(): void
    {
        $this->assertSame(Keys::DEFAULT_PAGE, Keys::pageSize('lots'));
        $this->assertSame(Keys::DEFAULT_PAGE, Keys::pageSize(null));
        $this->assertSame(Keys::DEFAULT_PAGE, Keys::pageSize(0));
        $this->assertSame(Keys::DEFAULT_PAGE, Keys::pageSize(-5));
    }
}

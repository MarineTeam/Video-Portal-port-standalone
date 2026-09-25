<?php

declare(strict_types=1);

namespace Tests\Unit\Access;

use App\Modules\Access\AllowlistStore;
use App\Modules\Access\Authorization;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

final class FakeAllowlist implements AllowlistStore
{
    /** @var array<string, array{status: string, organizationExempt: bool}> */
    public array $rows = [];
    /** @var list<string> */
    public array $lookups = [];
    /** @var list<string> */
    public array $adopted = [];

    public function find(string $normalizedEmail): ?array
    {
        $this->lookups[] = $normalizedEmail;
        return $this->rows[$normalizedEmail] ?? null;
    }

    public function adopt(string $normalizedEmail): array
    {
        $this->adopted[] = $normalizedEmail;
        return $this->rows[$normalizedEmail] = ['status' => 'ACTIVE', 'organizationExempt' => false];
    }
}

/** lib/authorization.test.ts */
final class AuthorizationTest extends TestCase
{
    private const ORG = 'org_marine';

    private function auth(string $mode = 'BOTH', array $rows = [], array $admins = [], ?FakeAllowlist $store = null): Authorization
    {
        $store ??= new FakeAllowlist();
        foreach ($rows as $email => $row) {
            $store->rows[$email] = $row + ['status' => 'ACTIVE', 'organizationExempt' => false];
        }
        return new Authorization($store, $mode, [self::ORG], $admins);
    }

    // normalizeEmail
    #[TestDox('lowercases and trims, so casing and stray spaces can\'t make a second identity')]
    public function testNormalize(): void
    {
        self::assertSame('alice@example.com', Authorization::normalizeEmail('  Alice@Example.COM '));
    }

    // isValidEmail
    #[TestDox('accepts ordinary addresses')]
    public function testValidOrdinary(): void
    {
        foreach (['a@b.co', 'first.last+tag@church.example.org'] as $email) {
            self::assertTrue(Authorization::isValidEmail($email), $email);
        }
    }

    #[TestDox('rejects malformed input')]
    public function testValidMalformed(): void
    {
        foreach (['', 'plain', '@example.com', 'a@', 'a@b', 'a@@b.com'] as $email) {
            self::assertFalse(Authorization::isValidEmail($email), $email);
        }
    }

    #[TestDox('rejects anything that could be used for header, log, or SQL-ish injection')]
    public function testValidInjection(): void
    {
        foreach (["a@b.com\r\nBcc: x@y.com", "a@b.com\nx", "a'--@b.com", 'a@b.com;drop', '<a@b.com>', 'a b@c.com'] as $email) {
            self::assertFalse(Authorization::isValidEmail($email), $email);
        }
    }

    // isOrganizationMember
    #[TestDox('accepts only the configured organization')]
    public function testOrgOnly(): void
    {
        self::assertTrue(Authorization::isOrganizationMember(self::ORG, [self::ORG]));
        self::assertFalse(Authorization::isOrganizationMember('org_other', [self::ORG]));
    }

    #[TestDox('refuses a missing claim — a personal account carries no org_id')]
    public function testOrgMissing(): void
    {
        self::assertFalse(Authorization::isOrganizationMember(null, [self::ORG]));
        self::assertFalse(Authorization::isOrganizationMember('', [self::ORG]));
    }

    #[TestDox('fails closed when the organization isn\'t configured, rather than passing everyone')]
    public function testOrgUnconfigured(): void
    {
        self::assertFalse(Authorization::isOrganizationMember(self::ORG, []));
    }

    #[TestDox('accepts any organization in a comma-separated list, and rejects one that isn\'t listed')]
    public function testOrgList(): void
    {
        $allowed = Authorization::allowedOrganizationIds('org_a,org_b');
        self::assertTrue(Authorization::isOrganizationMember('org_a', $allowed));
        self::assertTrue(Authorization::isOrganizationMember('org_b', $allowed));
        self::assertFalse(Authorization::isOrganizationMember('org_c', $allowed));
    }

    #[TestDox('tolerates whitespace around each id in the list')]
    public function testOrgWhitespace(): void
    {
        self::assertTrue(Authorization::isOrganizationMember('org_b', Authorization::allowedOrganizationIds(' org_a , org_b ')));
    }

    #[TestDox('fails closed on a whitespace- or comma-only value, not just an unset one')]
    public function testOrgBlankList(): void
    {
        foreach (['  ', ',', ' , ,'] as $raw) {
            self::assertFalse(Authorization::isOrganizationMember('org_a', Authorization::allowedOrganizationIds($raw)));
        }
    }

    // allowedOrganizationIds
    #[TestDox('parses a single value the same as before — the existing single-org deployment shape')]
    public function testIdsSingle(): void
    {
        self::assertSame(['org_a'], Authorization::allowedOrganizationIds('org_a'));
    }

    #[TestDox('parses a comma-separated list, trimmed and with empties dropped')]
    public function testIdsList(): void
    {
        self::assertSame(['org_a', 'org_b'], Authorization::allowedOrganizationIds(' org_a,, org_b ,'));
    }

    #[TestDox('is empty when unset')]
    public function testIdsUnset(): void
    {
        self::assertSame([], Authorization::allowedOrganizationIds(null));
        self::assertSame([], Authorization::allowedOrganizationIds(''));
    }

    // isEmailAuthorized
    #[TestDox('passes an ACTIVE row')]
    public function testEmailActive(): void
    {
        self::assertTrue($this->auth(rows: ['a@b.com' => []])->isEmailAuthorized('a@b.com'));
    }

    #[TestDox('looks the address up normalized, whatever casing or spacing was given')]
    public function testEmailNormalizedLookup(): void
    {
        $store = new FakeAllowlist();
        $auth = $this->auth(rows: ['a@b.com' => []], store: $store);
        self::assertTrue($auth->isEmailAuthorized('  A@B.com '));
        self::assertSame(['a@b.com'], $store->lookups);
    }

    #[TestDox('refuses a SUSPENDED row')]
    public function testEmailSuspended(): void
    {
        self::assertFalse($this->auth(rows: ['a@b.com' => ['status' => 'SUSPENDED']])->isEmailAuthorized('a@b.com'));
    }

    #[TestDox('refuses an address with no row')]
    public function testEmailMissing(): void
    {
        self::assertFalse($this->auth()->isEmailAuthorized('a@b.com'));
    }

    #[TestDox('refuses empty or malformed input without querying at all')]
    public function testEmailMalformed(): void
    {
        $store = new FakeAllowlist();
        $auth = $this->auth(store: $store);
        self::assertFalse($auth->isEmailAuthorized(''));
        self::assertFalse($auth->isEmailAuthorized(null));
        self::assertFalse($auth->isEmailAuthorized('not an email'));
        self::assertSame([], $store->lookups);
    }

    #[TestDox('adopts an ADMIN_EMAILS address with no row, creating a visible entry for it')]
    public function testEmailAdopt(): void
    {
        $store = new FakeAllowlist();
        $auth = $this->auth(admins: ['Boss@Church.org'], store: $store);
        self::assertTrue($auth->isEmailAuthorized('boss@church.org'));
        self::assertSame(['boss@church.org'], $store->adopted);
    }

    #[TestDox('does not revive an ADMIN_EMAILS address an administrator suspended')]
    public function testEmailAdoptSuspended(): void
    {
        $store = new FakeAllowlist();
        $auth = $this->auth(rows: ['boss@church.org' => ['status' => 'SUSPENDED']], admins: ['boss@church.org'], store: $store);
        self::assertFalse($auth->isEmailAuthorized('boss@church.org'));
        self::assertSame([], $store->adopted);
    }

    // authorizationMode
    #[TestDox('defaults to requiring both checks')]
    public function testModeDefault(): void
    {
        self::assertSame('BOTH', Authorization::authorizationMode(null));
        self::assertSame('BOTH', Authorization::authorizationMode(''));
    }

    #[TestDox('accepts the four modes, case-insensitively and untrimmed')]
    public function testModeFour(): void
    {
        self::assertSame('ORGANIZATION', Authorization::authorizationMode(' organization '));
        self::assertSame('ALLOWLIST', Authorization::authorizationMode('Allowlist'));
        self::assertSame('EITHER', Authorization::authorizationMode('either'));
        self::assertSame('BOTH', Authorization::authorizationMode('BOTH'));
    }

    #[TestDox('falls back to BOTH for anything unrecognised, rather than to something permissive')]
    public function testModeFallback(): void
    {
        foreach (['NONE', 'OFF', 'ANY', 'either or', 'allow'] as $raw) {
            self::assertSame('BOTH', Authorization::authorizationMode($raw));
        }
    }

    // isAuthorized — every mode against every combination
    /** @return iterable<string, array{bool, bool, bool, bool, bool, bool}> */
    public static function truthTable(): iterable
    {
        // org, email => BOTH, ORGANIZATION, ALLOWLIST, EITHER
        yield 'neither' => [false, false, false, false, false, false];
        yield 'email only' => [false, true, false, false, true, true];
        yield 'org only' => [true, false, false, true, false, true];
        yield 'both' => [true, true, true, true, true, true];
    }

    #[DataProvider('truthTable')]
    #[TestDox('${label}: BOTH=${both} ORGANIZATION=${orgMode} ALLOWLIST=${allowlistMode} EITHER=${eitherMode}')]
    public function testTruthTable(bool $org, bool $email, bool $both, bool $orgMode, bool $allowlistMode, bool $eitherMode): void
    {
        self::assertSame($both, Authorization::isAuthorized('BOTH', $org, $email));
        self::assertSame($orgMode, Authorization::isAuthorized('ORGANIZATION', $org, $email));
        self::assertSame($allowlistMode, Authorization::isAuthorized('ALLOWLIST', $org, $email));
        self::assertSame($eitherMode, Authorization::isAuthorized('EITHER', $org, $email));
    }

    #[TestDox('never lets any mode admit someone who failed every check')]
    public function testNoModeAdmitsNobody(): void
    {
        foreach ([...Authorization::MODES, 'garbage', ''] as $mode) {
            self::assertFalse(Authorization::isAuthorized($mode, false, false), $mode);
        }
    }

    #[TestDox('EITHER is the one mode where org-only and email-only both admit — that\'s the whole point of it')]
    public function testEitherIsUnique(): void
    {
        foreach (Authorization::MODES as $mode) {
            $both = Authorization::isAuthorized($mode, true, false) && Authorization::isAuthorized($mode, false, true);
            self::assertSame($mode === 'EITHER', $both, $mode);
        }
    }

    // denialReasonFor
    #[TestDox('names whichever halves failed, under BOTH')]
    public function testReasonBoth(): void
    {
        self::assertSame(Authorization::NOT_ORG_MEMBER, Authorization::denialReasonFor('BOTH', false, true));
        self::assertSame(Authorization::EMAIL_NOT_AUTHORIZED, Authorization::denialReasonFor('BOTH', true, false));
        self::assertSame(Authorization::BOTH_FAILED, Authorization::denialReasonFor('BOTH', false, false));
    }

    #[TestDox('only blames checks the mode actually enforces')]
    public function testReasonEnforced(): void
    {
        self::assertSame(Authorization::NOT_ORG_MEMBER, Authorization::denialReasonFor('ORGANIZATION', false, false));
        self::assertSame(Authorization::EMAIL_NOT_AUTHORIZED, Authorization::denialReasonFor('ALLOWLIST', false, false));
    }

    #[TestDox('under EITHER, a denial always means both failed — isAuthorized only calls this once neither passed')]
    public function testReasonEither(): void
    {
        self::assertSame(Authorization::BOTH_FAILED, Authorization::denialReasonFor('EITHER', false, false));
    }

    // authorizeIdentity in a single-check mode
    #[TestDox('ORGANIZATION: an org member gets in with no allowlist row')]
    public function testOrgModeMember(): void
    {
        self::assertTrue($this->auth('ORGANIZATION')->authorizeIdentity('a@b.com', self::ORG)['allowed']);
    }

    #[TestDox('ORGANIZATION: a non-member is still refused even when allowlisted')]
    public function testOrgModeNonMember(): void
    {
        self::assertFalse($this->auth('ORGANIZATION', ['a@b.com' => []])->authorizeIdentity('a@b.com', null)['allowed']);
    }

    #[TestDox('ALLOWLIST: an allowlisted address gets in with no organization claim')]
    public function testAllowlistMode(): void
    {
        self::assertTrue($this->auth('ALLOWLIST', ['a@b.com' => []])->authorizeIdentity('a@b.com', null)['allowed']);
    }

    #[TestDox('ALLOWLIST: an org member with no allowlist row is still refused')]
    public function testAllowlistModeOrgOnly(): void
    {
        self::assertFalse($this->auth('ALLOWLIST')->authorizeIdentity('a@b.com', self::ORG)['allowed']);
    }

    #[TestDox('ALLOWLIST: suspension still revokes')]
    public function testAllowlistSuspended(): void
    {
        self::assertFalse($this->auth('ALLOWLIST', ['a@b.com' => ['status' => 'SUSPENDED']])->authorizeIdentity('a@b.com', null)['allowed']);
    }

    #[TestDox('an unrecognised mode is treated as BOTH, not as a bypass')]
    public function testUnknownMode(): void
    {
        $auth = $this->auth('WIDE_OPEN', ['a@b.com' => []]);
        self::assertFalse($auth->authorizeIdentity('a@b.com', null)['allowed']);
        self::assertTrue($auth->authorizeIdentity('a@b.com', self::ORG)['allowed']);
    }

    #[TestDox('EITHER: a personal account (no org_id) gets in on an allowlist entry alone')]
    public function testEitherPersonal(): void
    {
        self::assertTrue($this->auth('EITHER', ['a@b.com' => []])->authorizeIdentity('a@b.com', null)['allowed']);
    }

    #[TestDox('EITHER: an organization member gets in with no allowlist row')]
    public function testEitherOrg(): void
    {
        self::assertTrue($this->auth('EITHER')->authorizeIdentity('a@b.com', self::ORG)['allowed']);
    }

    #[TestDox('EITHER: someone with neither is still refused')]
    public function testEitherNeither(): void
    {
        self::assertFalse($this->auth('EITHER')->authorizeIdentity('a@b.com', null)['allowed']);
    }

    // authorizeIdentity — the whole truth table
    #[TestDox('DENY: not in the organization, not on the allowlist')]
    public function testDenyNeither(): void
    {
        $r = $this->auth()->authorizeIdentity('a@b.com', null);
        self::assertFalse($r['allowed']);
        self::assertSame(Authorization::BOTH_FAILED, $r['reason']);
    }

    #[TestDox('DENY: not in the organization, but on the allowlist')]
    public function testDenyNotOrg(): void
    {
        $r = $this->auth(rows: ['a@b.com' => []])->authorizeIdentity('a@b.com', null);
        self::assertFalse($r['allowed']);
        self::assertSame(Authorization::NOT_ORG_MEMBER, $r['reason']);
    }

    #[TestDox('DENY: in the organization, but not on the allowlist')]
    public function testDenyNotEmail(): void
    {
        $r = $this->auth()->authorizeIdentity('a@b.com', self::ORG);
        self::assertFalse($r['allowed']);
        self::assertSame(Authorization::EMAIL_NOT_AUTHORIZED, $r['reason']);
    }

    #[TestDox('ALLOW: in the organization and on the allowlist')]
    public function testAllow(): void
    {
        $r = $this->auth(rows: ['a@b.com' => []])->authorizeIdentity('a@b.com', self::ORG);
        self::assertTrue($r['allowed']);
        self::assertNull($r['reason']);
    }

    #[TestDox('DENY: a different organization\'s id can\'t stand in for ours')]
    public function testDenyOtherOrg(): void
    {
        self::assertFalse($this->auth(rows: ['a@b.com' => []])->authorizeIdentity('a@b.com', 'org_elsewhere')['allowed']);
    }

    #[TestDox('ALLOW is unaffected by casing or whitespace in the email')]
    public function testAllowCasing(): void
    {
        self::assertTrue($this->auth(rows: ['a@b.com' => []])->authorizeIdentity(' A@B.COM ', self::ORG)['allowed']);
    }

    #[TestDox('DENY: suspending the allowlist entry revokes an existing organization member')]
    public function testDenySuspended(): void
    {
        self::assertFalse($this->auth(rows: ['a@b.com' => ['status' => 'SUSPENDED']])->authorizeIdentity('a@b.com', self::ORG)['allowed']);
    }

    #[TestDox('evaluates both halves even when the first fails, so the record says which')]
    public function testBothEvaluated(): void
    {
        $r = $this->auth(rows: ['a@b.com' => []])->authorizeIdentity('a@b.com', null);
        self::assertFalse($r['organizationMember']);
        self::assertTrue($r['emailAuthorized']);
    }

    // authorizeIdentity — per-address organization exemption
    #[TestDox('an exempt, ACTIVE address gets in under BOTH mode with no organization at all')]
    public function testExempt(): void
    {
        self::assertTrue($this->auth(rows: ['g@b.com' => ['organizationExempt' => true]])->authorizeIdentity('g@b.com', null)['allowed']);
    }

    #[TestDox('a non-exempt address still needs both checks under BOTH mode')]
    public function testNotExempt(): void
    {
        self::assertFalse($this->auth(rows: ['a@b.com' => []])->authorizeIdentity('a@b.com', null)['allowed']);
    }

    #[TestDox('an exempt but SUSPENDED address is still refused — exemption isn\'t a bypass of status')]
    public function testExemptSuspended(): void
    {
        self::assertFalse($this->auth(rows: ['g@b.com' => ['organizationExempt' => true, 'status' => 'SUSPENDED']])->authorizeIdentity('g@b.com', null)['allowed']);
    }

    #[TestDox('exemption is a no-op for someone who\'s also an organization member — they\'d have gotten in anyway')]
    public function testExemptMember(): void
    {
        self::assertTrue($this->auth(rows: ['g@b.com' => ['organizationExempt' => true]])->authorizeIdentity('g@b.com', self::ORG)['allowed']);
    }

    #[TestDox('a bootstrap-adopted ADMIN_EMAILS address is never exempt')]
    public function testAdoptedNotExempt(): void
    {
        $auth = $this->auth(admins: ['boss@church.org']);
        self::assertFalse($auth->authorizeIdentity('boss@church.org', null)['allowed']);
        self::assertTrue($auth->authorizeIdentity('boss@church.org', self::ORG)['allowed']);
    }

    #[TestDox('a provider with no membership claim is judged on the allowlist under BOTH')]
    public function testNotApplicable(): void
    {
        $auth = $this->auth(rows: ['a@b.com' => []]);
        self::assertTrue($auth->authorizeIdentity('a@b.com', null, membershipApplicable: false)['allowed']);
        self::assertFalse($auth->authorizeIdentity('x@b.com', null, membershipApplicable: false)['allowed']);
        self::assertFalse($this->auth('ORGANIZATION', ['a@b.com' => []])->authorizeIdentity('a@b.com', null, membershipApplicable: false)['allowed']);
    }
}

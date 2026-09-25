<?php

declare(strict_types=1);

namespace App\Modules\Access;

use App\Core\Validator;

/**
 * Who gets in: two independent checks — membership (an organisation, a
 * Workspace domain, an Entra tenant: whatever the sign-in provider offers as
 * its membership claim) and an ACTIVE row in the allowlist — combined by the
 * authorization mode.
 *
 *   BOTH          (default) both must pass
 *   ORGANIZATION  membership only
 *   ALLOWLIST     the list only
 *   EITHER        either alone
 *
 * Anything unrecognised is BOTH: a typo must never be what opens a door, and
 * no value switches both checks off. A provider with no membership claim at
 * all reports "not applicable", which under BOTH means the allowlist decides.
 *
 * An ACTIVE row flagged organization_exempt ("Guest") admits that one
 * address on the allowlist alone, whatever the mode.
 */
final class Authorization
{
    public const MODES = ['BOTH', 'ORGANIZATION', 'ALLOWLIST', 'EITHER'];

    public const NOT_ORG_MEMBER = 'NOT_ORG_MEMBER';
    public const EMAIL_NOT_AUTHORIZED = 'EMAIL_NOT_AUTHORIZED';
    public const BOTH_FAILED = 'NOT_ORG_MEMBER_AND_EMAIL_NOT_AUTHORIZED';
    public const CALLBACK_ERROR = 'AUTH0_CALLBACK_ERROR';

    public function __construct(
        private readonly AllowlistStore $allowlist,
        private readonly string $mode = 'BOTH',
        /** @var list<string> */
        private readonly array $organizationIds = [],
        /** @var list<string> */
        private readonly array $bootstrapAdmins = [],
    ) {
    }

    public static function normalizeEmail(string $email): string
    {
        return Validator::normalizeEmail($email);
    }

    public static function isValidEmail(string $email): bool
    {
        return Validator::isEmail($email);
    }

    /** @return list<string> */
    public static function allowedOrganizationIds(?string $raw): array
    {
        if ($raw === null) {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode(',', $raw)), fn ($id) => $id !== ''));
    }

    /**
     * The claim is the proof: only an id from a verified token counts, and an
     * unset configuration admits nobody rather than everybody.
     *
     * @param list<string> $allowed
     */
    public static function isOrganizationMember(?string $claim, array $allowed): bool
    {
        if ($claim === null || $claim === '' || $allowed === []) {
            return false;
        }
        return in_array($claim, $allowed, true);
    }

    public static function authorizationMode(?string $raw): string
    {
        $mode = strtoupper(trim((string) $raw));
        return in_array($mode, self::MODES, true) ? $mode : 'BOTH';
    }

    public static function isAuthorized(string $mode, bool $orgMember, bool $emailAuthorized): bool
    {
        return match (self::authorizationMode($mode)) {
            'ORGANIZATION' => $orgMember,
            'ALLOWLIST' => $emailAuthorized,
            'EITHER' => $orgMember || $emailAuthorized,
            default => $orgMember && $emailAuthorized,
        };
    }

    /** Names only the checks the mode actually enforces. */
    public static function denialReasonFor(string $mode, bool $orgMember, bool $emailAuthorized): string
    {
        $mode = self::authorizationMode($mode);
        if ($mode === 'EITHER') {
            return self::BOTH_FAILED;
        }
        $orgFailed = $mode !== 'ALLOWLIST' && !$orgMember;
        $emailFailed = $mode !== 'ORGANIZATION' && !$emailAuthorized;
        if ($orgFailed && $emailFailed) {
            return self::BOTH_FAILED;
        }
        return $orgFailed ? self::NOT_ORG_MEMBER : self::EMAIL_NOT_AUTHORIZED;
    }

    public function mode(): string
    {
        return self::authorizationMode($this->mode);
    }

    /**
     * Whether an address has an ACTIVE allowlist row. A bootstrap
     * administrator's address with no row is adopted as a visible row — a
     * suspended one is never revived.
     *
     * @return array{authorized: bool, exempt: bool}
     */
    public function emailStatus(?string $email): array
    {
        if ($email === null || !self::isValidEmail($email)) {
            return ['authorized' => false, 'exempt' => false];
        }
        $email = self::normalizeEmail($email);
        $row = $this->allowlist->find($email);
        if ($row === null && in_array($email, array_map([self::class, 'normalizeEmail'], $this->bootstrapAdmins), true)) {
            $row = $this->allowlist->adopt($email);
        }
        if ($row === null || $row['status'] !== 'ACTIVE') {
            return ['authorized' => false, 'exempt' => false];
        }
        return ['authorized' => true, 'exempt' => (bool) $row['organizationExempt']];
    }

    public function isEmailAuthorized(?string $email): bool
    {
        return $this->emailStatus($email)['authorized'];
    }

    /**
     * The whole decision for one identity. Both halves are always evaluated,
     * so a refusal records which failed whatever the mode.
     *
     * @param ?string $membershipClaim the verified claim; null when the provider has none to give
     * @param bool $membershipApplicable false when the provider has no membership concept at all
     * @return array{allowed: bool, organizationMember: bool, emailAuthorized: bool, reason: ?string}
     */
    public function authorizeIdentity(?string $email, ?string $membershipClaim, bool $membershipApplicable = true): array
    {
        $status = $this->emailStatus($email);
        $orgMember = self::isOrganizationMember($membershipClaim, $this->organizationIds);
        $mode = $this->mode();

        if ($status['authorized'] && $status['exempt']) {
            return ['allowed' => true, 'organizationMember' => $orgMember, 'emailAuthorized' => true, 'reason' => null];
        }
        // A provider with nothing that could stand for membership: under BOTH
        // the allowlist decides; under ORGANIZATION nobody passes (fail closed).
        $effectiveMode = !$membershipApplicable && $mode === 'BOTH' ? 'ALLOWLIST' : $mode;
        $allowed = self::isAuthorized($effectiveMode, $orgMember, $status['authorized']);
        return [
            'allowed' => $allowed,
            'organizationMember' => $orgMember,
            'emailAuthorized' => $status['authorized'],
            'reason' => $allowed ? null : self::denialReasonFor($effectiveMode, $orgMember, $status['authorized']),
        ];
    }
}

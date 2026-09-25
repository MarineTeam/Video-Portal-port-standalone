<?php

declare(strict_types=1);

namespace App\Modules\Library;

use App\Core\Validator;

/**
 * Share links' rules, pure (lib/share-links.ts): who may make one and what it
 * grants, whether a link opens for this reader, and the small parsers the
 * form uses. Redemption and the cookie live in ShareAccess.
 */
final class ShareLinks
{
    public const COOKIE = 'share_access';
    public const MAX_EXPIRY_DAYS = 365;
    public const MIN_PASSWORD = 6;
    public const PER_HOUR = 20;

    /**
     * What a share would be (shareLinkPolicy): content anyone can already see
     * is shared as a plain link and grants nothing (an override asked for
     * there is ignored); restricted content is a plain link unless the sharer
     * asks for the override, which only a permitted sharer may.
     *
     * @return array{allowed: bool, grantsAccess: bool, reason: ?string}
     */
    public static function policy(bool $contentIsRestricted, bool $sharerMayOverride, bool $overrideRequested): array
    {
        if (!$contentIsRestricted) {
            return ['allowed' => true, 'grantsAccess' => false, 'reason' => null];
        }
        if (!$overrideRequested) {
            return ['allowed' => true, 'grantsAccess' => false, 'reason' => null];
        }
        if (!$sharerMayOverride) {
            return ['allowed' => false, 'grantsAccess' => false, 'reason' => 'Only an administrator, or someone allowed to share restricted content here, can make a link that lets people in.'];
        }
        return ['allowed' => true, 'grantsAccess' => true, 'reason' => null];
    }

    /**
     * Whether a link opens for this reader (shareLinkStatus). Revoked is
     * checked first, so a revoked link says nothing about who it was for.
     *
     * @param array<string, mixed>|null $link a share_links row
     * @param list<string> $recipients the private link's addresses
     * @return 'invalid'|'revoked'|'expired'|'login'|'wrong_recipient'|'ok'
     */
    public static function status(?array $link, array $recipients, ?string $viewerEmail, \DateTimeImmutable $now): string
    {
        if ($link === null) {
            return 'invalid';
        }
        if (($link['revoked_at'] ?? null) !== null) {
            return 'revoked';
        }
        $expires = $link['expires_at'] ?? null;
        if ($expires !== null && new \DateTimeImmutable((string) $expires, new \DateTimeZone('UTC')) <= $now) {
            return 'expired';
        }
        if (($link['visibility'] ?? 'PUBLIC') === 'PRIVATE') {
            if ($viewerEmail === null) {
                return 'login';
            }
            $normalized = array_map([Validator::class, 'normalizeEmail'], $recipients);
            if (!in_array(Validator::normalizeEmail($viewerEmail), $normalized, true)) {
                return 'wrong_recipient';
            }
        }
        return 'ok';
    }

    /**
     * Addresses from what somebody typed: commas, semicolons and whitespace
     * all separate; lower-cased and de-duplicated; anything that isn't an
     * address dropped.
     *
     * @return list<string>
     */
    public static function parseRecipientEmails(?string $input): array
    {
        $out = [];
        foreach (preg_split('/[\s,;]+/', (string) $input) ?: [] as $part) {
            $email = Validator::normalizeEmail($part);
            if ($email !== '' && Validator::isEmail($email) && !in_array($email, $out, true)) {
                $out[] = $email;
            }
        }
        return $out;
    }

    /** Never (null) for null or 0 days; otherwise that many days from $now. */
    public static function expiryFromDays(?int $days, \DateTimeImmutable $now): ?\DateTimeImmutable
    {
        if ($days === null || $days <= 0) {
            return null;
        }
        return $now->modify('+' . min($days, self::MAX_EXPIRY_DAYS) . ' days');
    }

    /** A new token: 32 characters of base64url, 192 bits. */
    public static function newToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
    }

    public static function isTokenShaped(string $token): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9_-]{16,128}$/', $token);
    }

    /**
     * The tokens a share_access cookie holds (never grants: every one is
     * re-checked against the database on every request).
     *
     * @return list<string>
     */
    public static function cookieTokens(?string $cookie): array
    {
        $out = [];
        foreach (explode(',', (string) $cookie) as $token) {
            $token = trim($token);
            if (self::isTokenShaped($token) && !in_array($token, $out, true)) {
                $out[] = $token;
            }
        }
        return array_slice($out, -20);
    }
}

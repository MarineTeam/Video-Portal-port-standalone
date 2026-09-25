<?php

declare(strict_types=1);

namespace App\Modules\Access;

/**
 * decideLinking: the only way an incoming identity is attached to a member.
 *
 * sub first — the provider's stable id, which survives an email change at the
 * provider. A sub never seen before may attach to an existing member by email
 * only when the provider verified that email; an unverified match is refused
 * (indistinguishably from any other denial, by the caller), because linking
 * on an unverified address is the account-takeover path.
 */
final class IdentityLinking
{
    /**
     * @param ?string $userBySub the member already holding this sub
     * @param ?string $userByEmail the member whose address matches
     * @return array{action: 'use'|'link'|'create'|'refuse', userId: ?string}
     */
    public static function decideLinking(?string $userBySub, ?string $userByEmail, bool $emailVerified): array
    {
        if ($userBySub !== null) {
            return ['action' => 'use', 'userId' => $userBySub];
        }
        if ($userByEmail !== null) {
            return $emailVerified
                ? ['action' => 'link', 'userId' => $userByEmail]
                : ['action' => 'refuse', 'userId' => null];
        }
        return ['action' => 'create', 'userId' => null];
    }

    /**
     * How a provider's own subject is stored: namespaced by provider, except
     * Auth0's, which are already globally unique ("google-oauth2|…") and are
     * kept verbatim so imported identities still match.
     */
    public static function storedSub(string $providerId, string $sub): string
    {
        return $providerId === 'auth0' ? $sub : $providerId . '|' . $sub;
    }

    /** The connection named in a sub, for display: "google-oauth2|123" → "google-oauth2". */
    public static function providerOf(string $sub): string
    {
        $pos = strpos($sub, '|');
        return $pos === false ? 'unknown' : substr($sub, 0, $pos);
    }
}

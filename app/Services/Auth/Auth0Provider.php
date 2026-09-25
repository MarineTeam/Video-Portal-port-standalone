<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Core\Url;
use App\Modules\Access\Identity;

/**
 * Auth0: the same redirect flow against the tenant's OIDC endpoints, with
 * the organization rules src/lib/auth0.ts documents. The org_id claim of
 * the verified ID token is the only proof of membership; the organization
 * parameter sent is a request. Subs are kept verbatim (google-oauth2|…),
 * so imported identities still match.
 */
final class Auth0Provider extends RedirectProvider
{
    public static function id(): string
    {
        return 'auth0';
    }

    public static function label(): string
    {
        return 'Auth0';
    }

    public static function limits(): string
    {
        return 'Needs HTTPS on this site and outbound HTTPS. Organizations, the guest link and the Pre-User-Registration Action work as before.';
    }

    public static function configSchema(): array
    {
        return [
            ['key' => 'domain', 'label' => 'Tenant domain', 'type' => 'text', 'required' => true, 'help' => 'e.g. gracechurch.eu.auth0.com, or your custom domain.'],
            ...self::clientFields(),
        ];
    }

    public function subPrefix(): string
    {
        return 'auth0';
    }

    private function domain(): string
    {
        return (string) preg_replace('#^https?://|/+$#', '', $this->str('domain'));
    }

    public function discoveryUrl(): string
    {
        return 'https://' . $this->domain() . '/.well-known/openid-configuration';
    }

    public function membershipClaim(): string
    {
        return 'org_id';
    }

    /**
     * organization is sent only when exactly one is configured and the mode
     * requires membership — never for the guest link, and never under
     * ALLOWLIST or EITHER, where Auth0 would turn people away before the
     * allowlist had a say.
     */
    /** @param list<string> $organizationIds */
    public static function organizationParam(string $mode, array $organizationIds, bool $guest): ?string
    {
        if ($guest || count($organizationIds) !== 1 || !in_array($mode, ['BOTH', 'ORGANIZATION'], true)) {
            return null;
        }
        return $organizationIds[0];
    }

    public function authorizeParams(array $context): array
    {
        $org = self::organizationParam($context['mode'], $context['organizationIds'], $context['guest']);
        return $org !== null ? ['organization' => $org] : [];
    }

    public function guestLoginUrl(): string
    {
        return Url::to('/auth/start', ['guest' => '1']);
    }

    public function identityFrom(array $claims, array $callback, array $organizationIds): Identity
    {
        return new Identity(
            (string) ($claims['sub'] ?? ''),
            'auth0',
            (string) ($claims['email'] ?? ''),
            self::truthy($claims['email_verified'] ?? false),
            isset($claims['name']) ? (string) $claims['name'] : null,
            isset($claims['picture']) ? (string) $claims['picture'] : null,
            isset($claims['org_id']) ? (string) $claims['org_id'] : null,
            true,
        );
    }

    public function logoutUrl(?string $returnTo): string
    {
        return 'https://' . $this->domain() . '/v2/logout?' . http_build_query(['client_id' => $this->clientId(), 'returnTo' => Url::absolute($returnTo ?? '/')]);
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Modules\Access\Identity;
use App\Support\Jwt;

/**
 * OpenID Connect, generic, with presets that fill in the discovery URL,
 * scopes, membership claim and notes for a named provider. A preset is
 * otherwise this same provider. Subs are stored as "<preset>|<sub>" (or
 * "oidc|<sub>" with no preset), so two issuers' subs never collide.
 */
final class OidcProvider extends RedirectProvider
{
    /** @var array<string, array{label: string, issuer: ?string, membership: ?string, scopes: string, notes: string}> */
    public const PRESETS = [
        'custom' => ['label' => 'Any OpenID Connect provider', 'issuer' => null, 'membership' => null, 'scopes' => 'openid email profile', 'notes' => 'Enter the issuer URL; its /.well-known/openid-configuration is read from there.'],
        'google' => ['label' => 'Google', 'issuer' => 'https://accounts.google.com', 'membership' => 'hd', 'scopes' => 'openid email profile', 'notes' => 'Membership is the Workspace domain (hd): put your domain in the organization list to admit only it. Personal accounts carry no hd.'],
        'entra' => ['label' => 'Microsoft Entra ID', 'issuer' => null, 'membership' => 'tid', 'scopes' => 'openid email profile', 'notes' => 'Issuer: https://login.microsoftonline.com/<tenant id>/v2.0. Enable the optional “email” claim on the app. Membership is the tenant (tid), or set the claim to groups.'],
        'apple' => ['label' => 'Sign in with Apple', 'issuer' => 'https://appleid.apple.com', 'membership' => null, 'scopes' => 'openid email name', 'notes' => 'Client ID is the Services ID. The client secret is made here from the team ID, key ID and the .p8 key, and renewed before it expires. The name arrives on the first sign-in only; addresses may be private relays.'],
        'okta' => ['label' => 'Okta', 'issuer' => null, 'membership' => 'groups', 'scopes' => 'openid email profile groups', 'notes' => 'Issuer: https://<your domain>.okta.com (or its authorization server).'],
        'keycloak' => ['label' => 'Keycloak', 'issuer' => null, 'membership' => 'groups', 'scopes' => 'openid email profile', 'notes' => 'Issuer: https://<host>/realms/<realm>.'],
        'authentik' => ['label' => 'Authentik', 'issuer' => null, 'membership' => 'groups', 'scopes' => 'openid email profile', 'notes' => 'Issuer: https://<host>/application/o/<application slug>/.'],
        'zitadel' => ['label' => 'Zitadel', 'issuer' => null, 'membership' => null, 'scopes' => 'openid email profile', 'notes' => 'Issuer: your instance’s domain.'],
        'logto' => ['label' => 'Logto', 'issuer' => null, 'membership' => null, 'scopes' => 'openid email profile', 'notes' => 'Issuer: https://<tenant>.logto.app/oidc.'],
        'kinde' => ['label' => 'Kinde', 'issuer' => null, 'membership' => 'org_code', 'scopes' => 'openid email profile', 'notes' => 'Issuer: https://<business>.kinde.com.'],
        'clerk' => ['label' => 'Clerk (as an OpenID provider)', 'issuer' => null, 'membership' => 'org_id', 'scopes' => 'openid email profile', 'notes' => 'Issuer: your instance’s Frontend API domain, e.g. https://clerk.example.org.'],
    ];

    public static function id(): string
    {
        return 'oidc';
    }

    public static function label(): string
    {
        return 'OpenID Connect (Google, Microsoft, Apple, Okta, Keycloak…)';
    }

    public static function limits(): string
    {
        return 'Needs HTTPS on this site and outbound HTTPS. Every preset is this one provider with its details filled in.';
    }

    public static function configSchema(): array
    {
        $presets = [];
        foreach (self::PRESETS as $key => $p) {
            $presets[$key] = $p['label'];
        }
        return [
            ['key' => 'preset', 'label' => 'Provider', 'type' => 'select', 'options' => $presets, 'default' => 'custom'],
            ['key' => 'issuer', 'label' => 'Issuer URL', 'type' => 'text', 'help' => 'Filled in for Google and Apple; see the notes for the others.'],
            ...self::clientFields(false),
            ['key' => 'scopes', 'label' => 'Scopes (optional)', 'type' => 'text', 'help' => 'Leave blank for the preset’s.'],
            ['key' => 'membership_claim', 'label' => 'Membership claim (optional)', 'type' => 'text', 'help' => 'The claim compared with the accepted organizations (hd, tid, groups, org_id…). Blank: the preset’s, or none — and then the allowlist decides.'],
            ['key' => 'apple_team_id', 'label' => 'Apple team ID', 'type' => 'text'],
            ['key' => 'apple_key_id', 'label' => 'Apple key ID', 'type' => 'text'],
            ['key' => 'apple_private_key', 'label' => 'Apple private key (.p8 contents)', 'type' => 'textarea', 'secret' => true],
        ];
    }

    public function displayName(): string
    {
        return $this->preset() === 'custom' ? 'single sign-on' : self::PRESETS[$this->preset()]['label'];
    }

    public function preset(): string
    {
        $p = $this->str('preset', 'custom');
        return isset(self::PRESETS[$p]) ? $p : 'custom';
    }

    public function subPrefix(): string
    {
        return $this->preset() === 'custom' ? 'oidc' : $this->preset();
    }

    public function issuer(): string
    {
        $given = rtrim($this->str('issuer'), '/');
        return $given !== '' ? $given : (string) (self::PRESETS[$this->preset()]['issuer'] ?? '');
    }

    public function discoveryUrl(): string
    {
        $issuer = $this->issuer();
        return str_contains($issuer, '/.well-known/') ? $issuer : $issuer . '/.well-known/openid-configuration';
    }

    public function scopes(): string
    {
        return $this->str('scopes') !== '' ? $this->str('scopes') : self::PRESETS[$this->preset()]['scopes'];
    }

    public function membershipClaim(): ?string
    {
        $claim = $this->str('membership_claim');
        return $claim !== '' ? $claim : self::PRESETS[$this->preset()]['membership'];
    }

    public function responseMode(): ?string
    {
        // Apple posts the result (and, the first time, the name) to the callback.
        return $this->preset() === 'apple' ? 'form_post' : null;
    }

    protected function prefersPostAuth(): bool
    {
        return $this->preset() === 'apple';
    }

    /**
     * Apple's client secret is an ES256 JWT signed with the team's key,
     * valid for at most six months; one is made per exchange.
     */
    public function clientSecret(): string
    {
        if ($this->preset() !== 'apple') {
            return parent::clientSecret();
        }
        $now = time();
        return Jwt::signEs256(['kid' => $this->str('apple_key_id')], [
            'iss' => $this->str('apple_team_id'),
            'iat' => $now,
            'exp' => $now + 3600,
            'aud' => 'https://appleid.apple.com',
            'sub' => $this->clientId(),
        ], $this->str('apple_private_key'));
    }

    public function authorizeParams(array $context): array
    {
        return [];
    }

    public function identityFrom(array $claims, array $callback, array $organizationIds): Identity
    {
        $sub = (string) ($claims['sub'] ?? '');
        $email = (string) ($claims['email'] ?? ($claims['preferred_username'] ?? ''));
        $name = isset($claims['name']) ? (string) $claims['name'] : null;
        if ($name === null && is_string($callback['user'] ?? null)) {
            // Apple: {"name":{"firstName":…,"lastName":…}} on the first sign-in only.
            $user = json_decode($callback['user'], true);
            $parts = is_array($user['name'] ?? null) ? array_filter([$user['name']['firstName'] ?? null, $user['name']['lastName'] ?? null]) : [];
            $name = $parts !== [] ? implode(' ', array_map('strval', $parts)) : null;
        }
        // Google omits email_verified for some account types; without it, it isn't verified.
        $verified = self::truthy($claims['email_verified'] ?? false);
        $claim = $this->membershipClaim();
        return new Identity(
            $this->subPrefix() . '|' . $sub,
            $this->subPrefix(),
            $email,
            $verified,
            $name,
            isset($claims['picture']) ? (string) $claims['picture'] : null,
            $claim !== null ? self::membershipValue($claims[$claim] ?? null, $organizationIds) : null,
            $claim !== null,
        );
    }
}

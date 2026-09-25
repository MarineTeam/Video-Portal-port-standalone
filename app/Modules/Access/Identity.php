<?php

declare(strict_types=1);

namespace App\Modules\Access;

/**
 * A verified assertion of who somebody is, from any sign-in provider. Every
 * provider ends in one of these; authorizeIdentity and decideLinking run on
 * it unchanged.
 */
final class Identity
{
    public function __construct(
        /** Stored form: "<provider>|<sub>", or Auth0's own sub verbatim. */
        public readonly string $sub,
        public readonly string $provider,
        public readonly string $email,
        public readonly bool $emailVerified,
        public readonly ?string $name = null,
        public readonly ?string $picture = null,
        /** The verified membership claim (org_id, hd, tid…), when the provider gave one. */
        public readonly ?string $membership = null,
        /** False when this provider has no notion of membership at all. */
        public readonly bool $membershipApplicable = true,
    ) {
    }

    /** @return array<string, mixed> what the session keeps, for the per-request re-check */
    public function toSession(): array
    {
        return [
            'sub' => $this->sub,
            'provider' => $this->provider,
            'email' => $this->email,
            'membership' => $this->membership,
            'membershipApplicable' => $this->membershipApplicable,
        ];
    }
}

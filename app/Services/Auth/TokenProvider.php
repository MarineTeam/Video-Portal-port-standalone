<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Modules\Access\Identity;

/**
 * The token flow: the provider's own script signs the person in on
 * /auth/login, and the browser posts the resulting JWT to /auth/token.
 */
interface TokenProvider extends AuthProvider
{
    /**
     * Verifies the token and derives the identity, or throws.
     *
     * @param list<string> $organizationIds the accepted membership values
     */
    public function identityFromToken(string $jwt, array $organizationIds): Identity;

    /**
     * What /auth/login needs to mount the provider's sign-in: its scripts
     * (loaded from the provider's own CDN, in order), the settings the page
     * script reads (nothing secret, and "kind" says which provider), and the
     * hosts the page's CSP must allow for them.
     *
     * @return array{scripts: list<string>, config: array<string, mixed>, csp: array<string, list<string>>}
     */
    public function widget(): array;
}

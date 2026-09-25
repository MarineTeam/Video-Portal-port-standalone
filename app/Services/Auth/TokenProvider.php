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
     * What /auth/login needs to mount the provider's sign-in: its script and
     * the settings the page script reads (nothing secret).
     *
     * @return array{script: string, config: array<string, mixed>}
     */
    public function widget(): array;
}

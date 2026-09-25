<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Core\App;
use App\Core\Router;
use App\Services\ServiceProvider;

/**
 * A sign-in provider. Three flow kinds are all the interface knows about:
 *   form      renders and handles its own forms (local accounts)
 *   redirect  start → provider → /auth/callback with state, nonce and PKCE
 *   token     the provider's script signs in; the browser posts a JWT to /auth/token
 * Whatever the flow, the result is an Identity handed to SignIn::complete().
 */
interface AuthProvider extends ServiceProvider
{
    public function flow(): string;

    public function routes(Router $router, App $app): void;

    public function logoutUrl(?string $returnTo): ?string;

    /** What stands in for Auth0's org_id with this provider, or null when nothing can. */
    public function membershipClaim(): ?string;
}

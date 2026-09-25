<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Core\App;
use App\Core\Router;
use App\Services\BaseProvider;
use App\Services\TestContext;
use App\Services\TestResult;

/**
 * Local accounts: a password (Argon2id where the host has it, bcrypt
 * otherwise), and optionally a magic link by email. Always available — and
 * always kept for ADMIN accounts, whatever the primary provider, because it
 * is the recovery path. Its routes live in the Access module.
 */
final class LocalProvider extends BaseProvider implements AuthProvider
{
    public static function slot(): string
    {
        return 'auth';
    }

    public static function id(): string
    {
        return 'local';
    }

    public static function label(): string
    {
        return 'Local accounts (password, magic link)';
    }

    public static function limits(): string
    {
        return 'Members manage one more password. Magic links and password resets need email to be set up.';
    }

    public static function configSchema(): array
    {
        return [
            ['key' => 'self_registration', 'label' => 'Let people create their own account', 'type' => 'toggle', 'default' => false, 'help' => 'Only addresses already on “Who can sign in” can register, whatever this says.'],
            ['key' => 'magic_link', 'label' => 'Offer “email me a sign-in link”', 'type' => 'toggle', 'default' => false, 'help' => 'For members who will never keep a password. Needs email.'],
            ['key' => 'members_may_use', 'label' => 'Members may sign in with a password (not only administrators)', 'type' => 'toggle', 'default' => true, 'help' => 'Only relevant when another sign-in provider is the primary one.'],
        ];
    }

    public function flow(): string
    {
        return 'form';
    }

    public function routes(Router $router, App $app): void
    {
        // Registered by App\Modules\Access\Routes so they exist whatever is primary.
    }

    public function logoutUrl(?string $returnTo): ?string
    {
        return null;
    }

    public function membershipClaim(): ?string
    {
        return null;
    }

    public function selfRegistration(): bool
    {
        return (bool) $this->cfg('self_registration', false);
    }

    public function magicLink(): bool
    {
        return (bool) $this->cfg('magic_link', false);
    }

    public function membersMayUse(): bool
    {
        return (bool) $this->cfg('members_may_use', true);
    }

    public function test(TestContext $context): TestResult
    {
        return TestResult::ok('Always available: nothing external to check.');
    }
}

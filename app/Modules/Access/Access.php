<?php

declare(strict_types=1);

namespace App\Modules\Access;

use App\Core\App;
use App\Core\Cache;
use App\Services\Email\Mailer;

/** Access settings, read from the settings table (the port of AUTHORIZATION_MODE and friends). */
final class Access
{
    public static function authorization(App $app): Authorization
    {
        return Cache::memo('authorization', fn () => new Authorization(
            new DbAllowlistStore($app->db()),
            $app->settings()->string('auth.mode', 'BOTH'),
            Authorization::allowedOrganizationIds($app->settings()->string('auth.organization_ids')),
            self::bootstrapAdmins($app),
        ));
    }

    /** @return list<string> "bootstrap administrators" (the port of ADMIN_EMAILS), normalised */
    public static function bootstrapAdmins(App $app): array
    {
        $raw = $app->settings()->get('auth.bootstrap_admins', []);
        $list = is_array($raw) ? $raw : explode(',', (string) $raw);
        return array_values(array_filter(array_map(fn ($e) => Authorization::normalizeEmail((string) $e), $list), [Authorization::class, 'isValidEmail']));
    }

    public static function mailer(App $app): ?Mailer
    {
        try {
            return $app->mailer();
        } catch (\Throwable) {
            return null;
        }
    }

    /** The break-glass file: local sign-in for ADMIN accounts, whatever else is configured. */
    /**
     * Whether this person may sign in with a local password: administrators
     * always (unless turned off, and then still with the break-glass file);
     * members when local accounts are the sign-in service or allowed beside it.
     *
     * @param array<string, mixed> $user
     */
    public static function mayUseLocal(App $app, array $user): bool
    {
        if (($user['role'] ?? '') === 'ADMIN') {
            return $app->settings()->bool('auth.local_for_admins', true) || self::breakGlass($app);
        }
        $local = $app->services()->get('auth', 'local');
        return $app->services()->activeId('auth') === 'local'
            || ($local instanceof \App\Services\Auth\LocalProvider && $local->membersMayUse());
    }

    public static function breakGlass(App $app): bool
    {
        return is_file($app->paths->storage('enable-local-login'));
    }
}

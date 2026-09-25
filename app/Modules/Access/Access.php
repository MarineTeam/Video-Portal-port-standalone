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
    public static function breakGlass(App $app): bool
    {
        return is_file($app->paths->storage('enable-local-login'));
    }
}

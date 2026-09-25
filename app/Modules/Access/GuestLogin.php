<?php

declare(strict_types=1);

namespace App\Modules\Access;

use App\Core\Db;

/**
 * The guest-login switch: closed until an administrator opens it, and read
 * as closed when the settings row has never been written.
 */
final class GuestLogin
{
    public static function enabled(Db $db): bool
    {
        return (bool) $db->value('SELECT guest_login_enabled FROM {{auth_settings}} WHERE id = ?', ['singleton']);
    }

    /** One upsert, the same value on both the create and the update side. */
    public static function set(Db $db, bool $enabled): void
    {
        $db->run(
            'INSERT INTO {{auth_settings}} (id, guest_login_enabled, updated_at) VALUES (\'singleton\', ?, ?) ON DUPLICATE KEY UPDATE guest_login_enabled = VALUES(guest_login_enabled), updated_at = VALUES(updated_at)',
            [$enabled ? 1 : 0, Db::now()],
        );
    }
}

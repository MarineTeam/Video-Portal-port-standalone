<?php

declare(strict_types=1);

namespace App\Modules\Library;

/**
 * The server-side throttle on view counts: an HMAC of the caller's address
 * under app_key — never the address — short enough to index, and blanked by
 * the daily job after a day, so it is a throttle and not a record.
 */
final class ViewKey
{
    public const THROTTLE_SECONDS = 30 * 60;

    public static function viewKey(?string $address, string $secret): ?string
    {
        $address = trim((string) $address);
        if ($address === '' || $secret === '') {
            return null;
        }
        return substr(hash_hmac('sha256', strtolower($address), $secret), 0, 32);
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Push;

/**
 * Web Push subscriptions are URLs the browser hands the page, and the server
 * POSTs a signed body to every one of them on every notification. So only
 * the browsers' own push services are accepted — https, a known host matched
 * on a label boundary, no credentials — and a member holds at most eight.
 */
final class PushEndpoint
{
    public const MAX_PER_MEMBER = 8;

    public const HOSTS = [
        'fcm.googleapis.com',
        'android.googleapis.com',
        'push.services.mozilla.com',
        'notify.windows.com',
        'push.apple.com',
        'push.samsungosp.com',
    ];

    /** @param list<string> $extraSuffixes the admin's additions, for a browser not on the list */
    public static function isPushServiceEndpoint(string $endpoint, array $extraSuffixes = []): bool
    {
        $parts = parse_url($endpoint);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || !isset($parts['host'])) {
            return false;
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) && $parts['port'] !== 443) {
            return false;
        }
        $host = strtolower($parts['host']);
        foreach ([...self::HOSTS, ...array_map(fn ($s) => strtolower(trim($s, " .\t")), $extraSuffixes)] as $suffix) {
            if ($suffix === '') {
                continue;
            }
            if ($host === $suffix || str_ends_with($host, '.' . $suffix)) {
                return true;
            }
        }
        return false;
    }

    /** @return list<string> */
    public static function extraSuffixes(?string $setting): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $setting))));
    }

    /**
     * Which of a member's existing subscriptions to drop so exactly one more
     * fits, oldest first.
     *
     * @param list<array{id: string, createdAt: string}> $existing
     * @return list<string>
     */
    public static function subscriptionsToEvict(array $existing, int $max = self::MAX_PER_MEMBER): array
    {
        $excess = count($existing) - ($max - 1);
        if ($excess <= 0) {
            return [];
        }
        usort($existing, fn ($a, $b) => strcmp($a['createdAt'], $b['createdAt']));
        return array_map(fn ($s) => $s['id'], array_slice($existing, 0, $excess));
    }
}

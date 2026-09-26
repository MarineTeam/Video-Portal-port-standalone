<?php

declare(strict_types=1);

namespace App\Modules\Api;

use App\Core\Db;

/**
 * Keys for the read API (lib/api-keys.ts).
 *
 * A key is a password a machine keeps in a config file, so it is treated
 * like one: only its SHA-256 is stored, it is shown once when it is made and
 * never again, and what the list shows afterwards is a prefix, who made it
 * and when it was last used — the three things anybody wants after deciding
 * one has leaked.
 */
final class Keys
{
    /** Recognisable at a glance in a log or a config file. */
    public const PREFIX = 'mt_live_';

    /** 32 bytes of randomness, base64url. */
    public const BYTES = 32;

    /** Enough of the key to tell two rows apart, and no more. */
    public const PREFIX_SHOWN = 16;

    /** Requests a minute, per key. */
    public const PER_MINUTE = 120;

    public const DEFAULT_PAGE = 50;
    public const MAX_PAGE = 200;

    public const OK = 'ok';
    public const REVOKED = 'revoked';
    public const EXPIRED = 'expired';

    /**
     * What a key may read. No hierarchy: `events:read` gives you "forty
     * people are coming"; `events:registrations` gives you their phone
     * numbers, and one does not follow from the other.
     *
     * @var array<string, array{label: string, hint: string, personal: bool}>
     */
    public const SCOPES = [
        'content:read' => [
            'label' => 'Catalogue',
            'hint' => 'Categories, series, videos and files — drafts and members-only items included, with flags saying which is which.',
            'personal' => false,
        ],
        'events:read' => [
            'label' => 'Events',
            'hint' => 'What is on, and how many are coming.',
            'personal' => false,
        ],
        'events:registrations' => [
            'label' => 'Who signed up',
            'hint' => 'The names, email addresses and phone numbers people typed into a sign-up form.',
            'personal' => true,
        ],
        'schedules:read' => [
            'label' => 'Rotas',
            'hint' => 'The schedules and their dates, with the names on them.',
            'personal' => true,
        ],
        'groups:read' => [
            'label' => 'Small groups',
            'hint' => 'The groups and when they meet. Never an address, and never who is in one.',
            'personal' => false,
        ],
        'analytics:read' => [
            'label' => 'Figures',
            'hint' => 'Counts and totals: how much is published, how much is watched.',
            'personal' => false,
        ],
    ];

    /** A new key, shown once. */
    public static function newKey(): string
    {
        // base64url: it survives a config file, a URL and a curl command
        // without anybody having to think about escaping.
        return self::PREFIX . rtrim(strtr(base64_encode(random_bytes(self::BYTES)), '+/', '-_'), '=');
    }

    public static function hash(string $key): string
    {
        return hash('sha256', $key);
    }

    public static function prefixOf(string $key): string
    {
        return substr($key, 0, self::PREFIX_SHOWN);
    }

    /** Constant-time, and unbothered by two strings of different lengths. */
    public static function sameHash(string $a, string $b): bool
    {
        return strlen($a) === strlen($b) && hash_equals($a, $b);
    }

    /**
     * The key out of an Authorization header.
     *
     * Anything that is not one of ours is refused here, before it reaches
     * the database: a bearer token from some other system should cost a
     * string comparison, not a query.
     */
    public static function bearerFrom(?string $header): ?string
    {
        if ($header === null || !preg_match('/^Bearer\s+(\S+)$/i', trim($header), $m)) {
            return null;
        }
        $key = $m[1];
        return str_starts_with($key, self::PREFIX) && strlen($key) > strlen(self::PREFIX) + 20 ? $key : null;
    }

    /**
     * The scopes a key is made with: ours only, each once, in a fixed order.
     *
     * @param mixed $wanted
     * @return list<string>
     */
    public static function cleanScopes(mixed $wanted): array
    {
        $asked = [];
        foreach (is_array($wanted) ? $wanted : [] as $scope) {
            if (is_string($scope)) {
                $asked[trim($scope)] = true;
            }
        }
        $out = [];
        foreach (array_keys(self::SCOPES) as $scope) {
            if (isset($asked[$scope])) {
                $out[] = $scope;
            }
        }
        return $out;
    }

    /**
     * Whether a key holds a scope. Never implied by another: holding
     * `events:registrations` does not give you `events:read`, and holding
     * everything else does not give you either.
     *
     * @param list<string> $held
     */
    public static function allows(array $held, string $scope): bool
    {
        return in_array($scope, $held, true);
    }

    /**
     * @param array<string, mixed> $key
     * @return 'ok'|'revoked'|'expired'
     */
    public static function state(array $key, ?string $now = null): string
    {
        // Revoked ahead of expired: somebody switched it off, which is the
        // more useful thing to be told.
        if (($key['revoked_at'] ?? $key['revokedAt'] ?? null) !== null) {
            return self::REVOKED;
        }
        $expires = $key['expires_at'] ?? $key['expiresAt'] ?? null;
        if (!is_string($expires) || $expires === '') {
            return self::OK;
        }
        $zone = new \DateTimeZone('UTC');
        // Expired the moment it expires, not after.
        return new \DateTimeImmutable($now ?? 'now', $zone) >= new \DateTimeImmutable($expires, $zone) ? self::EXPIRED : self::OK;
    }

    /** A page size a database can serve; a guess gets the default. */
    public static function pageSize(mixed $given): int
    {
        if (!is_numeric($given)) {
            return self::DEFAULT_PAGE;
        }
        $wanted = (int) $given;
        return $wanted < 1 ? self::DEFAULT_PAGE : min(self::MAX_PAGE, $wanted);
    }

    /**
     * Counts one request against a key's window, in a single statement.
     *
     * Read-then-write loses under concurrency: thirty callers all read "29
     * used" and all write 30. One UPDATE that both rolls the window over and
     * increments serves exactly the number that were left.
     *
     * A refused request still counts, because it still cost a lookup.
     *
     * @return array{allowed: bool, remaining: int, retryAfter: int}
     */
    public static function hit(Db $db, string $keyId, int $perMinute = self::PER_MINUTE, ?string $now = null): array
    {
        $at = Db::datetime(new \DateTimeImmutable($now ?? 'now', new \DateTimeZone('UTC')));
        // window_count is set BEFORE window_started_at on purpose: MySQL
        // evaluates a single-table UPDATE's assignments left to right, so a
        // clause reads whatever earlier clauses already wrote. Set the start
        // first and the count below it would test the new start, never see an
        // expired window, and count up for ever instead of rolling over.
        $db->run(
            'UPDATE {{api_keys}}
                SET window_count = CASE WHEN window_started_at <= ? - INTERVAL 60 SECOND THEN 1 ELSE window_count + 1 END,
                    window_started_at = CASE WHEN window_started_at <= ? - INTERVAL 60 SECOND THEN ? ELSE window_started_at END,
                    last_used_at = ?
              WHERE id = ?',
            [$at, $at, $at, $at, $keyId],
        );
        $row = $db->one('SELECT window_started_at, window_count FROM {{api_keys}} WHERE id = ?', [$keyId]);
        $count = (int) ($row['window_count'] ?? $perMinute + 1);
        $started = (string) ($row['window_started_at'] ?? $at);
        $secondsLeft = 60 - (new \DateTimeImmutable($at, new \DateTimeZone('UTC')))->getTimestamp()
            + (new \DateTimeImmutable($started, new \DateTimeZone('UTC')))->getTimestamp();
        return [
            'allowed' => $count <= $perMinute,
            'remaining' => max(0, $perMinute - $count),
            'retryAfter' => max(1, min(60, $secondsLeft)),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Counting in the database, because shared hosting has no process state to
 * count in. Each decision is one transaction holding the bucket's row lock,
 * so thirty requests arriving together get exactly the number of yeses that
 * were left.
 *
 *   hit()      a fixed-window request limit (comments, forms, API keys)
 *   fail()     a failed attempt (a password, a share-link passphrase): after
 *              the free allowance, an exponentially growing block
 *   blocked()  seconds until the bucket may try again
 */
final class RateLimiter
{
    public function __construct(private readonly Db $db)
    {
    }

    public static function bucket(string $kind, string $key): string
    {
        return substr($kind, 0, 40) . ':' . hash('sha256', strtolower($key));
    }

    /** Counts one request; false once $limit is exceeded within $window seconds. */
    public function hit(string $bucket, int $limit, int $window): bool
    {
        return $this->db->transaction(function (Db $db) use ($bucket, $limit, $window) {
            $row = $this->lock($db, $bucket);
            $now = time();
            $started = strtotime($row['window_started_at'] . ' UTC');
            if ($started <= $now - $window) {
                $db->run('UPDATE {{rate_limits}} SET window_started_at = ?, hits = 1 WHERE bucket = ?', [Db::now(), $bucket]);
                return true;
            }
            $hits = (int) $row['hits'] + 1;
            // A refused request still counts: it still cost a lookup.
            $db->run('UPDATE {{rate_limits}} SET hits = ? WHERE bucket = ?', [$hits, $bucket]);
            return $hits <= $limit;
        });
    }

    /** Seconds the bucket must still wait, or 0. */
    public function blocked(string $bucket): int
    {
        $until = $this->db->value('SELECT blocked_until FROM {{rate_limits}} WHERE bucket = ?', [$bucket]);
        if ($until === null) {
            return 0;
        }
        return max(0, strtotime($until . ' UTC') - time());
    }

    /**
     * Records a failure. The first $free cost nothing; after that each one
     * doubles the wait from $base seconds, capped at $max.
     */
    public function fail(string $bucket, int $free = 5, int $base = 30, int $max = 3600): int
    {
        return $this->db->transaction(function (Db $db) use ($bucket, $free, $base, $max) {
            $row = $this->lock($db, $bucket);
            // Failures forget themselves after a quiet day.
            $failures = strtotime($row['window_started_at'] . ' UTC') < time() - 86400 ? 1 : (int) $row['failures'] + 1;
            $wait = $failures > $free ? min($max, $base * 2 ** min(20, $failures - $free - 1)) : 0;
            $db->run(
                'UPDATE {{rate_limits}} SET failures = ?, window_started_at = ?, blocked_until = ? WHERE bucket = ?',
                [$failures, Db::now(), $wait > 0 ? Db::datetime(new \DateTimeImmutable("+$wait seconds")) : null, $bucket],
            );
            return $wait;
        });
    }

    public function clear(string $bucket): void
    {
        $this->db->delete('rate_limits', ['bucket' => $bucket]);
    }

    /** @return array<string, mixed> */
    private function lock(Db $db, string $bucket): array
    {
        $db->run('INSERT IGNORE INTO {{rate_limits}} (bucket, window_started_at, hits, failures) VALUES (?, ?, 0, 0)', [$bucket, Db::now()]);
        return $db->one('SELECT * FROM {{rate_limits}} WHERE bucket = ? FOR UPDATE', [$bucket]) ?? throw new \RuntimeException('Rate limit row missing.');
    }

    public static function prune(Db $db): int
    {
        return $db->run(
            'DELETE FROM {{rate_limits}} WHERE window_started_at < ? AND (blocked_until IS NULL OR blocked_until < ?)',
            [Db::datetime(new \DateTimeImmutable('-2 days')), Db::now()],
        )->rowCount();
    }
}

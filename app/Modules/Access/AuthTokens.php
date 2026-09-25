<?php

declare(strict_types=1);

namespace App\Modules\Access;

use App\Core\Db;
use App\Core\Id;

/**
 * Single-use tokens for password reset (an hour), magic links (fifteen
 * minutes) and email verification (a day). 32 random bytes; only their
 * SHA-256 is stored; consuming one is a conditional UPDATE, so two clicks on
 * the same link can't both sign somebody in.
 */
final class AuthTokens
{
    public const TTL = ['reset' => 3600, 'magic' => 900, 'verify' => 86400, 'email_change' => 86400];

    public static function issue(Db $db, string $userId, string $purpose, ?string $email = null): string
    {
        $raw = Id::token(32);
        // One live token per purpose per member: asking again replaces the last.
        $db->run('DELETE FROM {{auth_tokens}} WHERE user_id = ? AND purpose = ? AND used_at IS NULL', [$userId, $purpose]);
        $db->insert('auth_tokens', [
            'user_id' => $userId,
            'purpose' => $purpose,
            'token_hash' => hash('sha256', $raw),
            'email' => $email,
            'expires_at' => new \DateTimeImmutable('+' . (self::TTL[$purpose] ?? 3600) . ' seconds'),
        ]);
        return $raw;
    }

    /**
     * Looks a token up without using it (to show a confirmation page).
     *
     * @return array<string, mixed>|null
     */
    public static function peek(Db $db, string $raw, string $purpose): ?array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{43}$/', $raw)) {
            return null;
        }
        return $db->one(
            'SELECT * FROM {{auth_tokens}} WHERE token_hash = ? AND purpose = ? AND used_at IS NULL AND expires_at > ?',
            [hash('sha256', $raw), $purpose, Db::now()],
        );
    }

    /**
     * Uses a token. Returns its row only to the one caller whose UPDATE
     * claimed it.
     *
     * @return array<string, mixed>|null
     */
    public static function consume(Db $db, string $raw, string $purpose): ?array
    {
        $row = self::peek($db, $raw, $purpose);
        if ($row === null) {
            return null;
        }
        $claimed = $db->run(
            'UPDATE {{auth_tokens}} SET used_at = ? WHERE id = ? AND used_at IS NULL AND expires_at > ?',
            [Db::now(), $row['id'], Db::now()],
        )->rowCount();
        return $claimed === 1 ? $row : null;
    }

    public static function prune(Db $db): int
    {
        return $db->run('DELETE FROM {{auth_tokens}} WHERE expires_at < ?', [Db::datetime(new \DateTimeImmutable('-1 day'))])->rowCount();
    }
}

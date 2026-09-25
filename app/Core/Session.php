<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Sessions in the database, not in the host's shared /tmp.
 *
 * The cookie carries 32 random bytes; the table stores their SHA-256, so a
 * database read yields nothing that can be presented as a cookie. Lifetimes
 * are 30 days absolute and 7 idle. The id is replaced on sign-in and on any
 * privilege change, and "sign out everywhere" deletes a member's rows.
 */
final class Session
{
    public const ABSOLUTE = 30 * 86400;
    public const IDLE = 7 * 86400;
    /** Touch last_seen at most this often, so reads don't become writes. */
    private const TOUCH_EVERY = 300;

    private ?string $rawId = null;
    private ?string $userId = null;
    /** @var array<string, mixed> */
    private array $data = [];
    private bool $dirty = false;
    private bool $rotated = false;
    private bool $destroyed = false;
    private ?string $lastSeen = null;

    public function __construct(private readonly Db $db, private readonly Request $request)
    {
    }

    public static function cookieName(bool $https): string
    {
        // __Host- binds the cookie to this exact origin, path /, Secure — only
        // possible when the site sits at a domain root over HTTPS.
        return $https && Url::basePath() === '' ? '__Host-mt_session' : 'mt_session';
    }

    public function start(): void
    {
        $raw = $this->request->cookie(self::cookieName($this->request->https));
        if ($raw === null || !preg_match('/^[A-Za-z0-9_-]{43}$/', $raw)) {
            return;
        }
        $row = $this->db->one(
            'SELECT user_id, data, created_at, last_seen_at FROM {{sessions}} WHERE id_hash = ?',
            [hash('sha256', $raw)],
        );
        if ($row === null) {
            return;
        }
        $created = strtotime($row['created_at'] . ' UTC');
        $seen = strtotime($row['last_seen_at'] . ' UTC');
        if ($created < time() - self::ABSOLUTE || $seen < time() - self::IDLE) {
            $this->db->delete('sessions', ['id_hash' => hash('sha256', $raw)]);
            return;
        }
        $this->rawId = $raw;
        $this->userId = $row['user_id'];
        $decoded = json_decode((string) $row['data'], true);
        $this->data = is_array($decoded) ? $decoded : [];
        $this->lastSeen = $row['last_seen_at'];
    }

    public function id(): ?string
    {
        return $this->rawId;
    }

    public function userId(): ?string
    {
        return $this->userId;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
        $this->dirty = true;
    }

    public function forget(string $key): void
    {
        unset($this->data[$key]);
        $this->dirty = true;
    }

    /** Reads and removes a one-time value (a flash message, an OAuth state). */
    public function pull(string $key, mixed $default = null): mixed
    {
        $value = $this->data[$key] ?? $default;
        $this->forget($key);
        return $value;
    }

    /** Signs a member in, on a brand-new id. */
    public function login(string $userId): void
    {
        $this->regenerate();
        $this->userId = $userId;
        $this->dirty = true;
    }

    public function logout(): void
    {
        $this->destroy();
    }

    /** A fresh id for the same data: on login and on any privilege change. */
    public function regenerate(): void
    {
        if ($this->rawId !== null) {
            $this->db->delete('sessions', ['id_hash' => hash('sha256', $this->rawId)]);
        }
        $this->rawId = Id::token(32);
        $this->rotated = true;
        $this->dirty = true;
        $this->destroyed = false;
        // CSRF tokens are per session; a new session gets a new one.
        unset($this->data['_csrf']);
    }

    public function destroy(): void
    {
        if ($this->rawId !== null) {
            $this->db->delete('sessions', ['id_hash' => hash('sha256', $this->rawId)]);
        }
        $this->rawId = null;
        $this->userId = null;
        $this->data = [];
        $this->destroyed = true;
    }

    /** "Sign out everywhere": every session this member holds. */
    public function destroyAllFor(string $userId): void
    {
        $this->db->delete('sessions', ['user_id' => $userId]);
        if ($this->userId === $userId) {
            $this->rawId = null;
            $this->userId = null;
            $this->data = [];
            $this->destroyed = true;
        }
    }

    /** Ensures a session row exists (for a CSRF token, a share cookie, an OAuth state). */
    public function ensure(): void
    {
        if ($this->rawId === null) {
            $this->rawId = Id::token(32);
            $this->rotated = true;
            $this->dirty = true;
        }
    }

    /** Persists the session and writes the cookie onto the response. */
    public function commit(Response $response): void
    {
        $name = self::cookieName($this->request->https);
        $path = Url::basePath() === '' ? '/' : Url::basePath() . '/';
        if ($this->destroyed && $this->rawId === null) {
            $response->forgetCookie($name, $path, $this->request->https);
            return;
        }
        if ($this->rawId === null) {
            return;
        }
        $hash = hash('sha256', $this->rawId);
        $now = Db::now();
        if ($this->rotated) {
            $this->db->insert('sessions', [
                'id' => Id::new(),
                'id_hash' => $hash,
                'user_id' => $this->userId,
                'data' => $this->data,
                'ip' => substr($this->request->ip, 0, 45),
                'user_agent' => mb_substr($this->request->header('user-agent', '') ?? '', 0, 255),
                'created_at' => $now,
                'last_seen_at' => $now,
            ]);
            $response->cookie($name, $this->rawId, [
                'maxAge' => self::ABSOLUTE,
                'path' => $path,
                'secure' => $this->request->https,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            return;
        }
        $stale = $this->lastSeen === null || strtotime($this->lastSeen . ' UTC') < time() - self::TOUCH_EVERY;
        if ($this->dirty) {
            $this->db->update('sessions', ['data' => $this->data, 'user_id' => $this->userId, 'last_seen_at' => $now], ['id_hash' => $hash]);
        } elseif ($stale) {
            $this->db->update('sessions', ['last_seen_at' => $now], ['id_hash' => $hash]);
        }
    }

    public static function prune(Db $db): int
    {
        return $db->run(
            'DELETE FROM {{sessions}} WHERE created_at < ? OR last_seen_at < ?',
            [Db::datetime(new \DateTimeImmutable('-' . self::ABSOLUTE . ' seconds')), Db::datetime(new \DateTimeImmutable('-' . self::IDLE . ' seconds'))],
        )->rowCount();
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Profile;

use App\Core\Cache;
use App\Core\Db;
use App\Core\Id;

/**
 * A member's kept copy of every notification the site sent them, written
 * alongside each push or email so the inbox is the complete record whether
 * or not either reached them. Plugins write through add().
 */
final class Inbox
{
    public const PAGE = 30;

    public static function add(Db $db, string $userId, string $title, string $body, ?string $url = null): string
    {
        $id = Id::new();
        $db->insert('notifications', [
            'id' => $id,
            'user_id' => $userId,
            'title' => mb_substr($title, 0, 255),
            'body' => mb_substr($body, 0, 5000),
            'url' => $url !== null && self::isSafeUrl($url) ? $url : null,
        ]);
        Cache::forgetMemo("inbox-unread:$userId");
        return $id;
    }

    /** A same-site path or an http(s) address: what the inbox may link to. */
    public static function isSafeUrl(string $url): bool
    {
        if (strlen($url) > 2000 || preg_match('/[\x00-\x1f\s\\\\]/', $url)) {
            return false;
        }
        if (str_starts_with($url, '/')) {
            return !str_starts_with($url, '//');
        }
        return (bool) preg_match('#^https?://[^/]+#i', $url);
    }

    public static function unreadCount(Db $db, string $userId): int
    {
        return (int) Cache::memo("inbox-unread:$userId", fn () => (int) $db->value(
            'SELECT COUNT(*) FROM {{notifications}} WHERE user_id = ? AND read_at IS NULL',
            [$userId],
        ));
    }

    /**
     * Newest first, a page at a time; $before is the createdAt/id of the last
     * row already shown.
     *
     * @return array{rows: list<array<string, mixed>>, hasMore: bool}
     */
    public static function page(Db $db, string $userId, ?string $beforeId = null, int $limit = self::PAGE): array
    {
        $params = [$userId];
        $where = 'user_id = ?';
        if ($beforeId !== null) {
            $anchor = $db->one('SELECT created_at, id FROM {{notifications}} WHERE id = ? AND user_id = ?', [$beforeId, $userId]);
            if ($anchor !== null) {
                $where .= ' AND (created_at < ? OR (created_at = ? AND id < ?))';
                array_push($params, $anchor['created_at'], $anchor['created_at'], $anchor['id']);
            }
        }
        $rows = $db->all(
            "SELECT id, title, body, url, read_at, created_at FROM {{notifications}} WHERE $where ORDER BY created_at DESC, id DESC LIMIT " . ($limit + 1),
            $params,
        );
        return ['rows' => array_slice($rows, 0, $limit), 'hasMore' => count($rows) > $limit];
    }

    /** @param list<string>|null $ids null for all */
    public static function markRead(Db $db, string $userId, ?array $ids): int
    {
        Cache::forgetMemo("inbox-unread:$userId");
        if ($ids === null) {
            return $db->run('UPDATE {{notifications}} SET read_at = ? WHERE user_id = ? AND read_at IS NULL', [Db::now(), $userId])->rowCount();
        }
        if ($ids === []) {
            return 0;
        }
        $marks = implode(', ', array_fill(0, count($ids), '?'));
        return $db->run("UPDATE {{notifications}} SET read_at = ? WHERE user_id = ? AND read_at IS NULL AND id IN ($marks)", [Db::now(), $userId, ...$ids])->rowCount();
    }

    /** @param list<string>|null $ids null for all */
    public static function delete(Db $db, string $userId, ?array $ids): int
    {
        Cache::forgetMemo("inbox-unread:$userId");
        if ($ids === null) {
            return $db->run('DELETE FROM {{notifications}} WHERE user_id = ?', [$userId])->rowCount();
        }
        if ($ids === []) {
            return 0;
        }
        $marks = implode(', ', array_fill(0, count($ids), '?'));
        return $db->run("DELETE FROM {{notifications}} WHERE user_id = ? AND id IN ($marks)", [$userId, ...$ids])->rowCount();
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Library;

use App\Core\Db;

/**
 * The share_access cookie's tokens resolved to grants, every request: a
 * revoked, expired or somebody-else's link grants nothing, whatever the
 * browser holds. Only links made with the override carry a grant.
 */
final class ShareAccess
{
    /**
     * @param list<string> $tokens
     * @return array{series: list<string>, videos: list<string>}
     */
    public static function grants(Db $db, array $tokens, ?string $viewerEmail): array
    {
        $grants = ['series' => [], 'videos' => []];
        if ($tokens === []) {
            return $grants;
        }
        $marks = implode(', ', array_fill(0, count($tokens), '?'));
        $links = $db->all("SELECT id, series_id, video_id, visibility, grants_access, expires_at, revoked_at, password_hash FROM {{share_links}} WHERE token IN ($marks) AND grants_access = 1", $tokens);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        foreach ($links as $link) {
            $recipients = $link['visibility'] === 'PRIVATE'
                ? array_map('strval', $db->column('SELECT email FROM {{share_link_recipients}} WHERE share_link_id = ?', [$link['id']]))
                : [];
            if (ShareLinks::status($link, $recipients, $viewerEmail, $now) !== 'ok') {
                continue;
            }
            if ($link['series_id'] !== null) {
                $grants['series'][] = (string) $link['series_id'];
            }
            if ($link['video_id'] !== null) {
                $grants['videos'][] = (string) $link['video_id'];
            }
        }
        return ['series' => array_values(array_unique($grants['series'])), 'videos' => array_values(array_unique($grants['videos']))];
    }
}

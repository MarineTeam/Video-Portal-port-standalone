<?php

declare(strict_types=1);

namespace App\Modules\Library;

use App\Core\App;
use App\Core\ApiError;
use App\Core\Db;

/**
 * The sync-video-status job: every video still PROCESSING is asked about
 * again, as the admin's "Check status" button does, so a finished encode
 * doesn't wait for somebody to click. It never touches published. An upload
 * the browser abandoned (its placeholder never got the service's id) is
 * marked FAILED after a day, so it stops being asked about.
 */
final class StatusSync
{
    public const ABANDONED_AFTER = 86400;

    public static function run(App $app, float $deadline): string
    {
        $videos = new Videos($app);
        $db = $app->db();
        $abandoned = $db->run(
            "UPDATE {{videos}} SET status = 'FAILED' WHERE status = 'PROCESSING' AND external_id LIKE 'pending-%' AND created_at < ?",
            [Db::datetime(new \DateTimeImmutable('-' . self::ABANDONED_AFTER . ' seconds'))],
        )->rowCount();
        $rows = $db->all("SELECT * FROM {{videos}} WHERE status = 'PROCESSING' AND deleted_at IS NULL AND external_id NOT LIKE 'pending-%' ORDER BY created_at LIMIT 200");
        $checked = $ready = $failed = 0;
        foreach ($rows as $row) {
            if (microtime(true) > $deadline) {
                break;
            }
            try {
                $fresh = $videos->sync($row);
                $checked++;
                $ready += $fresh['status'] === 'READY' ? 1 : 0;
                $failed += $fresh['status'] === 'FAILED' ? 1 : 0;
            } catch (ApiError $e) {
                // The service is unreachable or gone; the next run asks again.
                \App\Core\Log::warning('Status sync for video ' . $row['id'] . ': ' . $e->getMessage());
            }
        }
        return "checked $checked, ready $ready, failed " . ($failed + $abandoned);
    }
}

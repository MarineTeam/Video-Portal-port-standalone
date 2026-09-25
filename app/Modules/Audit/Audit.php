<?php

declare(strict_types=1);

namespace App\Modules\Audit;

use App\Core\Db;
use App\Core\Log;

/** The append-only record of admin and editor actions. */
final class Audit
{
    public static function log(Db $db, string $actorEmail, string $action, string $entityType, ?string $entityId = null, ?string $detail = null): void
    {
        try {
            $db->insert('audit_logs', [
                'actor_email' => mb_substr($actorEmail, 0, 255),
                'action' => mb_substr($action, 0, 191),
                'entity_type' => mb_substr($entityType, 0, 191),
                'entity_id' => $entityId,
                'detail' => $detail === null ? null : mb_substr($detail, 0, 5000),
            ]);
        } catch (\Throwable $e) {
            // An audit write failing must not undo the action it describes.
            Log::error('Audit write failed: ' . $e->getMessage(), ['action' => $action]);
        }
    }
}

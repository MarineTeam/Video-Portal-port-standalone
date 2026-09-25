<?php

declare(strict_types=1);

namespace App\Modules\Library;

use App\Core\ApiError;
use App\Core\App;
use App\Core\Validator;
use App\Modules\Plugins\PluginStates;

/**
 * The item a member's action names — {categoryId}, {seriesId} or
 * {videoId} — resolved the way every plugin needs it: the row, once the
 * reader may open it (anything else is "not found", so its existence
 * doesn't leak), its category, and the plugin's state there.
 */
final class ContentTarget
{
    /**
     * @param array<string, mixed> $row
     */
    private function __construct(
        public readonly string $kind,
        public readonly string $id,
        public readonly array $row,
        public readonly ?string $categoryId,
    ) {
    }

    /** "series_id", "video_id", "category_id". */
    public function column(): string
    {
        return $this->kind . '_id';
    }

    /**
     * @param array<string, mixed> $input
     * @param list<'category'|'series'|'video'> $kinds the ones this action accepts, most specific first
     * @param ?string $plugin refuse with 403 plugin_disabled where this plugin is off
     */
    public static function from(App $app, array $input, array $kinds, ?string $plugin = null): self
    {
        $rules = [];
        foreach ($kinds as $kind) {
            $rules[$kind . 'Id'] = ['id', 'nullable'];
        }
        $data = Validator::check(array_intersect_key($input, $rules), $rules);
        foreach (['video', 'series', 'category'] as $kind) {
            if (in_array($kind, $kinds, true) && isset($data[$kind . 'Id'])) {
                return self::resolve($app, $kind, (string) $data[$kind . 'Id'], $plugin);
            }
        }
        throw ApiError::invalid('Say which ' . implode(' or ', $kinds) . '.');
    }

    public static function resolve(App $app, string $kind, string $id, ?string $plugin = null): self
    {
        $db = $app->db();
        $access = ContentAccess::for($app);
        $row = null;
        $categoryId = null;
        $ok = false;
        if ($kind === 'category') {
            $row = $db->one('SELECT * FROM {{categories}} WHERE id = ? AND deleted_at IS NULL', [$id]);
            $ok = $row !== null && Visibility::isVisible($row, $access->now()) && $access->category($row) === ContentAccess::OK;
            $categoryId = $id;
        } elseif ($kind === 'series') {
            $row = $db->one('SELECT * FROM {{series}} WHERE id = ? AND deleted_at IS NULL', [$id]);
            $ok = $row !== null && $access->series($row) === ContentAccess::OK;
            $categoryId = $row['category_id'] ?? null;
        } elseif ($kind === 'video') {
            $row = $db->one('SELECT * FROM {{videos}} WHERE id = ? AND deleted_at IS NULL', [$id]);
            $series = $row !== null && $row['series_id'] !== null ? $db->one('SELECT * FROM {{series}} WHERE id = ?', [$row['series_id']]) : null;
            $ok = $row !== null && $access->video($row, $series) === ContentAccess::OK;
            $categoryId = $row['category_id'] ?? ($series['category_id'] ?? null);
        }
        if (!$ok || $row === null) {
            throw ApiError::notFound();
        }
        $categoryId = $categoryId !== null ? (string) $categoryId : null;
        if ($plugin !== null && !PluginStates::enabled($db, $plugin, $categoryId)) {
            throw new ApiError('This feature is switched off here.', 403, 'plugin_disabled');
        }
        return new self($kind, $id, $row, $categoryId);
    }
}

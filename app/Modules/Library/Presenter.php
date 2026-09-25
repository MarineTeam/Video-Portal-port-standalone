<?php

declare(strict_types=1);

namespace App\Modules\Library;

use App\Core\Json;

/**
 * Library rows as the original's JSON had them: Prisma's field names and
 * types (Json::row), plus the few fields the port stores differently.
 *
 *   videos: provider + external_id are sent as source (BUNNY | YOUTUBE |
 *     VIMEO, or the port's own provider names), bunnyVideoId (Bunny's id,
 *     else null) and externalId (the other sources' id, else null), with
 *     provider kept alongside for the providers the original didn't have;
 *     provider_data (the provider's private bookkeeping) never leaves.
 *   file_assets: storage_path is bunnyPath when the file is in Bunny Storage
 *     and never sent otherwise — a path in local storage is nobody's business.
 */
final class Presenter
{
    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function video(array $row): array
    {
        $out = Json::row('videos', $row, ['provider_data']);
        $source = VideoSource::source($row);
        $id = $row['external_id'] ?? null;
        $out['source'] = $source;
        $out['bunnyVideoId'] = $source === 'BUNNY' ? $id : null;
        $out['externalId'] = $source === 'BUNNY' ? null : $id;
        return $out;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function file(array $row): array
    {
        $out = Json::row('file_assets', $row, ['storage_path', 'cover_data_url']);
        $out['bunnyPath'] = ($row['backend'] ?? null) === 'bunny' ? ($row['storage_path'] ?? null) : null;
        return $out;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function series(array $row): array
    {
        return Json::row('series', $row);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function category(array $row): array
    {
        return Json::row('categories', $row);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public static function many(string $kind, array $rows): array
    {
        return array_map(fn ($r) => self::$kind($r), $rows);
    }
}

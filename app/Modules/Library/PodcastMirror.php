<?php

declare(strict_types=1);

namespace App\Modules\Library;

use App\Core\App;
use App\Services\Files\BunnyStorageProvider;

/**
 * The public podcast zone (podcast-mirror.ts). An administrator opts an
 * audio file into its series' podcast; while it qualifies, a copy lives in
 * a separate public storage zone and the feed points there. The moment it
 * stops qualifying — members-only, unpublished, hidden, scheduled out,
 * expired, trashed, or its series any of those — the copy goes, and comes
 * back when the cause does, without anybody ticking it again: the intent
 * (podcast_published) is kept apart from the mirror's state (public_path).
 * public_path is written after the copy lands and cleared before the delete,
 * so the feed never advertises a file that isn't there.
 */
final class PodcastMirror
{
    /**
     * @param array<string, mixed> $file
     * @param array<string, mixed>|null $series
     */
    public static function isMirrorEligible(array $file, ?array $series, bool $categoryMemberOnly, \DateTimeImmutable $now): bool
    {
        if (!(bool) ($file['podcast_published'] ?? false) || $series === null) {
            return false;
        }
        if (!str_starts_with((string) ($file['mime_type'] ?? ''), 'audio/')) {
            return false;
        }
        if ((bool) ($file['member_only'] ?? false) || (bool) ($series['member_only'] ?? false) || $categoryMemberOnly) {
            return false;
        }
        return Visibility::isVisible($file, $now) && Visibility::isVisible($series, $now);
    }

    /** podcast/<file id>/<its filename>, so the zone's contents explain themselves. */
    public static function publicPathFor(array $file): string
    {
        $name = basename((string) ($file['storage_path'] ?? ''));
        $name = (string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $name);
        return 'podcast/' . $file['id'] . '/' . ($name !== '' && $name !== '.' ? $name : (string) $file['id']);
    }

    public static function provider(App $app): ?BunnyStorageProvider
    {
        $p = $app->services()->get('files', 'bunny');
        return $p instanceof BunnyStorageProvider && $app->services()->savedConfig('files', 'bunny') !== [] && $p->hasPublicZone() ? $p : null;
    }

    /**
     * Brings the public zone in line with every file's current state. A
     * change in the admin runs it for removals only, so no request waits on
     * a copy; the job does the copying.
     *
     * @return array{copied: int, removed: int, failed: int}
     */
    public static function reconcile(App $app, ?float $deadline = null, bool $removalsOnly = false): array
    {
        $out = ['copied' => 0, 'removed' => 0, 'failed' => 0];
        $provider = self::provider($app);
        if ($provider === null) {
            return $out;
        }
        $db = $app->db();
        $access = new ContentAccess($app, Viewer::guest());
        $now = $access->now();
        $rows = $db->all("SELECT * FROM {{file_assets}} WHERE backend = 'bunny' AND (podcast_published = 1 OR public_path IS NOT NULL)");
        foreach ($rows as $file) {
            if ($deadline !== null && microtime(true) > $deadline) {
                break;
            }
            $series = $file['series_id'] !== null ? $db->one('SELECT * FROM {{series}} WHERE id = ?', [$file['series_id']]) : null;
            $categoryId = $series['category_id'] ?? $file['category_id'];
            $eligible = self::isMirrorEligible($file, $series, $access->categoryIsMemberOnly($categoryId !== null ? (string) $categoryId : null), $now);
            try {
                if ($eligible && $file['public_path'] === null && !$removalsOnly) {
                    $path = self::publicPathFor($file);
                    $provider->copyToPublic((string) $file['storage_path'], $path);
                    $db->update('file_assets', ['public_path' => $path], ['id' => $file['id']]);
                    $out['copied']++;
                } elseif (!$eligible && $file['public_path'] !== null) {
                    $db->update('file_assets', ['public_path' => null], ['id' => $file['id']]);
                    $provider->deletePublic((string) $file['public_path']);
                    $out['removed']++;
                }
            } catch (\Throwable $e) {
                \App\Core\Log::warning('Podcast mirror for file ' . $file['id'] . ': ' . $e->getMessage());
                $out['failed']++;
            }
        }
        return $out;
    }
}

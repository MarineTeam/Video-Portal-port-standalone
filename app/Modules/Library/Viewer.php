<?php

declare(strict_types=1);

namespace App\Modules\Library;

use App\Core\App;
use App\Core\Cache;

/**
 * Who is looking, as far as content is concerned: the signed-in member (or
 * nobody), whether they are an administrator, the permission groups they
 * belong to (which is what a restricted item's "roles" name), and what their
 * share links let them into. Built once per request.
 */
final class Viewer
{
    /**
     * @param array<string, mixed>|null $user
     * @param list<string> $groupIds
     * @param array{series: list<string>, videos: list<string>} $grants
     */
    public function __construct(
        public readonly ?array $user,
        public readonly bool $isAdmin = false,
        public readonly array $groupIds = [],
        public readonly array $grants = ['series' => [], 'videos' => []],
    ) {
    }

    public static function guest(): self
    {
        return new self(null);
    }

    public static function current(App $app): self
    {
        return Cache::memo('viewer', function () use ($app): self {
            $user = null;
            try {
                $user = $app->currentUser()->user();
            } catch (\Throwable) {
            }
            $db = $app->db();
            $groups = $user === null ? [] : array_values(array_unique(array_map('strval', $db->column(
                'SELECT DISTINCT group_id FROM {{group_assignments}} WHERE user_id = ?',
                [$user['id']],
            ))));
            $tokens = ShareLinks::cookieTokens($app->request()->cookie(ShareLinks::COOKIE));
            $grants = ShareAccess::grants($db, $tokens, $user === null ? null : (string) $user['email']);
            return new self($user, ($user['role'] ?? null) === 'ADMIN', $groups, $grants);
        });
    }

    public function signedIn(): bool
    {
        return $this->user !== null;
    }

    public function id(): ?string
    {
        return $this->user === null ? null : (string) $this->user['id'];
    }

    public function email(): ?string
    {
        return $this->user === null ? null : (string) $this->user['email'];
    }

    public function hasSeriesGrant(?string $seriesId): bool
    {
        return $seriesId !== null && in_array($seriesId, $this->grants['series'], true);
    }

    public function hasVideoGrant(?string $videoId, ?string $seriesId): bool
    {
        return ($videoId !== null && in_array($videoId, $this->grants['videos'], true)) || $this->hasSeriesGrant($seriesId);
    }
}

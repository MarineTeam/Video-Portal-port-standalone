<?php

declare(strict_types=1);

namespace App\Modules\Library\Admin;

use App\Core\ApiError;
use App\Core\App;
use App\Core\Db;
use App\Core\Id;
use App\Modules\Audit\Audit;
use App\Modules\Library\CategoryTree;
use App\Support\Reorder;
use App\Support\Slug;

/**
 * What the four library admin screens share: which capability a row needs
 * and where it sits in the tree (so a scoped editor is held to their part of
 * it), positions among siblings, slugs and the aliases a rename leaves
 * behind, a series' tags, and the trash.
 */
final class Catalog
{
    public const TYPES = [
        'category' => ['table' => 'categories', 'capability' => 'manage_categories', 'entity' => 'Category', 'title' => 'name', 'slugType' => null],
        'series' => ['table' => 'series', 'capability' => 'manage_series', 'entity' => 'Series', 'title' => 'title', 'slugType' => 'SERIES'],
        'video' => ['table' => 'videos', 'capability' => 'manage_videos', 'entity' => 'Video', 'title' => 'title', 'slugType' => 'VIDEO'],
        'file' => ['table' => 'file_assets', 'capability' => 'manage_files', 'entity' => 'FileAsset', 'title' => 'title', 'slugType' => null],
    ];

    /** Fields only somebody who may publish can change. */
    public const PUBLISH_FIELDS = ['published', 'publishAt', 'unpublishAt', 'featured', 'pinned'];

    public function __construct(private readonly App $app)
    {
    }

    public function db(): Db
    {
        return $this->app->db();
    }

    /** @return array<string, mixed> */
    public function user(): array
    {
        return (array) $this->app->currentUser()->user();
    }

    public function actor(): string
    {
        return (string) $this->app->currentUser()->email();
    }

    /**
     * Records a library change, and fires library.changed($action, $type,
     * $id) for whatever depends on the tree as a whole (who may see what).
     */
    public function audit(string $action, string $type, string $id, ?string $detail = null): void
    {
        Audit::log($this->db(), $this->actor(), $action, self::TYPES[$type]['entity'], $id, $detail);
        $this->app->hooks->do('library.changed', $action, $type, $id, $this->app);
    }

    /** @return array<string, mixed> */
    public function find(string $type, string $id, bool $withTrashed = false): array
    {
        $table = self::TYPES[$type]['table'];
        $row = Id::isValid($id) ? $this->db()->one("SELECT * FROM {{{$table}}} WHERE id = ?" . ($withTrashed ? '' : ' AND deleted_at IS NULL'), [$id]) : null;
        if ($row === null) {
            throw ApiError::notFound();
        }
        return $row;
    }

    /**
     * Where a row sits, for a scoped permission check.
     *
     * @param array<string, mixed> $row
     * @return array{categoryId: ?string, seriesId: ?string}
     */
    public function scopeOf(string $type, array $row): array
    {
        return match ($type) {
            'category' => ['categoryId' => (string) $row['id'], 'seriesId' => null],
            'series' => ['categoryId' => $row['category_id'] ?? null, 'seriesId' => isset($row['id']) ? (string) $row['id'] : null],
            default => [
                'categoryId' => $row['category_id'] ?? (isset($row['series_id']) ? $this->db()->value('SELECT category_id FROM {{series}} WHERE id = ?', [$row['series_id']]) : null),
                'seriesId' => $row['series_id'] ?? null,
            ],
        };
    }

    /** @param array{categoryId: ?string, seriesId: ?string} $scope */
    public function can(string $capability, array $scope): bool
    {
        $user = $this->user();
        return ($user['role'] ?? null) === 'ADMIN' || $this->app->permissions()->has($user, $capability, $scope);
    }

    /** @param array{categoryId: ?string, seriesId: ?string} $scope */
    public function require(string $capability, array $scope): void
    {
        if (!$this->can($capability, $scope)) {
            throw ApiError::forbidden('You don’t have permission to change that here.');
        }
    }

    /**
     * Publishing, featuring and pinning need publish_content where the row is.
     *
     * @param array<string, mixed> $input
     * @param array{categoryId: ?string, seriesId: ?string} $scope
     */
    public function requirePublishIfTouched(array $input, array $scope): void
    {
        if (array_intersect(array_keys($input), self::PUBLISH_FIELDS) !== [] && !$this->can('publish_content', $scope)) {
            throw ApiError::forbidden('You can edit this, but publishing, featuring and pinning need the publish-content permission.');
        }
    }

    /**
     * The WHERE that keeps an admin list to the reader's part of the library.
     *
     * @return array{0: string, 1: list<string>}|null null when they may see none of it
     */
    public function listScope(string $capability, string $categoryColumn, ?string $seriesColumn): ?array
    {
        $scope = $this->app->permissions()->scope($this->user(), $capability);
        if ($scope['all']) {
            return ['1 = 1', []];
        }
        $parts = [];
        $params = [];
        if ($scope['categoryIds'] !== []) {
            $parts[] = "$categoryColumn IN (" . implode(', ', array_fill(0, count($scope['categoryIds']), '?')) . ')';
            array_push($params, ...$scope['categoryIds']);
        }
        if ($seriesColumn !== null && $scope['seriesIds'] !== []) {
            $parts[] = "$seriesColumn IN (" . implode(', ', array_fill(0, count($scope['seriesIds']), '?')) . ')';
            array_push($params, ...$scope['seriesIds']);
        }
        return $parts === [] ? null : ['(' . implode(' OR ', $parts) . ')', $params];
    }

    // Slugs ---------------------------------------------------------------------------

    public function uniqueSlug(string $type, string $wanted, ?string $exceptId = null): string
    {
        $table = self::TYPES[$type]['table'];
        return Slug::unique($wanted, function (string $slug) use ($table, $exceptId): bool {
            return $this->db()->value("SELECT 1 FROM {{{$table}}} WHERE slug = ?" . ($exceptId !== null ? ' AND id <> ?' : ''), $exceptId !== null ? [$slug, $exceptId] : [$slug]) !== null;
        }, $type);
    }

    /**
     * A rename leaves the old slug pointing here, and takes the new slug back
     * from any alias that held it (so the new address can't be shadowed).
     */
    public function recordSlugChange(string $type, string $id, string $old, string $new): void
    {
        $aliasType = self::TYPES[$type]['slugType'];
        if ($aliasType === null || $old === $new) {
            return;
        }
        $this->db()->run('DELETE FROM {{slug_aliases}} WHERE type = ? AND old_slug = ?', [$aliasType, $new]);
        $this->db()->run(
            'INSERT INTO {{slug_aliases}} (id, type, old_slug, target_id) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE target_id = VALUES(target_id)',
            [Id::new(), $aliasType, $old, $id],
        );
    }

    // Positions -----------------------------------------------------------------------

    /**
     * The siblings a row is ordered among: categories under the same parent,
     * series in the same category, videos and files in the same series (or,
     * standing alone, the same category).
     *
     * @param array<string, mixed> $row
     * @return array{0: string, 1: list<mixed>}
     */
    private function siblingsWhere(string $type, array $row): array
    {
        $eq = fn (string $col, mixed $v) => $v === null ? ["$col IS NULL", []] : ["$col = ?", [$v]];
        return match ($type) {
            'category' => $eq('parent_id', $row['parent_id'] ?? null),
            'series' => $eq('category_id', $row['category_id'] ?? null),
            default => ($row['series_id'] ?? null) !== null
                ? $eq('series_id', $row['series_id'])
                : (fn ($c) => [$c[0] . ' AND series_id IS NULL', $c[1]])($eq('category_id', $row['category_id'] ?? null)),
        };
    }

    /** @param array<string, mixed> $row */
    public function nextPosition(string $type, array $row): int
    {
        $table = self::TYPES[$type]['table'];
        [$where, $params] = $this->siblingsWhere($type, $row);
        return (int) $this->db()->value("SELECT COALESCE(MAX(position), -1) + 1 FROM {{{$table}}} WHERE deleted_at IS NULL AND $where", $params);
    }

    /**
     * Moves a row among its siblings — 'up', 'down', or to an index — and
     * rewrites their positions 0..n-1, so gaps and ties from old data heal.
     *
     * @param array<string, mixed> $row
     */
    public function move(string $type, array $row, string|int $to): void
    {
        $table = self::TYPES[$type]['table'];
        [$where, $params] = $this->siblingsWhere($type, $row);
        $this->db()->transaction(function (Db $db) use ($table, $where, $params, $row, $to): void {
            $ids = array_map('strval', $db->column("SELECT id FROM {{{$table}}} WHERE deleted_at IS NULL AND $where ORDER BY position, created_at, id FOR UPDATE", $params));
            $from = array_search((string) $row['id'], $ids, true);
            if ($from === false) {
                return;
            }
            $target = match ($to) {
                'up' => $from - 1,
                'down' => $from + 1,
                default => (int) $to,
            };
            foreach (Reorder::move($ids, $from, $target) as $position => $id) {
                $db->run("UPDATE {{{$table}}} SET position = ? WHERE id = ?", [$position, $id]);
            }
        });
    }

    // Tags ----------------------------------------------------------------------------

    /**
     * Tags as typed: trimmed, de-duplicated case-insensitively (the first
     * spelling wins), at most 30 of at most 50 characters.
     *
     * @param list<mixed> $tags
     * @return list<string>
     */
    public static function cleanTags(array $tags): array
    {
        $out = [];
        $seen = [];
        foreach ($tags as $tag) {
            if (!is_string($tag)) {
                continue;
            }
            $tag = mb_substr(trim((string) preg_replace('/\s+/u', ' ', $tag)), 0, 50);
            $key = mb_strtolower($tag);
            if ($tag !== '' && !isset($seen[$key])) {
                $seen[$key] = true;
                $out[] = $tag;
            }
        }
        return array_slice($out, 0, 30);
    }

    /** @param list<string> $tags */
    public function syncSeriesTags(string $seriesId, array $tags): void
    {
        $this->db()->run('DELETE FROM {{series_tags}} WHERE series_id = ?', [$seriesId]);
        foreach ($tags as $tag) {
            $this->db()->run('INSERT IGNORE INTO {{series_tags}} (series_id, tag) VALUES (?, ?)', [$seriesId, mb_strtolower($tag)]);
        }
    }

    // Categories ----------------------------------------------------------------------

    /** Refuses a parent that is the category itself or one of its descendants. */
    public function assertParentAllowed(string $categoryId, ?string $parentId): void
    {
        if ($parentId === null) {
            return;
        }
        if ($this->db()->value('SELECT 1 FROM {{categories}} WHERE id = ? AND deleted_at IS NULL', [$parentId]) === null) {
            throw ApiError::invalid('That parent category doesn’t exist.');
        }
        $tree = new CategoryTree(array_column($this->db()->all('SELECT id, parent_id FROM {{categories}}'), 'parent_id', 'id'));
        if (in_array($parentId, $tree->descendants([$categoryId]), true)) {
            throw ApiError::invalid('A category can’t go inside itself or one of its own subcategories.');
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Access;

use App\Core\Cache;
use App\Core\Db;
use App\Modules\Library\CategoryTree;

/**
 * Who may do what, where. ADMIN may do everything and is never a capability
 * a group can carry; everyone else holds capabilities through permission
 * groups (site-wide, or scoped to a category and everything under it, or to
 * one series) and through the simpler content-editor grants.
 *
 * Enforced server-side on every admin route, not only in the menus.
 */
final class Permissions
{
    public function __construct(private readonly Db $db)
    {
    }

    /**
     * @param array{capabilities: list<string>, categoryId: ?string, seriesId: ?string} $assignment
     * @param list<string> $scopeChain the scope's category and its ancestors
     */
    private static function grants(array $assignment, string $capability, ?string $scopeSeriesId, array $scopeChain, bool $hasScope): bool
    {
        if (!in_array($capability, $assignment['capabilities'], true)) {
            return false;
        }
        if ($assignment['categoryId'] === null && $assignment['seriesId'] === null) {
            return true;
        }
        if (!$hasScope) {
            return false;
        }
        if ($assignment['seriesId'] !== null) {
            return $assignment['seriesId'] === $scopeSeriesId;
        }
        return in_array($assignment['categoryId'], $scopeChain, true);
    }

    /**
     * The pure decision.
     *
     * @param list<array{capabilities: list<string>, categoryId: ?string, seriesId: ?string}> $assignments
     * @param list<string> $scopeChain
     */
    public static function decide(string $role, array $assignments, string $capability, ?string $scopeSeriesId = null, array $scopeChain = []): bool
    {
        if ($role === 'ADMIN') {
            return true;
        }
        $hasScope = $scopeSeriesId !== null || $scopeChain !== [];
        foreach ($assignments as $assignment) {
            if (self::grants($assignment, $capability, $scopeSeriesId, $scopeChain, $hasScope)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string, mixed>|null $user
     * @param array{categoryId?: ?string, seriesId?: ?string}|null $scope
     */
    public function has(?array $user, string $capability, ?array $scope = null): bool
    {
        if ($user === null) {
            return false;
        }
        if (($user['role'] ?? null) === 'ADMIN') {
            return true;
        }
        $seriesId = $scope['seriesId'] ?? null;
        $categoryId = $scope['categoryId'] ?? null;
        if ($seriesId !== null && $categoryId === null) {
            $categoryId = $this->db->value('SELECT category_id FROM {{series}} WHERE id = ?', [$seriesId]);
        }
        $chain = $categoryId !== null ? CategoryTree::load($this->db)->chain((string) $categoryId) : [];
        return self::decide('MEMBER', $this->assignments((string) $user['id']), $capability, $seriesId, $chain);
    }

    /** Whether the user holds $capability site-wide (no scope). */
    public function hasSiteWide(?array $user, string $capability): bool
    {
        return $this->has($user, $capability);
    }

    /** Whether the user holds $capability anywhere at all — for showing a menu. */
    public function hasAnywhere(?array $user, string $capability): bool
    {
        if ($user === null) {
            return false;
        }
        if (($user['role'] ?? null) === 'ADMIN') {
            return true;
        }
        foreach ($this->assignments((string) $user['id']) as $a) {
            if (in_array($capability, $a['capabilities'], true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Where the user holds $capability: 'all', or the categories (with their
     * descendants) and series it covers — what scopes an admin list.
     *
     * @return array{all: bool, categoryIds: list<string>, seriesIds: list<string>}
     */
    public function scope(?array $user, string $capability): array
    {
        if ($user === null) {
            return ['all' => false, 'categoryIds' => [], 'seriesIds' => []];
        }
        if (($user['role'] ?? null) === 'ADMIN') {
            return ['all' => true, 'categoryIds' => [], 'seriesIds' => []];
        }
        $cats = [];
        $series = [];
        foreach ($this->assignments((string) $user['id']) as $a) {
            if (!in_array($capability, $a['capabilities'], true)) {
                continue;
            }
            if ($a['categoryId'] === null && $a['seriesId'] === null) {
                return ['all' => true, 'categoryIds' => [], 'seriesIds' => []];
            }
            if ($a['seriesId'] !== null) {
                $series[] = $a['seriesId'];
            } else {
                $cats[] = (string) $a['categoryId'];
            }
        }
        return [
            'all' => false,
            'categoryIds' => CategoryTree::load($this->db)->descendants(array_values(array_unique($cats))),
            'seriesIds' => array_values(array_unique($series)),
        ];
    }

    /** @return list<array{capabilities: list<string>, categoryId: ?string, seriesId: ?string}> */
    public function assignments(string $userId): array
    {
        return Cache::memo("assignments:$userId", function () use ($userId) {
            $out = [];
            foreach ($this->db->all(
                'SELECT g.capabilities, a.category_id, a.series_id FROM {{group_assignments}} a
                 JOIN {{permission_groups}} g ON g.id = a.group_id WHERE a.user_id = ?',
                [$userId],
            ) as $row) {
                $caps = json_decode((string) $row['capabilities'], true);
                $out[] = [
                    'capabilities' => Capabilities::sanitize($caps),
                    'categoryId' => $row['category_id'],
                    'seriesId' => $row['series_id'],
                ];
            }
            foreach ($this->db->column('SELECT category_id FROM {{category_editors}} WHERE user_id = ?', [$userId]) as $categoryId) {
                $out[] = ['capabilities' => Capabilities::EDITOR_GRANT, 'categoryId' => (string) $categoryId, 'seriesId' => null];
            }
            foreach ($this->db->column('SELECT series_id FROM {{series_editors}} WHERE user_id = ?', [$userId]) as $seriesId) {
                $out[] = ['capabilities' => Capabilities::EDITOR_GRANT, 'categoryId' => null, 'seriesId' => (string) $seriesId];
            }
            return $out;
        });
    }

    /** Anyone who can reach any part of /admin: an admin, an editor, a group holder. */
    public function isStaff(?array $user): bool
    {
        if ($user === null) {
            return false;
        }
        return ($user['role'] ?? null) === 'ADMIN' || $this->assignments((string) $user['id']) !== [];
    }
}

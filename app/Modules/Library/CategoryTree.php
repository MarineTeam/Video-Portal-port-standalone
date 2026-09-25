<?php

declare(strict_types=1);

namespace App\Modules\Library;

use App\Core\Cache;
use App\Core\Db;

/**
 * The category table — tens of rows — loaded once per request and walked in
 * PHP. This replaces the original's WITH RECURSIVE query; the depth cap stays,
 * so a cycle an import somehow created can't loop.
 */
final class CategoryTree
{
    public const MAX_DEPTH = 32;

    /** @param array<string, ?string> $parents id => parent id */
    public function __construct(private readonly array $parents)
    {
    }

    public static function load(Db $db): self
    {
        return Cache::memo('category-tree', function () use ($db) {
            $parents = [];
            foreach ($db->all('SELECT id, parent_id FROM {{categories}}') as $row) {
                $parents[$row['id']] = $row['parent_id'];
            }
            return new self($parents);
        });
    }

    /**
     * The category and its ancestors, nearest first, root last.
     *
     * @return list<string>
     */
    public function chain(?string $categoryId): array
    {
        $out = [];
        $seen = [];
        $id = $categoryId;
        while ($id !== null && array_key_exists($id, $this->parents)) {
            if (isset($seen[$id]) || count($out) >= self::MAX_DEPTH) {
                break;
            }
            $seen[$id] = true;
            $out[] = $id;
            $id = $this->parents[$id];
        }
        return $out;
    }

    /**
     * Every category under the roots, the roots included.
     *
     * @param list<string> $roots
     * @return list<string>
     */
    public function descendants(array $roots): array
    {
        if ($roots === []) {
            return [];
        }
        $children = [];
        foreach ($this->parents as $id => $parent) {
            if ($parent !== null) {
                $children[$parent][] = $id;
            }
        }
        $out = [];
        $queue = array_values(array_filter($roots, fn ($r) => array_key_exists($r, $this->parents)));
        $depth = 0;
        while ($queue !== [] && $depth++ <= self::MAX_DEPTH) {
            $next = [];
            foreach ($queue as $id) {
                if (isset($out[$id])) {
                    continue;
                }
                $out[$id] = true;
                foreach ($children[$id] ?? [] as $child) {
                    $next[] = $child;
                }
            }
            $queue = $next;
        }
        return array_keys($out);
    }
}

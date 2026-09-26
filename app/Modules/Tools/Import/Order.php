<?php

declare(strict_types=1);

namespace App\Modules\Tools\Import;

use App\Core\Db;

/**
 * Which table to fill first.
 *
 * A video needs its series to exist, so series are written first. Rather
 * than keep that order by hand for ninety-odd tables — where one new
 * foreign key would make the list quietly wrong — it is read from the
 * database's own foreign keys and sorted.
 *
 * A table that points at itself (a category's parent, a comment's reply)
 * is not a cycle to be solved here: those columns are left null on the way
 * in and filled by the importer's relink pass, once every row exists.
 */
final class Order
{
    /**
     * @param list<string> $tables unprefixed table names
     * @return list<string> the same tables, parents before children
     */
    public static function sort(Db $db, array $tables): array
    {
        $wanted = array_flip($tables);
        $needs = array_fill_keys($tables, []);
        foreach (self::references($db) as [$table, $parent]) {
            if ($table !== $parent && isset($wanted[$table], $wanted[$parent])) {
                $needs[$table][$parent] = true;
            }
        }
        $done = [];
        $out = [];
        // Depth-first, so a chain three deep is ordered by one pass. A cycle
        // between two tables cannot deadlock the import: the row being
        // visited counts as placed, and what is left is a foreign key the
        // database will judge for itself.
        $visit = function (string $table) use (&$visit, &$done, &$out, $needs): void {
            if (isset($done[$table])) {
                return;
            }
            $done[$table] = true;
            foreach (array_keys($needs[$table] ?? []) as $parent) {
                $visit((string) $parent);
            }
            $out[] = $table;
        };
        foreach ($tables as $table) {
            $visit($table);
        }
        return $out;
    }

    /**
     * Every foreign key in this site's schema, as [table, the table it
     * points at], both with the prefix taken off.
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function references(Db $db): array
    {
        $rows = $db->all(
            'SELECT table_name AS child, referenced_table_name AS parent
               FROM information_schema.key_column_usage
              WHERE table_schema = DATABASE() AND referenced_table_name IS NOT NULL',
        );
        $prefix = $db->prefix();
        $strip = static function (string $name) use ($prefix): string {
            return $prefix !== '' && str_starts_with($name, $prefix) ? substr($name, strlen($prefix)) : $name;
        };
        $out = [];
        foreach ($rows as $row) {
            $out[] = [$strip((string) $row['child']), $strip((string) $row['parent'])];
        }
        return $out;
    }

    /**
     * The columns that point at a row in the same table, which are filled
     * after everything is in rather than on the way.
     *
     * @return array<string, list<string>> table => its self-referencing columns
     */
    public static function selfReferences(Db $db): array
    {
        $rows = $db->all(
            'SELECT table_name AS child, column_name AS col
               FROM information_schema.key_column_usage
              WHERE table_schema = DATABASE() AND referenced_table_name = table_name',
        );
        $prefix = $db->prefix();
        $out = [];
        foreach ($rows as $row) {
            $table = (string) $row['child'];
            if ($prefix !== '' && str_starts_with($table, $prefix)) {
                $table = substr($table, strlen($prefix));
            }
            $out[$table][] = (string) $row['col'];
        }
        return $out;
    }
}

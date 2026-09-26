<?php

declare(strict_types=1);

namespace App\Modules\Tools\Import;

/**
 * One exported row, as this database would write it.
 *
 * Pure on purpose: everything about what a row becomes is decided here,
 * where a test can state a row and read the answer, and the loader is left
 * with nothing but the writing. The conversions are the boring ones —
 * Postgres booleans and timestamps, arrays, JSON — plus whatever
 * {@see Mapping} says about this model.
 */
final class Row
{
    /**
     * @param array<string, mixed> $old one line of the export
     * @param array<string, string> $columns this table's columns => their SQL
     *        type, from the database rather than from a list kept here
     * @return array{row: array<string, mixed>, fanout: array<string, list<string>>, dropped: list<string>}
     *         `fanout` is table => the values for the second table's rows;
     *         `dropped` names columns the export had and this schema has not,
     *         which the screen reports rather than swallowing.
     */
    public static function convert(string $model, array $old, array $columns): array
    {
        $row = [];
        $fanout = [];
        $dropped = [];
        foreach ($old as $field => $value) {
            if (!is_string($field)) {
                continue;
            }
            $spec = Mapping::FANOUT[$model][$field] ?? null;
            if ($spec !== null && is_array($value)) {
                $fanout[$spec[0]] = self::fanoutValues($value, $spec[3]);
            }
            $column = Mapping::column($model, $field);
            if (!isset($columns[$column])) {
                // A column the export has and this schema has not. Silence
                // here would be the importer quietly losing a field, which is
                // the one thing an import must never do without saying so.
                $dropped[] = $field;
                continue;
            }
            $row[$column] = self::value($value, $columns[$column], Mapping::VALUES[$model][$column] ?? []);
        }
        foreach (Mapping::extras($model, $old) as $column => $value) {
            if (isset($columns[$column])) {
                $row[$column] = $value;
            }
        }
        return ['row' => $row, 'fanout' => $fanout, 'dropped' => $dropped];
    }

    /**
     * The distinct, non-empty values of a Postgres array, in the order they
     * were given. A tag list with a blank and a duplicate in it is a tag
     * list somebody typed, and the second table has a unique key.
     *
     * @param array<mixed> $value
     * @return list<string>
     */
    public static function fanoutValues(array $value, bool $lower): array
    {
        $out = [];
        foreach ($value as $one) {
            if (!is_scalar($one)) {
                continue;
            }
            $text = trim((string) $one);
            $text = $lower ? mb_strtolower($text) : $text;
            if ($text !== '' && !in_array($text, $out, true)) {
                $out[] = $text;
            }
        }
        return $out;
    }

    /**
     * One value, as MySQL wants it.
     *
     * An array or an object becomes JSON: every column here that receives
     * one is a JSON column, and a Postgres array and a JSON array are
     * written the same way.
     *
     * @param array<string, string> $enum
     */
    public static function value(mixed $value, string $type, array $enum = []): mixed
    {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        if (is_array($value)) {
            return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        if (!is_string($value)) {
            return $value;
        }
        if (isset($enum[$value])) {
            return $enum[$value];
        }
        // Only a column that really is a datetime: a title that happens to
        // read like one is a title.
        return str_starts_with($type, 'datetime') || str_starts_with($type, 'timestamp')
            ? self::timestamp($value)
            : $value;
    }

    /**
     * An ISO-8601 instant as MySQL's DATETIME(3), in UTC.
     *
     * Postgres writes "2026-03-01 09:30:00.123+00" for a column with a zone
     * and "2026-03-01 09:30:00.123" for one without; MySQL wants
     * "2026-03-01 09:30:00.123" and this schema keeps every instant in UTC.
     * A value with no zone is taken as UTC, which is what the old
     * deployment stored. Anything unparseable is returned as it came and
     * left for the database to refuse, which is louder than a silent zero
     * date.
     */
    public static function timestamp(string $value): string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}([T ]\d{2}:\d{2}(:\d{2}(\.\d+)?)?)?\s*(Z|[+-]\d{2}:?(\d{2})?)?$/', $value) !== 1) {
            return $value;
        }
        try {
            $at = new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return $value;
        }
        return $at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
    }
}

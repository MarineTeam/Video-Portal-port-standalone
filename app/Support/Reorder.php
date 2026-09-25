<?php

declare(strict_types=1);

namespace App\Support;

final class Reorder
{
    /**
     * Moves the item at $from to $to, shifting the rest; clamps $to to the
     * list. Returns the same array when nothing moves.
     *
     * @template T
     * @param list<T> $items
     * @return list<T>
     */
    public static function move(array $items, int $from, int $to): array
    {
        $count = count($items);
        if ($from < 0 || $from >= $count) {
            return $items;
        }
        $to = max(0, min($count - 1, $to));
        if ($to === $from) {
            return $items;
        }
        $item = $items[$from];
        array_splice($items, $from, 1);
        array_splice($items, $to, 0, [$item]);
        return $items;
    }
}

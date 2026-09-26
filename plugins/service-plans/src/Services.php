<?php

declare(strict_types=1);

namespace MarineTeam\Plugins\ServicePlans;

/**
 * A service's running order (the original's lib/services.ts). Each row of
 * a plan points at a file, which is either a hymn that is its own file —
 * inside a hymn-per-file series — or a whole book with a hymn number
 * written beside it. The difference decides where the row opens, whether
 * it can be put on a screen, and what the number means.
 */
final class Services
{
    /** The kinds of file a row can open into a reader. */
    public const BOOK_TYPES = ['application/pdf', 'application/epub+zip'];

    /** @param array<string, mixed> $series */
    public static function isHymnFile(?array $series): bool
    {
        return $series !== null && (bool) ($series['hymn_per_file'] ?? false);
    }

    /** @param array<string, mixed> $file */
    public static function isBook(array $file): bool
    {
        return in_array((string) ($file['mime_type'] ?? ''), self::BOOK_TYPES, true);
    }

    /**
     * Where a row opens.
     *
     * @param array<string, mixed> $item
     * @param array<string, mixed> $file
     * @param array<string, mixed>|null $series
     */
    public static function planItemHref(array $item, array $file, ?array $series): ?string
    {
        $id = (string) $file['id'];
        if (self::isHymnFile($series)) {
            // A hymn that is its own file opens at its lyrics, and carries no
            // number: its own page has nothing to resolve.
            return '/hymns/' . $id;
        }
        if (!self::isBook($file)) {
            return null;
        }
        $number = ($item['hymn_number'] ?? null) === null ? null : (int) $item['hymn_number'];
        // The contents knows how to resolve a number to a page.
        return '/books/' . $id . ($number !== null ? '?hymn=' . $number : '');
    }

    /**
     * The number to print beside a row: the one written for this service,
     * else the hymn's own printed number.
     *
     * @param array<string, mixed> $item
     * @param array<string, mixed> $file
     */
    public static function planItemNumber(array $item, array $file): ?int
    {
        if (($item['hymn_number'] ?? null) !== null) {
            return (int) $item['hymn_number'];
        }
        return ($file['page_number'] ?? null) === null ? null : (int) $file['page_number'];
    }

    /**
     * Whether this reader may open the row at all: the library's own
     * decision about the file, plus the states a plan can outlive.
     *
     * @param array<string, mixed> $file
     */
    public static function planItemReadable(array $file, bool $accessOk): bool
    {
        if (!$accessOk) {
            return false;
        }
        // A hymn unpublished, hidden or trashed since the plan was made.
        return (bool) ($file['published'] ?? false)
            && !(bool) ($file['hidden'] ?? false)
            && ($file['deleted_at'] ?? null) === null;
    }

    /**
     * Whether a row can be put on a screen: there have to be words, and
     * they have to be the words of this hymn.
     *
     * @param array<string, mixed> $item
     * @param array<string, mixed> $file
     * @param array<string, mixed>|null $series
     * @param array<string, mixed>|null $detail the typed-out hymn inside a book
     */
    public static function planItemPresentable(array $item, array $file, ?array $series, ?array $detail = null): bool
    {
        if (self::isHymnFile($series)) {
            // From the words on its own row; credits alone are not words.
            return trim((string) ($file['lyrics_text'] ?? '')) !== '';
        }
        if (!self::isBook($file) || ($item['hymn_number'] ?? null) === null) {
            // A whole book listed without a number has nothing to present.
            return false;
        }
        // A scan nobody has typed anything out of, or a hymn whose own words
        // are missing, is not presentable however much else is known about it.
        return $detail !== null && trim((string) ($detail['lyrics_text'] ?? '')) !== '';
    }

    /**
     * Where the presenter opens. The plan is carried so a hymn sung out of
     * order still knows which service it belongs to; it is left out when
     * the hymn isn't being presented as part of one.
     *
     * @param array<string, mixed> $item
     * @param array<string, mixed> $file
     * @param array<string, mixed>|null $series
     */
    public static function presentHref(array $item, array $file, ?array $series, ?string $planId = null): string
    {
        $query = [];
        if (!self::isHymnFile($series) && ($item['hymn_number'] ?? null) !== null) {
            $query['hymn'] = (string) (int) $item['hymn_number'];
        }
        if ($planId !== null && $planId !== '') {
            $query['plan'] = $planId;
        }
        return '/present/' . $file['id'] . ($query === [] ? '' : '?' . http_build_query($query));
    }
}

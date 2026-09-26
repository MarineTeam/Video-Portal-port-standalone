<?php

declare(strict_types=1);

namespace MarineTeam\Plugins\BookReader;

use App\Core\Db;
use App\Support\Hymnal;
use App\Support\PageOffset;
use App\Support\TocNav;

/**
 * Reading a shelf of books rather than one (lib/hymnal.ts's server half).
 *
 * A hymn is one of two things and the difference runs through all of this:
 * a hymn that is its own file in a hymn-per-file series, whose words are on
 * its own row; or a number inside a whole book, whose contents were indexed
 * from its bookmarks or typed in by hand. Searching has to answer across
 * both, because somebody looking for "Amazing Grace" does not know or care
 * which kind of book the church happens to keep it in.
 */
final class Books
{
    /**
     * Where a hymn was opened from, which is what lets a count be read
     * knowing what kind of opening it counts.
     */
    public const OPENINGS = ['hymn', 'book', 'reader', 'present'];

    public const LIMIT = 50;

    /**
     * Hymns matching a query across every book this reader may open.
     *
     * @param list<string> $fileIds the files already filtered for access
     * @return list<array<string, mixed>>
     */
    public static function search(Db $db, array $fileIds, string $query, int $limit = self::LIMIT): array
    {
        $text = trim($query);
        if ($fileIds === [] || mb_strlen($text) < 2) {
            return [];
        }
        $in = implode(',', array_fill(0, count($fileIds), '?'));
        $like = '%' . Db::likeEscape($text) . '%';
        $number = preg_match('/^\d{1,5}$/', $text) === 1 ? (int) $text : null;

        // By title, and by the number printed in the book — somebody at a
        // piano is as likely to have one as the other.
        $rows = $db->all(
            "SELECT h.*, f.title AS file_title, f.page_offset
             FROM {{book_hymns}} h JOIN {{file_assets}} f ON f.id = h.file_id
             WHERE h.file_id IN ($in) AND (h.title LIKE ? ESCAPE '\\\\'" . ($number === null ? '' : ' OR h.number = ?') . ")
             ORDER BY h.number IS NULL, h.number, h.title LIMIT " . max(1, $limit),
            $number === null ? [...$fileIds, $like] : [...$fileIds, $like, $number],
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = self::presentHymn($row);
        }
        return $out;
    }

    /**
     * Hymns whose printed words match, for a book somebody has read in.
     *
     * A matching page is attributed to the contents entry it falls inside,
     * so a search still answers with hymns rather than page numbers — which
     * is what somebody asked for.
     *
     * @param list<string> $fileIds
     * @return list<array<string, mixed>>
     */
    public static function searchText(Db $db, array $fileIds, string $query, int $limit = self::LIMIT): array
    {
        $text = trim($query);
        if ($fileIds === [] || mb_strlen($text) < 3) {
            return [];
        }
        $in = implode(',', array_fill(0, count($fileIds), '?'));
        $pages = $db->all(
            "SELECT p.file_id, p.page, p.text, f.title AS file_title, f.page_offset
             FROM {{book_pages}} p JOIN {{file_assets}} f ON f.id = p.file_id
             WHERE p.file_id IN ($in) AND p.text LIKE ? ESCAPE '\\\\'
             ORDER BY p.file_id, p.page LIMIT " . max(1, $limit * 4),
            [...$fileIds, '%' . Db::likeEscape($text) . '%'],
        );
        if ($pages === []) {
            return [];
        }
        $contents = [];
        foreach ($db->all("SELECT * FROM {{book_hymns}} WHERE file_id IN ($in) ORDER BY page, position", $fileIds) as $hymn) {
            $contents[(string) $hymn['file_id']][] = $hymn;
        }
        $out = [];
        $seen = [];
        foreach ($pages as $page) {
            $fileId = (string) $page['file_id'];
            $at = TocNav::currentTocIndex($contents[$fileId] ?? [], (int) $page['page']);
            $hymn = $at === null ? null : ($contents[$fileId][$at] ?? null);
            $key = $fileId . '#' . ($hymn['id'] ?? 'p' . $page['page']);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $found = $hymn === null
                ? [
                    'id' => null,
                    'title' => null,
                    'number' => null,
                    'page' => (int) $page['page'],
                    'printedPage' => PageOffset::printedPage((int) $page['page'], (int) $page['page_offset']),
                    'fileId' => $fileId,
                    'fileTitle' => (string) $page['file_title'],
                    'href' => '/read/' . $fileId . '?page=' . (int) $page['page'],
                ]
                : self::presentHymn($hymn + ['file_title' => $page['file_title'], 'page_offset' => $page['page_offset']]);
            $found['excerpt'] = self::excerpt((string) $page['text'], $text);
            $out[] = $found;
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    /**
     * One hymn by number, across a shelf: what the service-plan builder and
     * the "look up a hymn" box both ask.
     *
     * @param list<string> $fileIds
     * @return list<array<string, mixed>>
     */
    public static function lookup(Db $db, array $fileIds, int $number, int $limit = 20): array
    {
        if ($fileIds === [] || $number < 1) {
            return [];
        }
        $in = implode(',', array_fill(0, count($fileIds), '?'));
        $rows = $db->all(
            "SELECT h.*, f.title AS file_title, f.page_offset
             FROM {{book_hymns}} h JOIN {{file_assets}} f ON f.id = h.file_id
             WHERE h.file_id IN ($in) AND h.number = ? ORDER BY f.title LIMIT " . max(1, $limit),
            [...$fileIds, $number],
        );
        return array_map(fn (array $row) => self::presentHymn($row), $rows);
    }

    /**
     * A hymn-per-file series as the device keeps it: the hymns and their
     * words, with nothing in it a reader could not already see.
     *
     * @param list<array<string, mixed>> $files
     * @return array<string, mixed>
     */
    public static function offlineHymnal(array $series, array $files): array
    {
        $hymns = [];
        foreach ($files as $file) {
            $hymns[] = [
                'id' => (string) $file['id'],
                'title' => (string) $file['title'],
                'number' => $file['page_number'] === null ? null : (int) $file['page_number'],
                'lyrics' => (string) ($file['lyrics_text'] ?? ''),
                'author' => $file['song_author'],
                'copyright' => $file['song_copyright'],
                'ccli' => $file['ccli_number'],
                'group' => $file['group_label'],
                'key' => $file['musical_key'],
            ];
        }
        $hymns = Hymnal::readingOrder($hymns);
        return [
            'kind' => 'hymnal',
            'id' => (string) $series['id'],
            'title' => (string) $series['title'],
            'hymns' => $hymns,
            'fingerprint' => Hymnal::fingerprint($hymns),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function presentHymn(array $row): array
    {
        $page = (int) $row['page'];
        $fileId = (string) $row['file_id'];
        $number = $row['number'] === null ? null : (int) $row['number'];
        return [
            'id' => (string) $row['id'],
            'title' => (string) $row['title'],
            'number' => $number,
            'page' => $page,
            'printedPage' => PageOffset::printedPage($page, (int) ($row['page_offset'] ?? 0)),
            'fileId' => $fileId,
            'fileTitle' => (string) ($row['file_title'] ?? ''),
            // Where pressing it goes: the book at that page.
            'href' => '/read/' . $fileId . '?page=' . $page,
            'contentsHref' => '/books/' . $fileId . ($number === null ? '' : '?hymn=' . $number),
        ];
    }

    /** A line of the page around the match, for the results list. */
    private static function excerpt(string $text, string $query): string
    {
        $clean = trim((string) preg_replace('/\s+/u', ' ', $text));
        $at = mb_stripos($clean, $query);
        return $at === false ? mb_substr($clean, 0, 120) : \App\Support\Reader::excerptAround($clean, $at, mb_strlen($query));
    }
}

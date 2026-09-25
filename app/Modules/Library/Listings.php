<?php

declare(strict_types=1);

namespace App\Modules\Library;

use App\Core\App;
use App\Core\ErrorPage;
use App\Core\Id;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\Url;

/**
 * The library's cross-cutting pages: search, a tag, the speakers, the books
 * of the Bible that videos cite, and what was added lately. Like every
 * public list they go through Browse, so nobody finds what they can't open.
 */
final class Listings
{
    /** The canonical order /scripture lists books in; anything else follows, A–Z. */
    public const BOOKS = [
        'Genesis', 'Exodus', 'Leviticus', 'Numbers', 'Deuteronomy', 'Joshua', 'Judges', 'Ruth', '1 Samuel', '2 Samuel',
        '1 Kings', '2 Kings', '1 Chronicles', '2 Chronicles', 'Ezra', 'Nehemiah', 'Esther', 'Job', 'Psalm', 'Psalms', 'Proverbs',
        'Ecclesiastes', 'Song of Songs', 'Song of Solomon', 'Isaiah', 'Jeremiah', 'Lamentations', 'Ezekiel', 'Daniel', 'Hosea',
        'Joel', 'Amos', 'Obadiah', 'Jonah', 'Micah', 'Nahum', 'Habakkuk', 'Zephaniah', 'Haggai', 'Zechariah', 'Malachi',
        'Matthew', 'Mark', 'Luke', 'John', 'Acts', 'Romans', '1 Corinthians', '2 Corinthians', 'Galatians', 'Ephesians',
        'Philippians', 'Colossians', '1 Thessalonians', '2 Thessalonians', '1 Timothy', '2 Timothy', 'Titus', 'Philemon',
        'Hebrews', 'James', '1 Peter', '2 Peter', '1 John', '2 John', '3 John', 'Jude', 'Revelation',
    ];

    public function __construct(private readonly App $app)
    {
    }

    public static function register(Router $r, App $app): void
    {
        $self = new self($app);
        $r->get('/search', [$self, 'search']);
        $r->get('/tags/[tag]', [$self, 'tag']);
        $r->get('/speakers', [$self, 'speakers']);
        $r->get('/speakers/[slug]', [$self, 'speaker']);
        $r->get('/scripture', [$self, 'scripture']);
        $r->get('/scripture/[book]', [$self, 'book']);
        $r->get('/recently-added', [$self, 'recent']);
    }

    private function browse(): Browse
    {
        return Browse::for($this->app);
    }

    public function search(Request $req): Response
    {
        $q = Search::clean($req->query('q'));
        $categoryId = $req->query('category');
        $speakerId = $req->query('speaker');
        $filters = [
            'categoryId' => is_string($categoryId) && Id::isValid($categoryId) ? $categoryId : null,
            'speakerId' => is_string($speakerId) && Id::isValid($speakerId) ? $speakerId : null,
            'sort' => $req->query('sort') === 'newest' ? 'newest' : 'relevance',
        ];
        $results = $q === '' ? null : (new Search($this->app, $this->browse()))->run($q, $filters);
        return $this->app->page('library/search', [
            'title' => $q === '' ? t('nav.search') : t('search.resultsFor', ['q' => $q]),
            'noindex' => true,
            'q' => $q,
            'filters' => $filters,
            'results' => $results,
            'categories' => $this->browse()->categories(null),
            'speakers' => $this->app->db()->all('SELECT id, name FROM {{speakers}} ORDER BY name'),
        ]);
    }

    /** @param array<string, string> $p */
    public function tag(Request $req, array $p): Response
    {
        $tag = mb_strtolower(trim(rawurldecode($p['tag'])));
        if ($tag === '' || mb_strlen($tag) > 50) {
            return ErrorPage::render(404);
        }
        $series = $this->browse()->seriesWhere('EXISTS (SELECT 1 FROM {{series_tags}} t WHERE t.series_id = s.id AND t.tag = ?)', [$tag]);
        if ($series === []) {
            return ErrorPage::render(404);
        }
        return $this->app->page('library/tag', [
            'title' => '#' . $tag,
            'tag' => $tag,
            'series' => $series,
            'meta' => ['og:title' => '#' . $tag, 'og:url' => Url::absolute('/tags/' . rawurlencode($tag))],
        ]);
    }

    public function speakers(Request $req): Response
    {
        [$where, $params] = $this->browse()->access()->videoListSql('v', 's');
        $counts = array_column($this->app->db()->all(
            "SELECT v.speaker_id, COUNT(*) AS n FROM {{videos}} v LEFT JOIN {{series}} s ON s.id = v.series_id WHERE v.speaker_id IS NOT NULL AND $where GROUP BY v.speaker_id",
            $params,
        ), 'n', 'speaker_id');
        $speakers = array_map(fn ($sp) => $sp + ['video_count' => (int) ($counts[$sp['id']] ?? 0)], $this->app->db()->all('SELECT * FROM {{speakers}} ORDER BY position, name'));
        return $this->app->page('library/speakers', ['title' => t('library.speakers'), 'speakers' => $speakers]);
    }

    /** @param array<string, string> $p */
    public function speaker(Request $req, array $p): Response
    {
        $speaker = $this->app->db()->one('SELECT * FROM {{speakers}} WHERE slug = ?', [$p['slug']]);
        if ($speaker === null) {
            return ErrorPage::render(404);
        }
        $meta = ['og:title' => (string) $speaker['name'], 'og:type' => 'profile', 'og:url' => Url::absolute('/speakers/' . $speaker['slug'])];
        if (!empty($speaker['photo_url'])) {
            $meta['og:image'] = str_starts_with((string) $speaker['photo_url'], 'http') ? (string) $speaker['photo_url'] : Url::absolute((string) $speaker['photo_url']);
        }
        return $this->app->page('library/speaker', [
            'title' => $speaker['name'],
            'description' => Pages::excerpt($speaker['bio'] ?? null),
            'speaker' => $speaker,
            'videos' => $this->browse()->videosWhere('v.speaker_id = ?', [$speaker['id']], 'COALESCE(v.publish_at, v.created_at) DESC', 200),
            'meta' => $meta,
            'jsonLd' => [['@context' => 'https://schema.org', '@type' => 'Person', 'name' => (string) $speaker['name'], 'url' => Url::absolute('/speakers/' . $speaker['slug'])]],
        ]);
    }

    /** @return list<array{book: string, count: int}> books with at least one video the reader may open */
    public function books(): array
    {
        [$where, $params] = $this->browse()->access()->videoListSql('v', 's');
        $rows = $this->app->db()->all(
            "SELECT b.book, COUNT(DISTINCT v.id) AS n FROM {{video_scripture_books}} b JOIN {{videos}} v ON v.id = b.video_id LEFT JOIN {{series}} s ON s.id = v.series_id
             WHERE $where GROUP BY b.book",
            $params,
        );
        $order = array_flip(self::BOOKS);
        usort($rows, fn ($a, $b) => [$order[$a['book']] ?? 1000, (string) $a['book']] <=> [$order[$b['book']] ?? 1000, (string) $b['book']]);
        return array_map(fn ($r) => ['book' => (string) $r['book'], 'count' => (int) $r['n']], $rows);
    }

    public function scripture(Request $req): Response
    {
        return $this->app->page('library/scripture', ['title' => t('library.scripture'), 'books' => $this->books()]);
    }

    /** @param array<string, string> $p */
    public function book(Request $req, array $p): Response
    {
        $book = trim(rawurldecode($p['book']));
        $videos = $book === '' ? [] : $this->browse()->videosWhere(
            'EXISTS (SELECT 1 FROM {{video_scripture_books}} b WHERE b.video_id = v.id AND b.book = ?)',
            [$book],
            'COALESCE(v.publish_at, v.created_at) DESC',
            300,
        );
        if ($videos === []) {
            return ErrorPage::render(404);
        }
        return $this->app->page('library/book', [
            'title' => $book,
            'book' => $book,
            'videos' => $videos,
            'meta' => ['og:title' => $book, 'og:url' => Url::absolute('/scripture/' . rawurlencode($book))],
        ]);
    }

    public function recent(Request $req): Response
    {
        return $this->app->page('library/recent', [
            'title' => t('library.recentlyAdded'),
            'series' => $this->browse()->seriesWhere('1 = 1', [], 's.created_at DESC', 48),
            'videos' => $this->browse()->videosWhere('1 = 1', [], 'COALESCE(v.publish_at, v.created_at) DESC', 24),
        ]);
    }
}

<?php
/**
 * Plugin Name: Book reader
 * Slug:        book-reader
 * Version:     1.0.0
 * Description: Opens PDF and EPUB files in an in-app reader with contents, search, highlights and read-aloud, instead of only offering them as downloads.
 * Author:      Marine Team
 * Requires PHP: 8.2
 * Requires App: 3.0
 */

declare(strict_types=1);

use App\Core\App;
use App\Core\ErrorPage;
use App\Core\Hooks;
use App\Core\Id;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Modules\Library\ContentAccess;
use App\Modules\Plugins\BasePlugin;

/**
 * Books and hymnals: a book's contents at /books/<fileId>, one hymn's
 * words at /hymns/<fileId>, and the presenter at /present/<fileId>.
 *
 * A hymn is one of two things, and the difference runs through all of
 * this: a hymn that is its own file inside a hymn-per-file series, whose
 * words are on its own row; or a number inside a whole book, whose words
 * are only there if somebody has typed them out.
 *
 * The in-browser reader itself (pdf.js and epub.js, search, highlights,
 * read-aloud, the offline copy) is not here yet: /read/<fileId> hands the
 * file to the browser's own viewer meanwhile.
 */
return new class (__DIR__) extends BasePlugin {
    public function boot(Hooks $hooks, App $app): void
    {
        $this->useLang($hooks);
        $this->useTemplates($hooks);
        $hooks->on('routes.register', function (Router $r) use ($app): void {
            $r->get('/books/[fileId]', fn (Request $req, array $p) => $this->bookPage($app, $req, (string) $p['fileId']));
            $r->get('/hymns/[fileId]', fn (Request $req, array $p) => $this->hymnPage($app, $req, (string) $p['fileId']));
            $r->get('/present/[fileId]', fn (Request $req, array $p) => $this->presentPage($app, $req, (string) $p['fileId']));
            $r->get('/read/[fileId]', fn (Request $req, array $p) => $this->readPage($app, $req, (string) $p['fileId']));
        });
    }

    /**
     * Opens a file for a page: the library's own decision about it decides
     * what comes back, and the page itself only ever runs on a yes.
     *
     * @param callable(array<string, mixed>, ?array<string, mixed>): Response $then
     */
    private function guarded(App $app, Request $req, string $fileId, callable $then): Response
    {
        $db = $app->db();
        $file = Id::isValid($fileId) ? $db->one('SELECT * FROM {{file_assets}} WHERE id = ? AND deleted_at IS NULL', [$fileId]) : null;
        if ($file === null) {
            return ErrorPage::render(404);
        }
        $series = $file['series_id'] !== null ? $db->one('SELECT * FROM {{series}} WHERE id = ?', [$file['series_id']]) : null;
        $decision = ContentAccess::for($app)->file($file, $series);
        if ($decision === ContentAccess::LOGIN) {
            return $app->page('library/sign-in', [
                'title' => t('library.signInTitle'),
                'noindex' => true,
                'returnTo' => $req->path . ($req->query !== [] ? '?' . http_build_query($req->query) : ''),
            ], 401);
        }
        if ($decision === ContentAccess::DENIED) {
            return ErrorPage::render(403, t('library.restricted'));
        }
        if ($decision !== ContentAccess::OK) {
            return ErrorPage::render(404);
        }
        return $then($file, $series);
    }

    private function isHymnFile(?array $series): bool
    {
        return $series !== null && (bool) ($series['hymn_per_file'] ?? false);
    }

    /** A whole book: its contents, and where a number lands. */
    private function bookPage(App $app, Request $req, string $fileId): Response
    {
        return $this->guarded($app, $req, $fileId, function (array $file, ?array $series) use ($app, $req): Response {
        $db = $app->db();
        $wanted = ($req->query('hymn') ?? '') !== '' ? (int) $req->query('hymn') : null;
        if ($this->isHymnFile($series)) {
            // A hymn that is its own file has no contents of its own.
            return Response::redirect(url('/hymns/' . $file['id']));
        }
        $hymns = $db->all('SELECT * FROM {{book_hymns}} WHERE file_id = ? ORDER BY position, page', [$file['id']]);
        $found = null;
        foreach ($hymns as $hymn) {
            if ($wanted !== null && (int) ($hymn['number'] ?? 0) === $wanted) {
                $found = $hymn;
            }
        }
        $detail = $wanted === null ? null : $db->one('SELECT * FROM {{book_hymn_details}} WHERE file_id = ? AND number = ?', [$file['id'], $wanted]);
        return $app->page('book-reader/book', [
            'title' => (string) $file['title'],
            'file' => ['id' => (string) $file['id'], 'title' => (string) $file['title'], 'mime' => (string) ($file['mime_type'] ?? ''), 'pageOffset' => (int) $file['page_offset']],
            'hymns' => array_map(fn (array $h) => [
                'title' => (string) $h['title'],
                'number' => $h['number'] === null ? null : (int) $h['number'],
                'page' => (int) $h['page'],
                'depth' => (int) $h['depth'],
            ], $hymns),
            'wanted' => $wanted,
            'found' => $found === null ? null : ['title' => (string) $found['title'], 'page' => (int) $found['page']],
            'words' => $detail === null ? null : (string) ($detail['lyrics_text'] ?? ''),
            'credits' => $detail === null ? null : ['ccli' => $detail['ccli_number'], 'author' => $detail['author'], 'copyright' => $detail['copyright']],
        ]);
        });
    }

    /** One hymn that is its own file: its words, and its credits. */
    private function hymnPage(App $app, Request $req, string $fileId): Response
    {
        return $this->guarded($app, $req, $fileId, fn (array $file, ?array $series) => $app->page('book-reader/hymn', [
            'title' => (string) $file['title'],
            'file' => [
                'id' => (string) $file['id'],
                'title' => (string) $file['title'],
                'number' => $file['page_number'] === null ? null : (int) $file['page_number'],
                'group' => $file['group_label'],
                'words' => (string) ($file['lyrics_text'] ?? ''),
                'mime' => (string) ($file['mime_type'] ?? ''),
            ],
            'credits' => ['ccli' => $file['ccli_number'], 'author' => $file['song_author'], 'copyright' => $file['song_copyright'], 'key' => $file['musical_key'], 'tempo' => $file['tempo_bpm']],
            'series' => $series === null ? null : ['title' => (string) $series['title'], 'slug' => (string) $series['slug']],
        ]));
    }

    /**
     * The words, big. A projector is required to show the copyright line
     * while they are up, so it is part of the view rather than an extra.
     */
    private function presentPage(App $app, Request $req, string $fileId): Response
    {
        return $this->guarded($app, $req, $fileId, function (array $file, ?array $series) use ($app, $req): Response {
        $db = $app->db();
        $number = ($req->query('hymn') ?? '') !== '' ? (int) $req->query('hymn') : null;
        $detail = $this->isHymnFile($series) || $number === null
            ? null
            : $db->one('SELECT * FROM {{book_hymn_details}} WHERE file_id = ? AND number = ?', [$file['id'], $number]);
        $words = $this->isHymnFile($series) ? (string) ($file['lyrics_text'] ?? '') : (string) ($detail['lyrics_text'] ?? '');
        if (trim($words) === '') {
            return ErrorPage::render(404);
        }
        $plan = ($req->query('plan') ?? '') !== '' ? $db->one('SELECT id, title FROM {{service_plans}} WHERE id = ?', [$req->query('plan')]) : null;
        return $app->page('book-reader/present', [
            'title' => (string) $file['title'],
            'noindex' => true,
            'heading' => (string) $file['title'] . ($number !== null ? ' · ' . $number : ''),
            'verses' => array_values(array_filter(array_map('trim', preg_split('/\n{2,}/', str_replace(["\r\n", "\r"], "\n", $words)) ?: []), fn (string $v) => $v !== '')),
            'copyright' => $this->isHymnFile($series) ? $file['song_copyright'] : ($detail['copyright'] ?? null),
            'ccli' => $this->isHymnFile($series) ? $file['ccli_number'] : ($detail['ccli_number'] ?? null),
            'plan' => $plan === null ? null : ['id' => (string) $plan['id'], 'title' => (string) $plan['title']],
            'script' => $this->asset('present.js'),
        ], 200, 'layouts/site');
        });
    }

    /** The file itself, meanwhile, through the app's own content route. */
    private function readPage(App $app, Request $req, string $fileId): Response
    {
        return $this->guarded($app, $req, $fileId, fn (array $file, ?array $series) => $app->page('book-reader/read', [
            'title' => (string) $file['title'],
            'file' => ['id' => (string) $file['id'], 'title' => (string) $file['title'], 'mime' => (string) ($file['mime_type'] ?? '')],
        ]));
    }
};

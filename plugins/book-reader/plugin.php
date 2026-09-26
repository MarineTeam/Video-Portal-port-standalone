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

require_once __DIR__ . '/src/Books.php';

use App\Core\ApiError;
use App\Core\App;
use App\Core\Db;
use App\Core\ErrorPage;
use App\Core\Hooks;
use App\Core\Id;
use App\Core\Json;
use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\Validator;
use App\Modules\Audit\Audit;
use App\Modules\Library\Browse;
use App\Modules\Library\ContentAccess;
use App\Modules\Plugins\BasePlugin;
use App\Support\BookContents;
use App\Support\Hymnal;
use App\Support\PageOffset;
use App\Support\Reader;
use App\Support\TocNav;
use MarineTeam\Plugins\BookReader\Books;

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
            $member = Middleware::member($app);
            $manage = Middleware::can($app, 'manage_files');

            $r->get('/books/[fileId]', fn (Request $req, array $p) => $this->bookPage($app, $req, (string) $p['fileId']));
            $r->get('/hymns/[fileId]', fn (Request $req, array $p) => $this->hymnPage($app, $req, (string) $p['fileId']));
            $r->get('/present/[fileId]', fn (Request $req, array $p) => $this->presentPage($app, $req, (string) $p['fileId']));
            $r->get('/read/[fileId]', fn (Request $req, array $p) => $this->readPage($app, $req, (string) $p['fileId']));

            // Reading across a shelf rather than one book.
            $r->get('/api/hymnals/search', fn (Request $req) => $this->searchHymns($app, $req));
            $r->post('/api/hymns/lookup', fn (Request $req) => $this->lookupHymn($app, $req));

            // What the reader itself asks for.
            $r->get('/api/books/[fileId]', fn (Request $req, array $p) => $this->bookJson($app, $req, (string) $p['fileId']));
            // Searching inside one book, and a member's own marks and place
            // in it. These keep the addresses the original used — a mark is
            // named by its own id, not by the book it is in — because the
            // service worker and anything anybody bookmarked know them.
            $r->get('/api/files/[id]/search', fn (Request $req, array $p) => $this->searchInBook($app, $req, (string) $p['id']));
            $r->get('/api/reading/marks', fn (Request $req) => $this->marks($app, $req, $this->askedFile($req)), [$member]);
            $r->post('/api/reading/marks', fn (Request $req) => $this->addMark($app, $req, $this->askedFile($req)), [$member]);
            $r->add('PATCH', '/api/reading/marks/[id]', fn (Request $req, array $p) => $this->editMark($app, $req, (string) $p['id']), [$member]);
            $r->add('DELETE', '/api/reading/marks/[id]', fn (Request $req, array $p) => $this->deleteMark($app, (string) $p['id']), [$member]);
            $r->post('/api/reading/progress', fn (Request $req) => $this->saveProgress($app, $req, $this->askedFile($req)), [$member]);

            // Kept on the device.
            $r->get('/api/offline/hymnal/[seriesId]', fn (Request $req, array $p) => $this->offlineHymnal($app, $req, (string) $p['seriesId']));

            $r->get('/admin/books', fn () => $this->adminBooks($app), [$manage]);
            $r->get('/admin/books/[fileId]', fn (Request $req, array $p) => $this->adminBook($app, (string) $p['fileId']), [$manage]);

            // Indexing a book, from the admin's browser where pdf.js runs.
            $r->add('PUT', '/api/admin/files/[fileId]/contents', fn (Request $req, array $p) => $this->saveContents($app, $req, (string) $p['fileId']), [$manage]);
            $r->get('/api/admin/files/[fileId]/contents', fn (Request $req, array $p) => $this->readContents($app, (string) $p['fileId']), [$manage]);
            $r->add('PUT', '/api/admin/files/[fileId]/pages', fn (Request $req, array $p) => $this->savePages($app, $req, (string) $p['fileId']), [$manage]);
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


    // -- Reading across a shelf ---------------------------------------------

    /**
     * Every book this reader may open, as ids for a query.
     *
     * The access decision is made once, here, so no search below it has to
     * remember to make it — and a members-only hymnal never turns up in a
     * visitor's results.
     *
     * @return list<string>
     */
    private function readableFileIds(App $app, ?string $categoryId = null): array
    {
        $access = ContentAccess::for($app);
        [$where, $params] = $access->fileListSql('f', 's');
        $sql = 'SELECT f.id FROM {{file_assets}} f LEFT JOIN {{series}} s ON s.id = f.series_id WHERE ' . $where;
        if ($categoryId !== null) {
            $sql .= ' AND COALESCE(f.category_id, s.category_id) = ?';
            $params[] = $categoryId;
        }
        return array_map('strval', $app->db()->column($sql . ' LIMIT 2000', $params));
    }

    /** Hymns across every book on the shelf, by title, number or printed words. */
    private function searchHymns(App $app, Request $req): Response
    {
        $query = trim((string) ($req->query('q') ?? ''));
        $categoryId = ($req->query('categoryId') ?? '') !== '' && Id::isValid((string) $req->query('categoryId')) ? (string) $req->query('categoryId') : null;
        $files = $this->readableFileIds($app, $categoryId);
        $db = $app->db();
        $hymns = Books::search($db, $files, $query);
        return Response::json([
            'query' => $query,
            'hymns' => $hymns,
            // The words printed inside a scanned book, for a search the
            // contents list cannot answer.
            'inTheText' => count($hymns) >= Books::LIMIT ? [] : Books::searchText($db, $files, $query, Books::LIMIT - count($hymns)),
        ]);
    }

    /** One number, across the shelf: what the running-order builder asks. */
    private function lookupHymn(App $app, Request $req): Response
    {
        $data = Validator::check($req->input(), [
            'number' => ['int', 'nullable', 'min' => 1, 'max' => 99999],
            'fileId' => ['id', 'nullable'],
            'source' => ['string', 'nullable', 'max' => 16],
        ]);
        $number = isset($data['number']) ? (int) $data['number'] : null;
        $asked = isset($data['fileId']) ? (string) $data['fileId'] : null;
        $files = $this->readableFileIds($app);
        if ($asked !== null) {
            $files = array_values(array_intersect($files, [$asked]));
        }
        if ($number === null && $asked === null) {
            throw ApiError::invalid(t('books.lookupNeedsSomething'));
        }
        $this->countOpening($app, $files, $asked, $number, is_string($data['source'] ?? null) ? $data['source'] : null);
        return Response::json([
            'number' => $number,
            'hymns' => $number === null ? [] : Books::lookup($app->db(), $files, $number),
        ]);
    }

    /**
     * The marker the page carries for {@see self::countOpening}, as JSON for
     * a data attribute. Null number for a hymn that is its own file: the
     * file says which hymn it is.
     */
    private function openedMarker(string $fileId, ?int $number, string $source): string
    {
        return (string) json_encode(['fileId' => $fileId, 'number' => $number, 'source' => $source], JSON_UNESCAPED_SLASHES);
    }

    /**
     * That somebody really opened this hymn, for "what does this
     * congregation actually sing".
     *
     * Only when the caller says where from: the running-order builder asks
     * this endpoint the same question while somebody types, and counting
     * that would count typing rather than singing. Counted from the browser
     * for the same reason — hovering a link prefetches the page, so counting
     * a render would largely count mice. The cost is the other way round: a
     * blocked request means an opening goes uncounted, which is the right
     * way round for a number nothing depends on.
     *
     * @param list<string> $files the books this reader may open
     */
    private function countOpening(App $app, array $files, ?string $fileId, ?int $number, ?string $source): void
    {
        if ($source === null || !in_array($source, Books::OPENINGS, true) || $fileId === null) {
            return;
        }
        // Only a book this reader may open: the count is of real openings.
        if (!in_array($fileId, $files, true)) {
            return;
        }
        $app->db()->insert('hymn_lookups', [
            'file_id' => $fileId,
            'number' => $number,
            'source' => $source,
            'user_id' => $app->currentUser()->id(),
        ]);
    }

    // -- What the reader asks for --------------------------------------------

    /**
     * Everything the reader needs to open a book: where it is, what is in
     * it, and where this member had got to.
     */
    private function bookJson(App $app, Request $req, string $fileId): Response
    {
        return $this->guarded($app, $req, $fileId, function (array $file) use ($app): Response {
            $db = $app->db();
            $userId = $app->currentUser()->id();
            $progress = $userId === null ? null : $db->one('SELECT * FROM {{reading_progresses}} WHERE user_id = ? AND file_id = ?', [$userId, $file['id']]);
            return Response::json([
                'id' => (string) $file['id'],
                'title' => (string) $file['title'],
                'format' => Reader::format($file['mime_type'] ?? null, (string) ($file['storage_path'] ?? '')),
                'contentUrl' => url('/api/files/' . $file['id'] . '/content'),
                'pageOffset' => (int) $file['page_offset'],
                'sizeBytes' => $file['size_bytes'] === null ? null : (int) $file['size_bytes'],
                // A book small enough is fetched whole and cached; a very
                // large scan keeps streaming so its first page opens quickly.
                'fetchWhole' => Reader::shouldFetchWholeBook($file['size_bytes'] === null ? null : (int) $file['size_bytes']),
                'searchable' => $file['text_indexed_at'] !== null,
                'contents' => $this->contentsOf($db, (string) $file['id'], (int) $file['page_offset']),
                'progress' => $progress === null ? null : ['location' => (string) $progress['location'], 'percent' => (int) $progress['percent']],
            ]);
        });
    }

    /** @return list<array<string, mixed>> */
    private function contentsOf(Db $db, string $fileId, int $offset): array
    {
        return array_map(fn (array $h) => [
            'id' => (string) $h['id'],
            'title' => (string) $h['title'],
            'number' => $h['number'] === null ? null : (int) $h['number'],
            'page' => (int) $h['page'],
            'printedPage' => PageOffset::printedPage((int) $h['page'], $offset),
            'depth' => (int) $h['depth'],
        ], $db->all('SELECT * FROM {{book_hymns}} WHERE file_id = ? ORDER BY position, page', [$fileId]));
    }

    /**
     * In-book search, off the pages somebody has read into the database.
     *
     * A book nobody has read answers "not indexed" rather than "no results",
     * because the reader can then parse the open document itself — and the
     * two are not the same answer.
     */
    private function searchInBook(App $app, Request $req, string $fileId): Response
    {
        return $this->guarded($app, $req, $fileId, function (array $file) use ($app, $req): Response {
            $query = trim((string) ($req->query('q') ?? ''));
            if (mb_strlen($query) < 2) {
                return Response::json(['indexed' => $file['text_indexed_at'] !== null, 'query' => $query, 'hits' => []]);
            }
            $db = $app->db();
            $pages = $db->all(
                "SELECT page, text FROM {{book_pages}} WHERE file_id = ? AND text LIKE ? ESCAPE '\\\\' ORDER BY page LIMIT 200",
                [$file['id'], '%' . Db::likeEscape($query) . '%'],
            );
            $offset = (int) $file['page_offset'];
            $contents = $db->all('SELECT * FROM {{book_hymns}} WHERE file_id = ? ORDER BY page, position', [$file['id']]);
            $hits = [];
            foreach ($pages as $page) {
                $clean = trim((string) preg_replace('/\s+/u', ' ', (string) $page['text']));
                $at = TocNav::currentTocIndex($contents, (int) $page['page']);
                foreach (array_slice(Reader::findMatches($clean, $query), 0, 5) as $where) {
                    $hits[] = [
                        'page' => (int) $page['page'],
                        'printedPage' => PageOffset::printedPage((int) $page['page'], $offset),
                        'inside' => $at === null ? null : (string) $contents[$at]['title'],
                        'excerpt' => Reader::excerptAround($clean, $where, mb_strlen($query)),
                    ];
                }
            }
            return Response::json(['indexed' => $file['text_indexed_at'] !== null, 'query' => $query, 'hits' => array_slice($hits, 0, 100)]);
        });
    }

    // -- Where somebody is, and what they marked -------------------------------

    private function saveProgress(App $app, Request $req, string $fileId): Response
    {
        return $this->guarded($app, $req, $fileId, function (array $file) use ($app, $req): Response {
            $data = Validator::check($req->input(), [
                'location' => ['string', 'required', 'max' => 1000],
                'percent' => ['int'],
            ]);
            $db = $app->db();
            $userId = (string) $app->currentUser()->id();
            $row = [
                // Opaque on purpose: only the engine that wrote a location
                // knows whether it is a page number or a CFI.
                'location' => (string) $data['location'],
                'percent' => Reader::clampPercent($data['percent'] ?? 0),
            ];
            $existing = $db->one('SELECT id FROM {{reading_progresses}} WHERE user_id = ? AND file_id = ?', [$userId, $file['id']]);
            if ($existing === null) {
                try {
                    $db->insert('reading_progresses', $row + ['user_id' => $userId, 'file_id' => (string) $file['id']]);
                } catch (\Throwable $e) {
                    if (!Db::isDuplicate($e)) {
                        throw $e;
                    }
                    $db->update('reading_progresses', $row, ['user_id' => $userId, 'file_id' => (string) $file['id']]);
                }
            } else {
                $db->update('reading_progresses', $row, ['id' => $existing['id']]);
            }
            return Response::json(['ok' => true] + $row);
        });
    }

    /** @return list<array<string, mixed>> */
    private function marksOf(App $app, string $fileId): array
    {
        return array_map(fn (array $m) => [
            'id' => (string) $m['id'],
            'kind' => (string) $m['kind'],
            'location' => (string) $m['location'],
            'endLocation' => $m['end_location'],
            'excerpt' => $m['excerpt'],
            'note' => $m['note'],
            'color' => (string) $m['color'],
            'createdAt' => Json::instant((string) $m['created_at']),
        ], $app->db()->all(
            'SELECT * FROM {{reading_marks}} WHERE user_id = ? AND file_id = ? ORDER BY created_at LIMIT 500',
            [$app->currentUser()->id(), $fileId],
        ));
    }

    private function marks(App $app, Request $req, string $fileId): Response
    {
        // Marks are per member and private to them, so the file's own access
        // decision is the only gate that matters.
        return $this->guarded($app, $req, $fileId, fn (array $file) => Response::json(['marks' => $this->marksOf($app, (string) $file['id'])]));
    }

    private function addMark(App $app, Request $req, string $fileId): Response
    {
        return $this->guarded($app, $req, $fileId, function (array $file) use ($app, $req): Response {
            $data = Validator::check($req->input(), [
                'kind' => ['string', 'max' => 32],
                'location' => ['string', 'required', 'max' => 1000],
                'endLocation' => ['string', 'nullable', 'max' => 1000],
                'excerpt' => ['text', 'nullable', 'max' => 2000],
                'note' => ['text', 'nullable', 'max' => 5000],
                'color' => ['string', 'max' => 32],
            ]);
            $kind = in_array((string) ($data['kind'] ?? ''), ['HIGHLIGHT', 'BOOKMARK'], true) ? (string) $data['kind'] : 'BOOKMARK';
            // A highlight without an excerpt is a bookmark with extra steps:
            // in an EPUB the selection lives in a frame the reader cannot
            // read, so that is what it honestly saves.
            if ($kind === 'HIGHLIGHT' && trim((string) ($data['excerpt'] ?? '')) === '') {
                $kind = 'BOOKMARK';
            }
            $id = $app->db()->insert('reading_marks', [
                'user_id' => (string) $app->currentUser()->id(),
                'file_id' => (string) $file['id'],
                'kind' => $kind,
                'location' => (string) $data['location'],
                'end_location' => $data['endLocation'] ?? null,
                'excerpt' => $data['excerpt'] ?? null,
                'note' => $data['note'] ?? null,
                'color' => (string) ($data['color'] ?? 'yellow'),
            ]);
            return Response::json(['ok' => true, 'id' => $id, 'marks' => $this->marksOf($app, (string) $file['id'])], 201);
        });
    }

    /**
     * The book a request names, from the query for a read and the body for a
     * write. Whether the member may open it is still `guarded()`'s to say.
     */
    private function askedFile(Request $req): string
    {
        $fromQuery = $req->query('fileId');
        $fromBody = $req->input()['fileId'] ?? null;
        $id = is_string($fromQuery) && $fromQuery !== '' ? $fromQuery : $fromBody;
        if (!is_string($id) || !Id::isValid($id)) {
            throw ApiError::invalid('Which book? This needs a fileId.');
        }
        return $id;
    }

    /**
     * A mark this member owns, or nothing. The book it is in comes from the
     * mark rather than from the caller: a mark is named by its own id here,
     * and asking for a file id as well would only be a second thing to get
     * wrong.
     *
     * @return array<string, mixed>
     */
    private function ownMark(App $app, string $markId): array
    {
        $mark = $app->db()->one(
            'SELECT * FROM {{reading_marks}} WHERE id = ? AND user_id = ?',
            [Id::isValid($markId) ? $markId : '', (string) $app->currentUser()->id()],
        );
        if ($mark === null) {
            throw ApiError::notFound();
        }
        return $mark;
    }

    private function editMark(App $app, Request $req, string $markId): Response
    {
        $fileId = (string) $this->ownMark($app, $markId)['file_id'];
        $data = Validator::check($req->input(), ['note' => ['text', 'nullable', 'max' => 5000], 'color' => ['string', 'max' => 32]]);
        $set = [];
        foreach (['note' => 'note', 'color' => 'color'] as $in => $column) {
            if (array_key_exists($in, $data)) {
                $set[$column] = $data[$in];
            }
        }
        if ($set === []) {
            throw ApiError::invalid(t('books.nothingToChange'));
        }
        $done = $app->db()->update('reading_marks', $set, ['id' => Id::isValid($markId) ? $markId : '', 'user_id' => (string) $app->currentUser()->id(), 'file_id' => $fileId]);
        if ($done === 0) {
            throw ApiError::notFound();
        }
        return Response::json(['ok' => true, 'marks' => $this->marksOf($app, $fileId)]);
    }

    private function deleteMark(App $app, string $markId): Response
    {
        $fileId = (string) $this->ownMark($app, $markId)['file_id'];
        $app->db()->delete('reading_marks', ['id' => $markId, 'user_id' => (string) $app->currentUser()->id()]);
        return Response::json(['ok' => true, 'marks' => $this->marksOf($app, $fileId)]);
    }

    // -- Kept on the device -----------------------------------------------------

    /**
     * A hymn-per-file series as JSON, for a device with no connection.
     *
     * The same access checks as the page it mirrors — a members-only series
     * is not readable here just because the reader is a script. `?probe=1`
     * answers "is what I saved still what you would send" without sending it.
     */
    private function offlineHymnal(App $app, Request $req, string $seriesId): Response
    {
        $db = $app->db();
        $series = Id::isValid($seriesId) ? $db->one('SELECT * FROM {{series}} WHERE id = ? AND deleted_at IS NULL', [$seriesId]) : null;
        if ($series === null) {
            throw ApiError::notFound();
        }
        $access = ContentAccess::for($app);
        if ($access->series($series) !== ContentAccess::OK) {
            throw ApiError::forbidden(t('library.restricted'));
        }
        [$where, $params] = $access->fileListSql('f', 's');
        $files = $db->all(
            'SELECT f.* FROM {{file_assets}} f LEFT JOIN {{series}} s ON s.id = f.series_id WHERE ' . $where . ' AND f.series_id = ? ORDER BY f.position, f.title LIMIT 2000',
            [...$params, $series['id']],
        );
        $payload = Books::offlineHymnal($series, $files);
        if (($req->query('probe') ?? '') !== '') {
            // Just the token, so a device can ask whether to re-fetch.
            return Response::json(['id' => $payload['id'], 'fingerprint' => $payload['fingerprint'], 'hymns' => count($payload['hymns'])]);
        }
        return Response::json($payload);
    }


    // -- Whoever keeps the library ------------------------------------------

    /** Every file a reader could open, with what has been indexed on each. */
    private function adminBooks(App $app): Response
    {
        $rows = $app->db()->all(
            'SELECT f.*, s.title AS series_title,
                    (SELECT COUNT(*) FROM {{book_hymns}} h WHERE h.file_id = f.id) AS entries,
                    (SELECT COUNT(*) FROM {{book_pages}} p WHERE p.file_id = f.id) AS pages
             FROM {{file_assets}} f LEFT JOIN {{series}} s ON s.id = f.series_id
             WHERE f.deleted_at IS NULL ORDER BY f.title LIMIT 500',
        );
        $books = [];
        foreach ($rows as $file) {
            if (Reader::format($file['mime_type'] ?? null, (string) ($file['storage_path'] ?? '')) === null) {
                continue;
            }
            $books[] = [
                'id' => (string) $file['id'],
                'title' => (string) $file['title'],
                'seriesTitle' => $file['series_title'],
                'format' => Reader::format($file['mime_type'] ?? null, (string) ($file['storage_path'] ?? '')),
                'entries' => (int) $file['entries'],
                'pages' => (int) $file['pages'],
                'indexedAt' => Json::instant($file['contents_indexed_at']),
                'textIndexedAt' => Json::instant($file['text_indexed_at']),
            ];
        }
        return $app->page('book-reader/admin-books', [
            'title' => t('books.adminTitle'),
            'books' => $books,
        ], 200, 'layouts/admin');
    }

    /** One book: its contents, and the passes that fill them in. */
    private function adminBook(App $app, string $fileId): Response
    {
        $db = $app->db();
        $file = $this->findFile($db, $fileId);
        $entries = $db->all('SELECT * FROM {{book_hymns}} WHERE file_id = ? ORDER BY position, page', [$file['id']]);
        return $app->page('book-reader/admin-book', [
            'title' => (string) $file['title'],
            'file' => [
                'id' => (string) $file['id'],
                'title' => (string) $file['title'],
                'format' => Reader::format($file['mime_type'] ?? null, (string) ($file['storage_path'] ?? '')),
                'pageOffset' => (int) $file['page_offset'],
                'entries' => count($entries),
                'pages' => (int) $db->value('SELECT COUNT(*) FROM {{book_pages}} WHERE file_id = ?', [$file['id']]),
                'textIndexedAt' => Json::instant($file['text_indexed_at']),
            ],
            'contentsText' => BookContents::format($entries, (int) $file['page_offset']),
            'script' => $this->asset('book-admin.js'),
        ], 200, 'layouts/admin');
    }

    // -- Indexing a book ---------------------------------------------------------

    /** @return array<string, mixed> */
    private function findFile(Db $db, string $fileId): array
    {
        $file = Id::isValid($fileId) ? $db->one('SELECT * FROM {{file_assets}} WHERE id = ?', [$fileId]) : null;
        return $file ?? throw ApiError::notFound();
    }

    private function readContents(App $app, string $fileId): Response
    {
        $db = $app->db();
        $file = $this->findFile($db, $fileId);
        $entries = $db->all('SELECT * FROM {{book_hymns}} WHERE file_id = ? ORDER BY position, page', [$file['id']]);
        return Response::json([
            'pageOffset' => (int) $file['page_offset'],
            'indexedAt' => Json::instant($file['contents_indexed_at']),
            'entries' => $this->contentsOf($db, (string) $file['id'], (int) $file['page_offset']),
            // The same rows as the box the typist edits.
            'text' => BookContents::format($entries, (int) $file['page_offset']),
        ]);
    }

    /**
     * The contents of a book, from the browser that read its outline or from
     * the box somebody typed.
     *
     * An empty list is refused rather than stored: the outline pass runs on
     * every cover generation, and a PDF with no bookmarks would otherwise
     * wipe a contents list somebody typed by hand.
     */
    private function saveContents(App $app, Request $req, string $fileId): Response
    {
        $db = $app->db();
        $file = $this->findFile($db, $fileId);
        $input = $req->input();
        $offset = array_key_exists('pageOffset', $input) ? max(-5000, min(5000, (int) $input['pageOffset'])) : (int) $file['page_offset'];
        $problems = [];
        if (isset($input['text']) && is_string($input['text'])) {
            $read = BookContents::parse($input['text'], ['offset' => $offset]);
            $entries = $read['entries'];
            $problems = $read['problems'];
        } else {
            $entries = [];
            foreach ((array) ($input['entries'] ?? []) as $position => $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $title = trim((string) ($entry['title'] ?? ''));
                $page = (int) ($entry['page'] ?? 0);
                if ($title === '' || $page < 1) {
                    continue;
                }
                $entries[] = [
                    'title' => mb_substr($title, 0, 500),
                    // Parsed with the same rule the reader uses, so a number
                    // means the same thing on both sides.
                    'number' => TocNav::hymnNumberOf($title),
                    'page' => $page,
                    'depth' => max(0, min(5, (int) ($entry['depth'] ?? 0))),
                    'position' => (int) $position,
                ];
            }
        }
        if ($entries === []) {
            throw ApiError::invalid(t('books.emptyContents'));
        }
        $db->transaction(function () use ($db, $file, $entries, $offset): void {
            $db->delete('book_hymns', ['file_id' => $file['id']]);
            foreach ($entries as $entry) {
                $db->insert('book_hymns', [
                    'file_id' => (string) $file['id'],
                    'title' => $entry['title'],
                    'number' => $entry['number'],
                    'page' => $entry['page'],
                    'depth' => $entry['depth'],
                    'position' => $entry['position'],
                ]);
            }
            $db->update('file_assets', [
                'page_offset' => $offset,
                'hymn_count' => count(array_filter($entries, fn (array $e) => $e['number'] !== null)),
                'contents_indexed_at' => Db::now(),
            ], ['id' => $file['id']]);
        });
        Audit::log($db, (string) $app->currentUser()->email(), 'book.contents', 'FileAsset', (string) $file['id'], count($entries) . ' entries');
        return Response::json(['ok' => true, 'saved' => count($entries), 'problems' => $problems, 'entries' => $this->contentsOf($db, (string) $file['id'], $offset)]);
    }

    /**
     * A page of a book's text, from the admin's browser.
     *
     * Stored a page at a time so an hour-long run over a scanned hymnal is
     * resumable and interruptible; `textIndexedAt` is set only by a run that
     * says it reached the last page, because a half-read book that claimed
     * to be searchable would answer "no results" for everything after it.
     */
    private function savePages(App $app, Request $req, string $fileId): Response
    {
        $db = $app->db();
        $file = $this->findFile($db, $fileId);
        $input = $req->input();
        $written = 0;
        foreach ((array) ($input['pages'] ?? []) as $page) {
            if (!is_array($page)) {
                continue;
            }
            $number = (int) ($page['page'] ?? 0);
            $text = trim((string) ($page['text'] ?? ''));
            if ($number < 1 || $text === '') {
                continue;
            }
            $row = [
                'text' => mb_substr($text, 0, 200000),
                'source' => in_array((string) ($page['source'] ?? ''), ['TEXT', 'OCR'], true) ? (string) $page['source'] : 'TEXT',
            ];
            try {
                $db->insert('book_pages', $row + ['file_id' => (string) $file['id'], 'page' => $number]);
            } catch (\Throwable $e) {
                if (!Db::isDuplicate($e)) {
                    throw $e;
                }
                // Re-reading a page replaces it: an OCR pass improves on the
                // text layer's guess often enough to be worth allowing.
                $db->update('book_pages', $row, ['file_id' => (string) $file['id'], 'page' => $number]);
            }
            $written++;
        }
        if (!empty($input['finished'])) {
            $db->update('file_assets', ['text_indexed_at' => Db::now()], ['id' => $file['id']]);
        }
        return Response::json([
            'ok' => true,
            'saved' => $written,
            'pages' => (int) $db->value('SELECT COUNT(*) FROM {{book_pages}} WHERE file_id = ?', [$file['id']]),
            'finished' => !empty($input['finished']),
        ]);
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
            // Only a book opened *at a number* is an opened hymn; browsing
            // the contents is not somebody singing something.
            'opened' => $wanted === null ? '' : $this->openedMarker((string) $file['id'], $wanted, 'book'),
            'openedScript' => $this->asset('opened.js'),
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
            'opened' => $this->openedMarker((string) $file['id'], null, 'hymn'),
            'openedScript' => $this->asset('opened.js'),
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
            'opened' => $this->openedMarker((string) $file['id'], $number, 'present'),
            'openedScript' => $this->asset('opened.js'),
        ], 200, 'layouts/site');
        });
    }

    /** The file itself, meanwhile, through the app's own content route. */
    /**
     * The reader itself.
     *
     * The page is drawn server-side down to the contents list, and the
     * engine is loaded by the browser afterwards: a phone too old to run
     * pdf.js still gets a page with the book's contents on it and a link to
     * its own viewer, rather than a spinner that never stops.
     */
    private function readPage(App $app, Request $req, string $fileId): Response
    {
        return $this->guarded($app, $req, $fileId, function (array $file, ?array $series) use ($app, $req): Response {
            $format = Reader::format($file['mime_type'] ?? null, (string) ($file['storage_path'] ?? ''));
            if ($format === null) {
                // Nothing here can open it, so do not pretend to.
                return Response::redirect(url('/books/' . $file['id']));
            }
            $offset = (int) $file['page_offset'];
            $wanted = ($req->query('page') ?? '') !== '' ? max(1, (int) $req->query('page')) : null;
            $hymn = ($req->query('hymn') ?? '') !== '' ? (int) $req->query('hymn') : null;
            $contents = $this->contentsOf($app->db(), (string) $file['id'], $offset);
            if ($wanted === null && $hymn !== null) {
                $at = TocNav::findHymnIndex($contents, $hymn);
                $wanted = $at === null ? null : (int) $contents[$at]['page'];
            }
            return $app->page('book-reader/reader', [
                'title' => (string) $file['title'],
                'file' => [
                    'id' => (string) $file['id'],
                    'title' => (string) $file['title'],
                    'mime' => (string) ($file['mime_type'] ?? ''),
                    'format' => $format,
                    'pageOffset' => $offset,
                ],
                'contents' => $contents,
                'startPage' => $wanted,
                'signedIn' => $app->currentUser()->isSignedIn(),
                'script' => $this->asset('reader.js'),
            ]);
        });
    }
};

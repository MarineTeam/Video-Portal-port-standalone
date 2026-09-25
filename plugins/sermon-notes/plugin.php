<?php
/**
 * Plugin Name: Sermon notes
 * Slug:        sermon-notes
 * Version:     1.0.0
 * Description: Lets members keep their own timestamped notes on a video, exportable as a text file.
 * Author:      Marine Team
 * Requires PHP: 8.2
 * Requires App: 3.0
 * Category Override: yes
 */

declare(strict_types=1);

require_once __DIR__ . '/src/Outline.php';

use App\Core\ApiError;
use App\Core\App;
use App\Core\Hooks;
use App\Core\Id;
use App\Core\Json;
use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\Validator;
use App\Modules\Library\ContentTarget;
use App\Modules\Library\Viewer;
use App\Modules\Plugins\BasePlugin;
use App\Support\Timestamp;
use MarineTeam\Plugins\SermonNotes\Outline;

/**
 * Two things under a video, both a member's own:
 *
 *  - the note sheet — the admin's fill-in-the-blank outline (the video's
 *    note outline) as a page to fill in, saved as it is typed, with the
 *    sheet's fingerprint kept beside the answers so an edited sheet is
 *    reported instead of quietly misaligning them; a signed-out reader gets
 *    the sheet to read and print;
 *  - private timestamped notes ("12:03 — great point about grace"), the
 *    time prefilled from the player and edited freely.
 *
 * Both leave as one plain-text file: GET /api/notes?videoId=…&format=text.
 */
return new class (__DIR__) extends BasePlugin {
    public const MAX_NOTES = 500;
    public const MAX_ANSWER = 500;

    /** @var array<string, true> videos whose outline this plugin is showing */
    private array $sheets = [];

    public function boot(Hooks $hooks, App $app): void
    {
        $this->useLang($hooks);
        $this->useTemplates($hooks);
        $hooks->on('routes.register', function (Router $r) use ($app): void {
            $member = Middleware::member($app);
            $r->get('/api/notes', fn (Request $req) => $this->list($app, $req), [$member]);
            $r->post('/api/notes', fn (Request $req) => $this->create($app, $req), [$member]);
            $r->add('PATCH', '/api/notes/[id]', fn (Request $req, array $p) => $this->update($app, $req, $p['id']), [$member]);
            $r->add('DELETE', '/api/notes/[id]', function (Request $req, array $p) use ($app): Response {
                $app->db()->delete('sermon_notes', ['id' => $this->note($app, $p['id'])['id']]);
                return Response::json(['ok' => true]);
            }, [$member]);
            $r->add('PUT', '/api/videos/outline', fn (Request $req) => $this->saveAnswers($app, $req), [$member]);
        });
        $hooks->filter('page.video.panels', function (array $panels, array $ctx) use ($app): array {
            if (!($ctx['plugins']['sermon-notes'] ?? false) || $ctx['locked']) {
                return $panels;
            }
            $video = $ctx['video'];
            $viewer = $ctx['viewer'];
            $signedIn = $viewer instanceof Viewer && $viewer->signedIn();
            $outline = trim((string) ($video['note_outline'] ?? ''));
            if ($outline !== '') {
                $this->sheets[(string) $video['id']] = true;
                $saved = $signedIn ? $app->db()->one('SELECT answers, outline_version FROM {{sermon_outline_answers}} WHERE user_id = ? AND video_id = ?', [$viewer->id(), $video['id']]) : null;
                $answers = $saved !== null ? (array) json_decode((string) $saved['answers'], true) : [];
                $panels[] = ['area' => 'below', 'order' => 20, 'html' => $app->view()->partial('sermon-notes/sheet', [
                    'videoId' => (string) $video['id'],
                    'lines' => Outline::parse($outline),
                    'version' => Outline::fingerprint($outline),
                    'answers' => $saved !== null && $saved['outline_version'] === Outline::fingerprint($outline) ? $answers : [],
                    'stale' => $saved !== null && $saved['outline_version'] !== Outline::fingerprint($outline) ? $answers : null,
                    'signedIn' => $signedIn,
                    'script' => $this->asset('notes.js'),
                ])];
            }
            if ($signedIn) {
                $panels[] = ['area' => 'below', 'order' => 21, 'html' => $app->view()->partial('sermon-notes/notes', [
                    'videoId' => (string) $video['id'],
                    'notes' => $this->notes($app, (string) $viewer->id(), (string) $video['id']),
                    'script' => $this->asset('notes.js'),
                ])];
            }
            return $panels;
        });
        // The library shows the outline as plain text; while this plugin shows the sheet, it doesn't.
        $hooks->filter('template.library.video.vars', function (array $vars): array {
            if (isset($vars['video']['id'], $this->sheets[(string) $vars['video']['id']])) {
                $vars['video']['note_outline'] = null;
            }
            return $vars;
        });
    }

    /** @return list<array<string, mixed>> */
    private function notes(App $app, string $userId, string $videoId): array
    {
        return Json::rows('sermon_notes', $app->db()->all('SELECT id, timestamp_seconds, body, created_at, updated_at FROM {{sermon_notes}} WHERE user_id = ? AND video_id = ? ORDER BY timestamp_seconds, created_at', [$userId, $videoId]));
    }

    /** @return array<string, mixed> the member's own note */
    private function note(App $app, string $id): array
    {
        $row = Id::isValid($id) ? $app->db()->one('SELECT * FROM {{sermon_notes}} WHERE id = ? AND user_id = ?', [$id, $app->currentUser()->id()]) : null;
        return $row ?? throw ApiError::notFound();
    }

    /** @param array<string, mixed> $input */
    private static function seconds(array $input): ?int
    {
        if (isset($input['timestampSeconds']) && (is_int($input['timestampSeconds']) || ctype_digit((string) $input['timestampSeconds']))) {
            return min((int) $input['timestampSeconds'], 48 * 3600);
        }
        if (array_key_exists('timestamp', $input)) {
            $s = Timestamp::parse(is_scalar($input['timestamp']) ? (string) $input['timestamp'] : null);
            if ($s === null && trim((string) $input['timestamp']) !== '') {
                throw ApiError::invalid(t('sermonNotes.badTime'));
            }
            return $s ?? 0;
        }
        return null;
    }

    private function list(App $app, Request $req): Response
    {
        $target = ContentTarget::from($app, $req->query, ['video'], 'sermon-notes');
        $userId = (string) $app->currentUser()->id();
        $notes = $this->notes($app, $userId, $target->id);
        if ($req->query('format') !== 'text') {
            return Response::json($notes);
        }
        $lines = [(string) $target->row['title'], \App\Core\Url::absolute('/videos/' . $target->row['slug']), ''];
        $outline = trim((string) ($target->row['note_outline'] ?? ''));
        if ($outline !== '') {
            $saved = $app->db()->one('SELECT answers FROM {{sermon_outline_answers}} WHERE user_id = ? AND video_id = ?', [$userId, $target->id]);
            $lines[] = Outline::toText($outline, $saved !== null ? (array) json_decode((string) $saved['answers'], true) : []);
            $lines[] = '';
        }
        foreach ($notes as $n) {
            $lines[] = Timestamp::format((int) $n['timestampSeconds']) . ' — ' . $n['body'];
        }
        $name = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower((string) $target->row['slug'])), '-');
        $response = Response::text(implode("\n", $lines) . "\n");
        $response->header('Content-Disposition', 'attachment; filename="notes-' . ($name !== '' ? $name : 'video') . '.txt"');
        $response->header('Cache-Control', 'no-store');
        return $response;
    }

    private function create(App $app, Request $req): Response
    {
        $input = $req->input();
        $target = ContentTarget::from($app, $input, ['video'], 'sermon-notes');
        $data = Validator::check($input, ['body' => ['text', 'required', 'min' => 1, 'max' => 5000]]);
        $db = $app->db();
        $userId = (string) $app->currentUser()->id();
        if ((int) $db->value('SELECT COUNT(*) FROM {{sermon_notes}} WHERE user_id = ? AND video_id = ?', [$userId, $target->id]) >= self::MAX_NOTES) {
            throw ApiError::invalid(t('sermonNotes.tooMany'));
        }
        $id = $db->insert('sermon_notes', ['id' => Id::new(), 'user_id' => $userId, 'video_id' => $target->id, 'timestamp_seconds' => self::seconds($input) ?? 0, 'body' => trim((string) $data['body'])]);
        return Response::json(Json::row('sermon_notes', $this->note($app, $id), ['user_id']), 201);
    }

    private function update(App $app, Request $req, string $id): Response
    {
        $note = $this->note($app, $id);
        $input = $req->input();
        $data = Validator::check($input, ['body' => ['text', 'required', 'min' => 1, 'max' => 5000]], partial: true);
        $row = [];
        if (isset($data['body'])) {
            $row['body'] = trim((string) $data['body']);
        }
        $seconds = self::seconds($input);
        if ($seconds !== null) {
            $row['timestamp_seconds'] = $seconds;
        }
        if ($row !== []) {
            $app->db()->update('sermon_notes', $row, ['id' => $note['id']]);
        }
        return Response::json(Json::row('sermon_notes', $this->note($app, $id), ['user_id']));
    }

    private function saveAnswers(App $app, Request $req): Response
    {
        $input = $req->input();
        $target = ContentTarget::from($app, $input, ['video'], 'sermon-notes');
        $outline = trim((string) ($target->row['note_outline'] ?? ''));
        if ($outline === '') {
            throw ApiError::notFound();
        }
        $version = Outline::fingerprint($outline);
        if (($input['outlineVersion'] ?? null) !== $version) {
            throw new ApiError(t('sermonNotes.sheetChanged'), 409, 'outline_changed');
        }
        $gaps = Outline::gapCount($outline);
        $answers = [];
        foreach ((array) ($input['answers'] ?? []) as $gap => $text) {
            if (!is_numeric($gap) || (int) $gap < 0 || (int) $gap >= $gaps || !is_string($text)) {
                throw ApiError::invalid('answers must map gap numbers on this sheet to text.');
            }
            $text = mb_substr(trim($text), 0, self::MAX_ANSWER);
            if ($text !== '') {
                $answers[(string) (int) $gap] = $text;
            }
        }
        $app->db()->run(
            'INSERT INTO {{sermon_outline_answers}} (id, user_id, video_id, answers, outline_version) VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE answers = VALUES(answers), outline_version = VALUES(outline_version)',
            [Id::new(), $app->currentUser()->id(), $target->id, json_encode((object) $answers, JSON_UNESCAPED_UNICODE), $version],
        );
        return Response::json(['answers' => (object) $answers, 'outlineVersion' => $version]);
    }
};

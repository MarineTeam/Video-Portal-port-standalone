<?php

declare(strict_types=1);

namespace App\Modules\Library\Admin;

use App\Core\ApiError;
use App\Core\App;
use App\Core\Db;
use App\Core\Id;
use App\Core\Json;
use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\Validator;
use App\Modules\Audit\Audit;
use App\Modules\Branding\Branding;
use App\Support\Slug;

/** /admin/speakers: the preachers and presenters a video can name. */
final class SpeakersAdmin
{
    private const RULES = [
        'name' => ['string', 'required', 'max' => 255],
        'slug' => ['string', 'max' => 80, 'pattern' => '/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
        'bio' => ['text', 'nullable', 'max' => 10_000],
        'photoUrl' => ['string', 'nullable', 'max' => 2000],
    ];

    public function __construct(private readonly App $app)
    {
    }

    public static function register(Router $r, App $app): void
    {
        $self = new self($app);
        $can = Middleware::can($app, 'manage_videos', anywhere: true);
        $r->get('/admin/speakers', [$self, 'page'], [$can]);
        $r->get('/api/admin/speakers', [$self, 'list'], [Middleware::staff($app)]);
        $r->post('/api/admin/speakers', [$self, 'create'], [$can]);
        $r->add('PATCH', '/api/admin/speakers/[id]', [$self, 'update'], [$can]);
        $r->add('DELETE', '/api/admin/speakers/[id]', [$self, 'delete'], [$can]);
    }

    private function db(): Db
    {
        return $this->app->db();
    }

    /** @return list<array<string, mixed>> */
    private function all(): array
    {
        return array_map(fn ($r) => Json::row('speakers', $r) + ['videoCount' => (int) $r['video_count']], $this->db()->all(
            'SELECT s.*, (SELECT COUNT(*) FROM {{videos}} v WHERE v.speaker_id = s.id AND v.deleted_at IS NULL) AS video_count FROM {{speakers}} s ORDER BY s.position, s.name',
        ));
    }

    public function page(Request $req): Response
    {
        return $this->app->page('admin/speakers', ['title' => 'Speakers', 'speakers' => $this->all()], 200, 'layouts/admin');
    }

    public function list(Request $req): Response
    {
        return Response::json($this->all());
    }

    /** @param array<string, mixed> $data */
    private function checkPhoto(array $data): void
    {
        if (($data['photoUrl'] ?? null) !== null && !Branding::isAcceptableLogo((string) $data['photoUrl'])) {
            throw ApiError::invalid('The photo must be an https:// address or an image uploaded here.');
        }
    }

    private function slug(string $wanted, ?string $except = null): string
    {
        return Slug::unique($wanted, fn (string $s) => $this->db()->value('SELECT 1 FROM {{speakers}} WHERE slug = ?' . ($except ? ' AND id <> ?' : ''), $except ? [$s, $except] : [$s]) !== null, 'speaker');
    }

    public function create(Request $req): Response
    {
        $data = Validator::check($req->input(), self::RULES);
        $this->checkPhoto($data);
        $id = Id::new();
        $this->db()->insert('speakers', [
            'id' => $id,
            'name' => $data['name'],
            'slug' => $this->slug((string) ($data['slug'] ?? $data['name'])),
            'bio' => $data['bio'] ?? null,
            'photo_url' => $data['photoUrl'] ?? null,
            'position' => (int) $this->db()->value('SELECT COALESCE(MAX(position), -1) + 1 FROM {{speakers}}'),
        ]);
        Audit::log($this->db(), (string) $this->app->currentUser()->email(), 'speaker.create', 'Speaker', $id, (string) $data['name']);
        return Response::json(Json::row('speakers', (array) $this->db()->one('SELECT * FROM {{speakers}} WHERE id = ?', [$id])), 201);
    }

    /** @param array<string, string> $p */
    public function update(Request $req, array $p): Response
    {
        $speaker = Id::isValid($p['id']) ? $this->db()->one('SELECT * FROM {{speakers}} WHERE id = ?', [$p['id']]) : null;
        if ($speaker === null) {
            throw ApiError::notFound();
        }
        $data = Validator::check($req->input(), self::RULES, partial: true);
        $this->checkPhoto($data);
        $row = [];
        foreach (['name' => 'name', 'bio' => 'bio', 'photoUrl' => 'photo_url'] as $key => $column) {
            if (array_key_exists($key, $data)) {
                $row[$column] = $data[$key];
            }
        }
        if (isset($data['slug'])) {
            $row['slug'] = $this->slug((string) $data['slug'], (string) $speaker['id']);
        }
        if ($row !== []) {
            $this->db()->update('speakers', $row, ['id' => $speaker['id']]);
        }
        Audit::log($this->db(), (string) $this->app->currentUser()->email(), 'speaker.update', 'Speaker', (string) $speaker['id']);
        return Response::json(Json::row('speakers', (array) $this->db()->one('SELECT * FROM {{speakers}} WHERE id = ?', [$speaker['id']])));
    }

    /** @param array<string, string> $p */
    public function delete(Request $req, array $p): Response
    {
        $speaker = Id::isValid($p['id']) ? $this->db()->one('SELECT * FROM {{speakers}} WHERE id = ?', [$p['id']]) : null;
        if ($speaker === null) {
            throw ApiError::notFound();
        }
        // Videos keep playing; they just stop naming a speaker (ON DELETE SET NULL).
        $this->db()->delete('speakers', ['id' => $speaker['id']]);
        Audit::log($this->db(), (string) $this->app->currentUser()->email(), 'speaker.delete', 'Speaker', (string) $speaker['id'], (string) $speaker['name']);
        return Response::json(['ok' => true]);
    }
}

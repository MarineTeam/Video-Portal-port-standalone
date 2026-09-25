<?php

declare(strict_types=1);

namespace App\Modules\Themes;

use App\Core\ApiError;
use App\Core\App;
use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\Url;
use App\Modules\Audit\Audit;
use App\Modules\Files\Images;
use App\Modules\Plugins\PackageInstaller;
use App\Modules\Uploads\Uploads;

/**
 * /admin/appearance: the installed themes, which one is active, installing
 * one from a zip, deleting one, and the active theme's customizer.
 */
final class AdminRoutes
{
    public function __construct(private readonly App $app)
    {
    }

    public static function register(Router $r, App $app): void
    {
        $self = new self($app);
        $admin = Middleware::admin($app);
        $r->get('/admin/appearance', [$self, 'page'], [$admin]);
        $r->get('/api/admin/appearance', [$self, 'show'], [$admin]);
        $r->post('/api/admin/appearance/theme', [$self, 'activate'], [$admin]);
        $r->add('PUT', '/api/admin/appearance/customizer', [$self, 'saveCustomizer'], [$admin]);
        $r->add('DELETE', '/api/admin/appearance/customizer', [$self, 'resetCustomizer'], [$admin]);
        $r->post('/api/admin/appearance/customizer/image', [$self, 'uploadImage'], [$admin]);
        $r->post('/api/admin/appearance/install', [$self, 'install'], [$admin]);
        $r->add('DELETE', '/api/admin/appearance/themes/[slug]', [$self, 'delete'], [$admin]);
        $r->add('DELETE', '/api/admin/appearance/notice', [$self, 'dismissNotice'], [$admin]);
        $r->post('/admin/appearance/dismiss', function (Request $req) use ($app): Response {
            $app->settings()->delete('theme.notice');
            return Response::redirect(Url::safeReturnTo((string) ($req->input()['returnTo'] ?? ''), '/admin/appearance'));
        }, [$admin]);
    }

    private function actor(): string
    {
        return (string) $this->app->currentUser()->email();
    }

    /** @return array<string, mixed> */
    private function state(): array
    {
        $loader = $this->app->themes();
        $active = $loader->active()['slug'] ?? ThemeLoader::DEFAULT;
        $themes = [];
        $parents = $this->lineageOf($active);
        foreach ($loader->available() as $slug => $meta) {
            $shot = null;
            if (is_string($meta['screenshot']) && str_starts_with($meta['screenshot'], 'assets/') && \App\Modules\Site\Assets::resolve($this->app->paths->themes, $slug, substr($meta['screenshot'], 7)) !== null) {
                $shot = Url::to('/themes/' . $slug . '/' . $meta['screenshot']);
            }
            $themes[] = [
                'slug' => $slug,
                'name' => $meta['name'],
                'version' => $meta['version'],
                'author' => $meta['author'],
                'parent' => $meta['parent'],
                'screenshot' => $shot,
                'active' => $slug === $active,
                'deletable' => $slug !== ThemeLoader::DEFAULT && !in_array($slug, $parents, true) && !$this->isParentOfAny($slug),
                'hasFunctions' => is_file($meta['dir'] . '/functions.php'),
            ];
        }
        usort($themes, fn ($a, $b) => [!$a['active'], $a['name']] <=> [!$b['active'], $b['name']]);
        $schema = Appearance::activeSchema($this->app);
        return [
            'active' => $active,
            'themes' => $themes,
            'customizer' => array_values($schema),
            'values' => Appearance::activeValues($this->app),
        ];
    }

    /** @return list<string> the slug and its ancestors */
    private function lineageOf(string $slug): array
    {
        $available = $this->app->themes()->available();
        $out = [];
        while (isset($available[$slug]) && !in_array($slug, $out, true) && count($out) < 5) {
            $out[] = $slug;
            $slug = (string) ($available[$slug]['parent'] ?? '');
        }
        return $out;
    }

    private function isParentOfAny(string $slug): bool
    {
        foreach ($this->app->themes()->available() as $meta) {
            if ($meta['parent'] === $slug && $meta['slug'] !== $slug) {
                return true;
            }
        }
        return false;
    }

    public function page(Request $req): Response
    {
        return $this->app->page('admin/appearance', ['title' => 'Appearance', 'zip' => class_exists(\ZipArchive::class), 'gd' => extension_loaded('gd')] + $this->state(), 200, 'layouts/admin');
    }

    public function show(Request $req): Response
    {
        return Response::json($this->state());
    }

    public function activate(Request $req): Response
    {
        $slug = (string) ($req->input()['slug'] ?? '');
        $available = $this->app->themes()->available();
        if (!isset($available[$slug])) {
            throw ApiError::notFound('That theme isn’t installed.');
        }
        $parent = $available[$slug]['parent'];
        if ($parent !== null && !isset($available[$parent])) {
            throw ApiError::invalid("This theme builds on “{$parent}”, which isn’t installed. Install that theme first.");
        }
        $this->app->settings()->set('theme.active', $slug);
        $this->app->settings()->delete('theme.notice');
        Audit::log($this->app->db(), $this->actor(), 'theme.activate', 'Theme', $slug);
        return Response::json(['active' => $slug]);
    }

    public function saveCustomizer(Request $req): Response
    {
        $schema = Appearance::activeSchema($this->app);
        $input = $req->input();
        $slug = $this->app->themes()->active()['slug'] ?? ThemeLoader::DEFAULT;
        $stored = $this->app->settings()->get('theme.customizer.' . $slug, []);
        $stored = is_array($stored) ? $stored : [];
        foreach ($schema as $key => $setting) {
            if (!array_key_exists($key, $input)) {
                continue;
            }
            $raw = $input[$key];
            if ($raw === null || $raw === '') {
                unset($stored[$key]);
                continue;
            }
            $clean = Appearance::clean($setting, $raw);
            if ($clean === null) {
                throw ApiError::invalid("{$setting['label']} isn’t a valid value.");
            }
            $stored[$key] = $clean;
        }
        $this->app->settings()->set('theme.customizer.' . $slug, $stored);
        Audit::log($this->app->db(), $this->actor(), 'theme.customize', 'Theme', $slug);
        return Response::json(['values' => Appearance::values($schema, $stored)]);
    }

    public function resetCustomizer(Request $req): Response
    {
        $slug = $this->app->themes()->active()['slug'] ?? ThemeLoader::DEFAULT;
        $this->app->settings()->delete('theme.customizer.' . $slug);
        Audit::log($this->app->db(), $this->actor(), 'theme.customize.reset', 'Theme', $slug);
        return Response::json(['values' => Appearance::values(Appearance::activeSchema($this->app), [])]);
    }

    public function uploadImage(Request $req): Response
    {
        $input = $req->input();
        $key = (string) ($input['key'] ?? '');
        $schema = Appearance::activeSchema($this->app);
        if (($schema[$key]['type'] ?? null) !== 'image') {
            throw ApiError::invalid('That setting doesn’t take an image.');
        }
        $uploadId = (string) ($input['upload'] ?? '');
        $upload = Uploads::take($this->app, $uploadId, 'image');
        try {
            $name = Images::store($upload['path'], $upload['fileName'], $this->app->paths->storage('media/theme'));
        } finally {
            Uploads::discard($this->app, $uploadId);
        }
        $slug = $this->app->themes()->active()['slug'] ?? ThemeLoader::DEFAULT;
        $stored = $this->app->settings()->get('theme.customizer.' . $slug, []);
        $stored = is_array($stored) ? $stored : [];
        $stored[$key] = Url::to('/media/theme/' . $name);
        $this->app->settings()->set('theme.customizer.' . $slug, $stored);
        Audit::log($this->app->db(), $this->actor(), 'theme.customize', 'Theme', $slug);
        return Response::json(['values' => Appearance::values($schema, $stored)]);
    }

    public function install(Request $req): Response
    {
        $uploadId = (string) ($req->input()['upload'] ?? '');
        $upload = Uploads::take($this->app, $uploadId, 'theme');
        try {
            $slug = (new PackageInstaller($this->app))->install($upload['path'], 'theme');
        } catch (\RuntimeException $e) {
            throw ApiError::invalid('That theme was refused: ' . $e->getMessage());
        } finally {
            Uploads::discard($this->app, $uploadId);
        }
        Audit::log($this->app->db(), $this->actor(), 'theme.install', 'Theme', $slug);
        return Response::json(['slug' => $slug], 201);
    }

    /** @param array<string, string> $p */
    public function delete(Request $req, array $p): Response
    {
        $slug = $p['slug'];
        $available = $this->app->themes()->available();
        if (!isset($available[$slug])) {
            throw ApiError::notFound();
        }
        $active = $this->app->themes()->active()['slug'] ?? ThemeLoader::DEFAULT;
        if ($slug === ThemeLoader::DEFAULT) {
            throw ApiError::invalid('The default theme can’t be deleted: every other theme falls back to it.');
        }
        if (in_array($slug, $this->lineageOf($active), true)) {
            throw ApiError::invalid('The active theme (or one it builds on) can’t be deleted. Switch to another theme first.');
        }
        if ($this->isParentOfAny($slug)) {
            throw ApiError::invalid('Another installed theme builds on this one. Delete that theme first.');
        }
        $dir = (string) $available[$slug]['dir'];
        $real = realpath($dir);
        $root = realpath($this->app->paths->themes);
        if ($real === false || $root === false || dirname($real) !== $root) {
            throw ApiError::invalid('That theme folder can’t be removed from here.');
        }
        PackageInstaller::removeTree($real);
        if (is_dir($real)) {
            throw new \RuntimeException('The themes/ folder is not writable; remove the folder by FTP instead.');
        }
        $this->app->settings()->delete('theme.customizer.' . $slug);
        Audit::log($this->app->db(), $this->actor(), 'theme.delete', 'Theme', $slug);
        return Response::json(['ok' => true]);
    }

    public function dismissNotice(Request $req): Response
    {
        $this->app->settings()->delete('theme.notice');
        return Response::json(['ok' => true]);
    }
}

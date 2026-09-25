<?php

declare(strict_types=1);

namespace App\Modules\Update;

use App\Core\ApiError;
use App\Core\App;
use App\Core\Log;
use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Modules\Audit\Audit;
use App\Modules\Uploads\Uploads;

/**
 * /admin/update: finishing an update whose files are already in place, and
 * applying a signed release zip. The browser drives both a step per request
 * (public/assets/js/update.js), so no step has to fit more than one
 * request's time on a slow host.
 */
final class Routes
{
    public function __construct(private readonly App $app)
    {
    }

    public static function register(Router $r, App $app): void
    {
        $self = new self($app);
        $admin = Middleware::admin($app);
        $r->get('/admin/update', [$self, 'page'], [$admin]);
        $r->get('/api/admin/update', [$self, 'status'], [$admin]);
        $r->post('/api/admin/update/step', [$self, 'step'], [$admin]);
        $r->post('/api/admin/update/finish', [$self, 'finish'], [$admin]);
        $r->post('/api/admin/update/maintenance', [$self, 'maintenance'], [$admin]);
        $r->post('/api/admin/update/release', [$self, 'releaseUpload'], [$admin]);
        $r->post('/api/admin/update/release/[id]/extract', [$self, 'releaseExtract'], [$admin]);
        $r->post('/api/admin/update/release/[id]/swap', [$self, 'releaseSwap'], [$admin]);
        $r->post('/api/admin/update/release/[id]/rollback', [$self, 'releaseRollback'], [$admin]);
        $r->add('DELETE', '/api/admin/update/release/[id]', [$self, 'releaseDiscard'], [$admin]);
    }

    private function updater(): Updater
    {
        return new Updater($this->app);
    }

    private function release(): Release
    {
        return new Release($this->app);
    }

    private function actor(): string
    {
        return (string) $this->app->currentUser()->email();
    }

    /** @return array<string, mixed> */
    private function state(): array
    {
        $u = $this->updater();
        $pending = $u->pending();
        if ($u->dbVersion() === null && $pending === []) {
            // An install from before the version was recorded, fully migrated.
            $this->app->settings()->set(Updater::VERSION_SETTING, Updater::codeVersion());
        }
        $key = Release::publicKey($this->app->paths->app() . '/release-key.pub');
        return [
            'codeVersion' => Updater::codeVersion(),
            'dbVersion' => $u->dbVersion(),
            'behind' => Updater::behind($this->app->settings()),
            'pending' => $pending,
            'needed' => $u->needed(),
            'maintenance' => $u->maintenance(),
            'release' => $this->release()->current(),
            'canUploadRelease' => class_exists(\ZipArchive::class) && function_exists('sodium_crypto_sign_verify_detached') && $key !== null,
            'why' => match (true) {
                !class_exists(\ZipArchive::class) => 'This host has no zip extension.',
                !function_exists('sodium_crypto_sign_verify_detached') => 'This host’s PHP has no sodium extension, which checks a release’s signature.',
                $key === null => 'This copy of the site was built without a release key (app/release-key.pub).',
                default => null,
            },
        ];
    }

    public function page(Request $req): Response
    {
        return $this->app->page('admin/update', ['title' => 'Update'] + $this->state(), 200, 'layouts/admin');
    }

    public function status(Request $req): Response
    {
        return Response::json($this->state());
    }

    /** Applies the next pending migration (entering maintenance first). */
    public function step(Request $req): Response
    {
        $u = $this->updater();
        try {
            $result = $u->step();
        } catch (\Throwable $e) {
            Log::error('Update migration failed: ' . $e->getMessage());
            throw new ApiError('A migration failed: ' . mb_substr($e->getMessage(), 0, 500) . ' The site stays in maintenance mode; fix the cause (the log has the detail) and try again — the step resumes where it stopped.', 500, 'migration_failed');
        }
        return Response::json($result);
    }

    public function finish(Request $req): Response
    {
        $u = $this->updater();
        $from = $u->dbVersion();
        try {
            $u->finish();
        } catch (\RuntimeException $e) {
            throw ApiError::conflict($e->getMessage());
        }
        $this->release()->complete();
        Audit::log($this->app->db(), $this->actor(), 'site.update', 'Site', Updater::codeVersion(), 'from ' . ($from ?? 'unknown'));
        return Response::json(['version' => Updater::codeVersion()]);
    }

    public function maintenance(Request $req): Response
    {
        $on = $req->input()['on'] ?? null;
        if (!is_bool($on)) {
            throw ApiError::invalid('Say whether maintenance mode should be on.');
        }
        $u = $this->updater();
        if ($on) {
            $u->enterMaintenance('manual');
        } else {
            if (($this->release()->current()['stage'] ?? null) === 'swapped' || Updater::behind($this->app->settings())) {
                throw ApiError::conflict('An update is part way through. Finish it (or roll it back) before reopening the site.');
            }
            $u->leaveMaintenance();
        }
        Audit::log($this->app->db(), $this->actor(), $on ? 'site.maintenance.on' : 'site.maintenance.off', 'Site', 'maintenance');
        return Response::json(['maintenance' => $u->maintenance()]);
    }

    public function releaseUpload(Request $req): Response
    {
        $uploadId = (string) ($req->input()['upload'] ?? '');
        $upload = Uploads::take($this->app, $uploadId, 'release');
        try {
            $state = $this->release()->prepare($upload['path'], Release::publicKey($this->app->paths->app() . '/release-key.pub'));
        } catch (\RuntimeException $e) {
            throw ApiError::invalid($e->getMessage());
        } finally {
            Uploads::discard($this->app, $uploadId);
        }
        Audit::log($this->app->db(), $this->actor(), 'site.release.upload', 'Site', (string) $state['version']);
        return Response::json($state, 201);
    }

    /** @param array<string, string> $p */
    public function releaseExtract(Request $req, array $p): Response
    {
        try {
            return Response::json($this->release()->extract($p['id']));
        } catch (\RuntimeException | \InvalidArgumentException $e) {
            throw ApiError::invalid($e->getMessage());
        }
    }

    /** @param array<string, string> $p */
    public function releaseSwap(Request $req, array $p): Response
    {
        $u = $this->updater();
        $u->enterMaintenance('update');
        // Everything this request still needs after the swap is loaded now,
        // from the old files, so nothing is half old and half new.
        class_exists(Response::class);
        class_exists(ApiError::class);
        class_exists(Audit::class);
        try {
            $state = $this->release()->swap($p['id']);
        } catch (\RuntimeException | \InvalidArgumentException $e) {
            throw ApiError::invalid($e->getMessage());
        }
        Audit::log($this->app->db(), $this->actor(), 'site.release.swap', 'Site', (string) $state['version']);
        return Response::json($state);
    }

    /** @param array<string, string> $p */
    public function releaseRollback(Request $req, array $p): Response
    {
        try {
            $state = $this->release()->rollback($p['id']);
        } catch (\RuntimeException | \InvalidArgumentException $e) {
            throw ApiError::invalid($e->getMessage());
        }
        Audit::log($this->app->db(), $this->actor(), 'site.release.rollback', 'Site', (string) $state['version']);
        return Response::json($state);
    }

    /** @param array<string, string> $p */
    public function releaseDiscard(Request $req, array $p): Response
    {
        try {
            $this->release()->discard($p['id']);
        } catch (\RuntimeException | \InvalidArgumentException $e) {
            throw ApiError::invalid($e->getMessage());
        }
        return Response::json(['ok' => true]);
    }
}

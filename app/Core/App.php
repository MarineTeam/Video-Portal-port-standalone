<?php

declare(strict_types=1);

namespace App\Core;

use App\Modules\Access\CurrentUser;
use App\Modules\Plugins\PluginLoader;
use App\Modules\Themes\ThemeLoader;

/**
 * The one container: configuration, the database, hooks, the router, the
 * view, and the request lifecycle. Nothing else is global.
 */
final class App
{
    public const VERSION = '3.0.0-dev';

    /** @var array<string, mixed> */
    public array $config = [];

    public Hooks $hooks;
    public Router $router;

    private ?Db $db = null;
    private ?Settings $settings = null;
    private ?View $view = null;
    private ?Session $session = null;
    private ?CurrentUser $currentUser = null;
    private ?Request $request = null;
    private ?PluginLoader $plugins = null;
    private ?ThemeLoader $themes = null;

    /** @var array<string, object> services registered by modules and plugins */
    private array $services = [];

    /** @var array<string, list<string>> extra CSP sources contributed by active providers and plugins, by directive */
    private array $cspSources = ['script' => [], 'frame' => [], 'connect' => [], 'img' => [], 'media' => []];

    public function __construct(public readonly Paths $paths)
    {
        $this->hooks = new Hooks();
        $this->router = new Router();
        if (is_file($paths->config())) {
            $config = require $paths->config();
            $this->config = is_array($config) ? $config : [];
        }
    }

    public function isInstalled(): bool
    {
        return is_file($this->paths->installedLock()) && $this->config !== [];
    }

    public function debug(): bool
    {
        return (bool) ($this->config['debug'] ?? false);
    }

    public function db(): Db
    {
        if ($this->db === null) {
            $db = Db::connect($this->config['database'] ?? throw new \RuntimeException('Not installed.'));
            if ($this->config['query_monitor'] ?? false) {
                $db->enableLog();
            }
            $this->db = $db;
        }
        return $this->db;
    }

    public function hasDb(): bool
    {
        return $this->db !== null;
    }

    public function settings(): Settings
    {
        return $this->settings ??= new Settings($this->db());
    }

    public function view(): View
    {
        return $this->view ??= new View($this->paths->app() . '/Templates', $this->hooks);
    }

    public function request(): Request
    {
        return $this->request ?? throw new \LogicException('No request.');
    }

    public function session(): Session
    {
        if ($this->session === null) {
            $this->session = new Session($this->db(), $this->request());
            $this->session->start();
        }
        return $this->session;
    }

    public function currentUser(): CurrentUser
    {
        return $this->currentUser ??= new CurrentUser($this);
    }

    public function plugins(): PluginLoader
    {
        return $this->plugins ??= new PluginLoader($this);
    }

    public function themes(): ThemeLoader
    {
        return $this->themes ??= new ThemeLoader($this);
    }

    private ?\App\Services\Registry $registry = null;
    private ?\App\Services\Email\Mailer $mailer = null;
    private ?\App\Modules\Access\Permissions $permissions = null;

    public function services(): \App\Services\Registry
    {
        return $this->registry ??= new \App\Services\Registry($this->db(), $this->hooks);
    }

    public function mailer(): \App\Services\Email\Mailer
    {
        if ($this->mailer === null) {
            $from = $this->settings()->string('email.from');
            if ($from === '') {
                $host = (string) (parse_url(Url::baseUrl(), PHP_URL_HOST) ?: 'localhost');
                $name = \App\Modules\Branding\Branding::load($this->db())['name'];
                $from = str_replace(['"', '<', '>'], '', $name) . ' <no-reply@' . preg_replace('/^www\./', '', $host) . '>';
            }
            $this->mailer = new \App\Services\Email\Mailer($this->db(), $this->services(), $from);
        }
        return $this->mailer;
    }

    public function permissions(): \App\Modules\Access\Permissions
    {
        return $this->permissions ??= new \App\Modules\Access\Permissions($this->db());
    }

    public function set(string $id, object $service): void
    {
        $this->services[$id] = $service;
    }

    public function get(string $id): object
    {
        return $this->services[$id] ?? throw new \RuntimeException("No service $id");
    }

    public function has(string $id): bool
    {
        return isset($this->services[$id]);
    }

    public function addCspSource(string $directive, string $origin): void
    {
        if (isset($this->cspSources[$directive]) && preg_match('#^(https://[a-z0-9.*-]+(:\d+)?|\'self\'|blob:|data:)$#i', $origin)) {
            $this->cspSources[$directive][] = $origin;
        }
    }

    /**
     * Handles one request, whatever goes wrong: every Throwable becomes a
     * logged line and one plain answer.
     */
    public function handle(Request $request): Response
    {
        $this->request = $request;
        Url::configure($this->baseUrl($request));
        Log::configure($this->paths->storage('logs'));
        Log::withContext(['request' => $request->id]);
        Cache::configure($this->paths->storage('cache'));
        if (isset($this->config['app_key'])) {
            Crypto::configure((string) $this->config['app_key']);
        }
        ErrorHandler::install($this->debug(), $this->paths->plugins);

        try {
            $response = $this->route($request);
        } catch (\Throwable $e) {
            $response = ErrorHandler::respond($e, $request, $this->viewerIsAdmin());
        }

        try {
            if ($this->session !== null) {
                $this->session->commit($response);
            }
        } catch (\Throwable $e) {
            Log::error('Session commit failed: ' . $e->getMessage());
        }
        $this->securityHeaders($request, $response);
        return $response;
    }

    private function route(Request $request): Response
    {
        if (!$this->isInstalled()) {
            return (new \App\Install\Installer($this))->handle($request);
        }
        if (str_starts_with($request->path, '/install')) {
            return ErrorPage::render(404);
        }

        if (is_file($this->paths->maintenance()) && !$this->maintenanceBypass($request)) {
            return $request->wantsJson() ? Response::error('Down for maintenance', 503, 'maintenance') : ErrorPage::render(503);
        }

        \App\Modules\I18n\I18n::configure($this->paths->app() . '/Lang');
        \App\Modules\I18n\I18n::setLocale(\App\Modules\I18n\I18n::fromRequest($request->cookie(\App\Modules\I18n\I18n::COOKIE), $request->header('accept-language')));
        CsrfToken::resolveWith(fn () => Csrf::token($this->session()));
        \App\Services\Files\LocalDiskProvider::configureRoot($this->paths->storage('uploads'));
        \App\Services\Files\RangeStreamer::$offload = $this->config['file_offload'] ?? null;

        $this->plugins()->recoverFromCrashedLoad();

        // Modules register their routes; a page that must always be
        // reachable (the plugin list, the logs) never loads a third-party plugin.
        Modules::register($this);
        $safe = PluginLoader::isSafePath($request->path);
        $this->plugins()->boot($safe ? 'bundled-only' : (str_starts_with($request->path, '/auth/') ? 'auth-only' : 'all'));
        if (!$safe) {
            $this->themes()->boot();
        } else {
            $this->themes()->boot(defaultOnly: true);
        }
        $this->hooks->do('routes.register', $this->router, $this);
        $this->hooks->do('app.request', $request, $this);

        if (!Csrf::check($request, $this->session())) {
            Log::warning('CSRF check failed', ['route' => $request->path, 'site' => $request->header('sec-fetch-site')]);
            return $request->wantsJson()
                ? Response::error('This request could not be verified. Reload the page and try again.', 403, 'csrf')
                : ErrorPage::render(403);
        }

        $response = $this->router->dispatch($request);
        $this->hooks->do('app.shutdown', $request, $response, $this);
        return $response;
    }

    private function maintenanceBypass(Request $request): bool
    {
        // The admin performing the update keeps working; nobody else does.
        return str_starts_with($request->path, '/admin/update')
            || str_starts_with($request->path, '/auth/login')
            || ($this->currentUser()->user()['role'] ?? null) === 'ADMIN';
    }

    public function viewerIsAdmin(): bool
    {
        try {
            return $this->currentUser !== null && ($this->currentUser->user()['role'] ?? null) === 'ADMIN';
        } catch (\Throwable) {
            return false;
        }
    }

    private function baseUrl(Request $request): string
    {
        $configured = $this->config['base_url'] ?? null;
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }
        $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php');
        $base = rtrim(str_replace('\\', '/', dirname($script)), '/');
        $base = (string) preg_replace('#/public$#', '', $base);
        return ($request->https ? 'https' : 'http') . '://' . $request->host . $base;
    }

    private function securityHeaders(Request $request, Response $response): void
    {
        $response->header('X-Content-Type-Options', 'nosniff');
        $response->header('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->header('X-Frame-Options', 'SAMEORIGIN');
        $response->header('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=(), fullscreen=(self), autoplay=(self), picture-in-picture=(self), screen-wake-lock=(self), encrypted-media=(self)');
        $response->header('X-Request-Id', $request->id);
        if ($request->https && ($this->config['hsts'] ?? false)) {
            $response->header('Strict-Transport-Security', 'max-age=31536000');
        }
        if ($response->getHeader('Content-Security-Policy') === null) {
            $nonce = $this->view !== null ? $this->view->nonce() : null;
            $response->header('Content-Security-Policy', $this->csp($nonce));
        }
        if ($request->isApi() && $response->getHeader('Cache-Control') === null) {
            $response->header('Cache-Control', 'no-store');
        }
    }

    public function csp(?string $nonce): string
    {
        $join = fn (string $d, array $base) => implode(' ', array_unique([...$base, ...$this->cspSources[$d]]));
        $script = ["'self'"];
        if ($nonce !== null) {
            $script[] = "'nonce-$nonce'";
        }
        // Styles stay 'unsafe-inline': a nonce would disable it and with it
        // every style="" attribute, and CSS is not where the risk is.
        $style = ["'self'"];
        return implode('; ', [
            "default-src 'self'",
            'script-src ' . $join('script', $script) . " 'wasm-unsafe-eval'",
            'style-src ' . implode(' ', $style) . " 'unsafe-inline'",
            'img-src ' . $join('img', ["'self'", 'data:', 'blob:', 'https:']),
            'media-src ' . $join('media', ["'self'", 'blob:', 'https:']),
            'connect-src ' . $join('connect', ["'self'"]),
            'frame-src ' . $join('frame', ["'self'"]),
            'worker-src ' . "'self' blob:",
            "frame-ancestors 'self'",
            "base-uri 'self'",
            "form-action 'self' https:",
            "object-src 'none'",
        ]);
    }

    /** Renders a page inside a layout, with the shell every layout needs. */
    public function page(string $template, array $vars = [], int $status = 200, string $layout = 'layouts/site'): Response
    {
        $this->view()->share('shell', $this->shell());
        return Response::html($this->view()->page($template, $vars, $layout), $status);
    }

    /** @return array<string, mixed> */
    private function shell(): array
    {
        $current = $this->currentUser();
        $user = $current->user();
        $branding = \App\Modules\Branding\Branding::load($this->db());
        $isAdmin = $current->isAdmin();
        $nav = [
            ['href' => '/', 'label' => t('nav.home'), 'icon' => 'home'],
            ['href' => '/search', 'label' => t('nav.search'), 'icon' => 'search'],
        ];
        $nav = (array) $this->hooks->apply('nav.sections', $nav, $user);
        $tabs = (array) $this->hooks->apply('nav.tabs', array_slice($nav, 0, 4), $user);
        if ($user !== null) {
            $tabs[] = ['href' => '/profile', 'label' => t('nav.profile'), 'icon' => 'person'];
        }
        return [
            'branding' => $branding,
            'brandingCss' => \App\Modules\Branding\Branding::brandingCss($branding),
            'user' => $user === null ? null : [
                'id' => $user['id'],
                'name' => $current->displayName(),
                'email' => $user['email'],
                'isAdmin' => $isAdmin,
                'isStaff' => $current->isStaff(),
            ],
            'csrf' => Csrf::token($this->session()),
            'nonce' => $this->view()->nonce(),
            'locale' => \App\Modules\I18n\I18n::locale(),
            'nav' => $nav,
            'tabs' => $tabs,
            'theme' => $this->themes()->assets(),
            'path' => $this->request()->path,
            'notices' => $isAdmin ? $this->plugins()->notices() : [],
            'themeNotice' => $isAdmin ? $this->settings()->get('theme.notice') : null,
            'breakGlass' => $isAdmin && is_file($this->paths->storage('enable-local-login')),
            'adminNav' => $user !== null && $current->isStaff()
                ? \App\Modules\Admin\AdminNav::groupsFor($isAdmin, fn (string $c) => $current->canAnywhere($c))
                : [],
            'head' => $this->capture('render.head'),
            'bodyEnd' => $this->capture('render.body_end'),
        ];
    }

    /** Collects what plugins echo on a render hook. */
    private function capture(string $hook): string
    {
        ob_start();
        try {
            $this->hooks->do($hook, $this);
        } finally {
            $out = (string) ob_get_clean();
        }
        return $out;
    }
}

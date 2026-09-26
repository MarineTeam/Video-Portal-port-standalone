<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\App;
use App\Core\Csrf;
use App\Core\Middleware;
use App\Core\Migrator;
use App\Core\Modules;
use App\Core\Paths;
use App\Core\Router;

/**
 * The security walk, as a test rather than a table somebody has to keep.
 *
 * Every route the site registers is read back out of the router with the
 * middleware it was given, and the rules below are asserted over all of them
 * at once. A route added next year without a guard fails here, which a
 * document listing 226 routes would not do.
 *
 * Where a route is deliberately open, it is named in one of the lists in this
 * file with the reason. That is the point: an exception has to be written
 * down and read by somebody, rather than being the absence of a line.
 */
final class RouteAuditTest extends DatabaseTestCase
{
    private const PREFIX = 'audit_';

    /**
     * Routes whose check is inside the handler rather than in middleware,
     * and why it could not be middleware.
     *
     * @var array<string, string>
     */
    private const GUARDED_IN_HANDLER = [
        '/admin/trash' => 'needs any one of the four content capabilities, which Middleware::can cannot express; TrashAdmin::allowed() checks all four',
        '/api/admin/trash' => 'the same, and the list is filtered to the types the reader may see',
        '/api/admin/trash/[type]/[id]' => 'the same',
        '/api/watch-progress' => 'signed-in only; the handler answers 401 itself',
        '/api/watch-progress/mark-watched' => 'the same',
    ];

    /**
     * Writes that skip the CSRF token, and what authenticates them instead.
     * A cross-site page can send none of these.
     *
     * @var array<string, string>
     */
    private const CSRF_EXEMPT = [
        '/cron/run' => 'the cron secret in the address',
        '/api/auth/registration-check' => 'a bearer secret the identity provider holds',
        '/auth/callback' => 'the OAuth state, spent once (Apple posts cross-site)',
        '/api/tv/pair' => 'a device secret minted for this screen',
        '/api/tv/poll' => 'the same device secret',
        '/api/sms/status/[provider]' => 'the provider’s signature, or a secret in the address',
        '/api/sms/inbound/[provider]' => 'the provider’s signature, or a secret in the address',
    ];

    /** @return array{0: Router, 1: App} */
    private static function boot(): array
    {
        $name = getenv('MT_TEST_DB_NAME');
        if (!is_string($name) || $name === '') {
            self::markTestSkipped('MT_TEST_DB_NAME is not set.');
        }
        static $booted = null;
        if ($booted !== null) {
            return $booted;
        }
        $db = self::connect(self::PREFIX);
        self::dropPrefix($db, self::PREFIX);
        (new Migrator($db, dirname(__DIR__, 2) . '/app/Migrations'))->runAll();
        $root = dirname(__DIR__, 2);
        $app = new App(new Paths($root, sys_get_temp_dir() . '/mt-audit-' . bin2hex(random_bytes(4)), "$root/plugins", "$root/themes"));
        $app->config = ['database' => self::dbConfig(self::PREFIX)];
        // The plugin loader writes a crash marker naming the request, so
        // there has to be one even though nothing is dispatched here.
        (new \ReflectionProperty($app, 'request'))->setValue($app, new \App\Core\Request('GET', '/', id: 'route-audit'));
        Modules::register($app);
        // The bundled plugins too: two thirds of the routes are theirs, and
        // an audit that only saw the core would be the wrong two thirds.
        $app->plugins()->seed();
        $app->plugins()->boot();
        $app->hooks->do('routes.register', $app->router, $app);
        return $booted = [$app->router, $app];
    }

    /**
     * Every route, as [pattern, method, the names of its middleware].
     *
     * A middleware is a closure, so it is identified by the line of
     * Middleware.php that produced it — which is exact, and breaks loudly if
     * that file is rearranged rather than quietly passing everything.
     *
     * @return list<array{0: string, 1: string, 2: list<string>}>
     */
    private static function routes(): array
    {
        [$router, $app] = self::boot();
        $byLine = [];
        foreach (['member', 'staff', 'admin'] as $kind) {
            $byLine[(new \ReflectionFunction(Middleware::$kind($app)))->getStartLine()] = $kind;
        }
        $byLine[(new \ReflectionFunction(Middleware::can($app, 'x')))->getStartLine()] = 'can';
        $byLine[(new \ReflectionFunction(Middleware::throttle($app, 'x', 1, 1)))->getStartLine()] = 'throttle';

        $out = [];
        foreach ((new \ReflectionProperty($router, 'routes'))->getValue($router) as $pattern => $route) {
            foreach ($route['methods'] as $method => $entry) {
                $names = [];
                foreach ($entry['middleware'] as $one) {
                    $reflected = new \ReflectionFunction($one);
                    $line = $reflected->getStartLine();
                    $name = str_ends_with((string) $reflected->getFileName(), 'Middleware.php')
                        ? ($byLine[$line] ?? "middleware.php:$line")
                        : 'custom:' . basename((string) $reflected->getFileName()) . ":$line";
                    if ($name === 'can') {
                        $name .= '(' . ($reflected->getStaticVariables()['capability'] ?? '?') . ')';
                    }
                    $names[] = $name;
                }
                $out[] = [(string) $pattern, (string) $method, $names];
            }
        }
        return $out;
    }

    private static function guarded(array $names): bool
    {
        foreach ($names as $name) {
            if ($name === 'member' || $name === 'staff' || $name === 'admin' || str_starts_with($name, 'can(')) {
                return true;
            }
        }
        return false;
    }

    public function test_1_the_site_registers_the_routes_the_map_counts(): void
    {
        $routes = self::routes();
        self::assertGreaterThan(200, count($routes), 'the whole site booted, not a subset');
    }

    public function test_2_every_admin_page_and_endpoint_is_behind_a_capability(): void
    {
        $open = [];
        foreach (self::routes() as [$pattern, $method, $names]) {
            if (!str_starts_with($pattern, '/admin') && !str_starts_with($pattern, '/api/admin')) {
                continue;
            }
            if (!self::guarded($names) && !isset(self::GUARDED_IN_HANDLER[$pattern])) {
                $open[] = "$method $pattern";
            }
        }
        self::assertSame([], $open, 'these admin routes have no capability check');
    }

    public function test_3_nothing_that_changes_state_is_open_to_a_stranger_by_accident(): void
    {
        $open = [];
        foreach (self::routes() as [$pattern, $method, $names]) {
            if (in_array($method, ['GET', 'HEAD'], true)) {
                continue;
            }
            if (self::guarded($names)) {
                continue;
            }
            // What is left is a write anybody may make: it must be either
            // rate-limited, or one of the endpoints below whose whole purpose
            // is to be reachable signed-out.
            if (!in_array('throttle', $names, true)
                && !isset(self::publicWrites()[$pattern])
                && !isset(self::GUARDED_IN_HANDLER[$pattern])) {
                $open[] = "$method $pattern";
            }
        }
        self::assertSame([], $open, 'these writes are open to anybody and neither throttled nor listed');
    }

    public function test_4_a_write_exempt_from_the_csrf_token_is_one_we_listed(): void
    {
        $unlisted = [];
        foreach (self::routes() as [$pattern, $method]) {
            if (in_array($method, ['GET', 'HEAD'], true)) {
                continue;
            }
            if (Csrf::isExempt(self::asPath($pattern)) && !isset(self::CSRF_EXEMPT[$pattern])) {
                $unlisted[] = "$method $pattern";
            }
        }
        // /api/v1 is exempt too, and test 5 is why that costs nothing.
        self::assertSame([], $unlisted, 'a write skips the CSRF token and is not written down as doing so');
    }

    public function test_6_the_exemption_list_itself_has_a_reason_against_every_entry(): void
    {
        // The other direction: a prefix added to Csrf::EXEMPT that nobody
        // explained. The television's are here whether or not its plugin is
        // active in this boot, which is the point of checking the list rather
        // than the routes.
        $explained = ['/api/v1' => 'read-only, and authenticated by a bearer key'] + self::CSRF_EXEMPT;
        $prefixes = (new \ReflectionClassConstant(Csrf::class, 'EXEMPT'))->getValue();
        foreach ($prefixes as $prefix) {
            $known = false;
            foreach (array_keys($explained) as $pattern) {
                if (str_starts_with(self::asPath($pattern), rtrim((string) $prefix, '/'))) {
                    $known = true;
                }
            }
            self::assertTrue($known, "Csrf::EXEMPT holds $prefix, which this test does not explain");
        }
    }

    /** A pattern with its placeholders filled in, so Csrf::isExempt can judge it. */
    private static function asPath(string $pattern): string
    {
        return (string) preg_replace('/\[[a-z]+\]/i', 'x', $pattern);
    }

    public function test_5_the_read_api_is_read_only(): void
    {
        foreach (self::routes() as [$pattern, $method]) {
            if (str_starts_with($pattern, '/api/v1')) {
                self::assertContains($method, ['GET', 'HEAD'], "$method $pattern: v1 has no writes");
            }
        }
    }

    /**
     * Writes anybody may make, with what stands in for a sign-in.
     *
     * @return array<string, string>
     */
    private static function publicWrites(): array
    {
        return [
            '/api/locale' => 'sets the caller’s own language cookie; there is nothing here to take',
            '/auth/login' => 'signing in is the thing being asked for; the lockout is per address and per account',
            '/auth/logout' => 'ends your own session, and a session is all it can end',
            '/auth/register' => 'five an hour per address (Routes::registerSubmit)',
            '/auth/reset' => 'ten an hour per address and three per account',
            '/auth/reset/[token]' => 'the token is the credential, single use and short-lived',
            '/auth/magic' => 'ten an hour per address and three per account',
            '/auth/magic/[token]' => 'the token is the credential, single use and short-lived',
            '/auth/recover' => 'per address, and needs the code in storage/recovery.key',
            '/auth/callback' => 'the provider’s own flow; the OAuth state is spent once',
            '/auth/token' => 'the same, for the providers that post a token',
            '/auth/supabase/password' => 'Supabase verifies it, and the lockout above still applies',
            '/auth/supabase/magic' => 'the same',
            '/api/share-links/unlock' => 'thirty tries in fifteen minutes per address, plus the per-link lockout',
            '/api/view-events' => 'a thirty-minute cookie and an HMAC address throttle; it can only count',
            '/cron/run' => 'the cron secret in the address',
            '/api/auth/registration-check' => 'a bearer secret the identity provider holds',
            '/api/sms/status/[provider]' => 'the provider’s signature, or a secret in the address',
            '/api/sms/inbound/[provider]' => 'the provider’s signature, or a secret in the address',

            // The bundled plugins. Each of these is a thing a visitor is
            // meant to be able to do without an account.
            '/api/hymns/lookup' => 'a read that takes a body; the shelf it looks across is the one this caller may read',
            '/api/events/[slug]/register' => 'guests may put their name down; rate-limited per address when signed out',
            '/api/prayer' => 'anybody may ask for prayer; rate-limited, and nothing is shown until a moderator lets it through',
            '/api/prayer/[id]' => 'deleting your own, or a moderator’s; Prayer::canDelete decides and a stranger gets a 404',
            '/api/forms/[slug]' => 'a public form is public; rate-limited per address when signed out',
            '/api/tv/pair' => 'a screen asking for a code, rate-limited per address',
            '/api/tv/poll' => 'the same screen asking whether anybody approved it; claiming is a conditional update',
        ];
    }
}

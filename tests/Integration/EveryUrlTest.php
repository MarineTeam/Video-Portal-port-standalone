<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\App;
use App\Core\Id;
use App\Core\Modules;
use App\Core\Paths;
use App\Core\Request;

/**
 * Every address the site registers, asked for.
 *
 * Not what each one says — the other tests do that — but that none of them
 * falls over. A page nobody visits in a test is a page that breaks the week
 * somebody does, and on shared hosting the person who finds out is a
 * volunteer at eight on a Sunday morning.
 *
 * Two answers are acceptable from a route given an id that does not exist:
 * a 404, or a redirect to sign in. A 500 never is.
 */
final class EveryUrlTest extends ServerTestCase
{
    /** @var array<string, string> */
    private static array $ids = [];

    protected static function prefix(): string
    {
        return 'urls_';
    }

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::install();
        $db = self::connect(self::prefix());

        // A little of everything, so the pages that need a row have one.
        self::$ids['category'] = Id::new();
        $db->insert('categories', ['id' => self::$ids['category'], 'name' => 'Sermons', 'slug' => 'sermons', 'published' => 1, 'tags' => '[]']);
        self::$ids['series'] = Id::new();
        $db->insert('series', ['id' => self::$ids['series'], 'title' => 'Romans', 'slug' => 'romans', 'published' => 1, 'tags' => '[]', 'category_id' => self::$ids['category']]);
        self::$ids['video'] = Id::new();
        $db->insert('videos', [
            'id' => self::$ids['video'], 'title' => 'Romans 1', 'slug' => 'romans-1', 'provider' => 'direct',
            'series_id' => self::$ids['series'], 'published' => 1, 'status' => 'READY', 'scripture_refs' => '[]',
            'provider_data' => '{"url":"https://cdn.example.org/r1.mp4"}',
        ]);
        self::$ids['speaker'] = Id::new();
        $db->insert('speakers', ['id' => self::$ids['speaker'], 'name' => 'A preacher', 'slug' => 'a-preacher']);
        self::$ids['file'] = Id::new();
        $db->insert('file_assets', [
            'id' => self::$ids['file'], 'title' => 'An order of service', 'backend' => 'local',
            'storage_path' => 'files/order.pdf', 'mime_type' => 'application/pdf', 'published' => 1,
        ]);
        self::flushCache();
    }

    /**
     * Every GET the site registers, read out of the router with the plugins
     * on — two thirds of the addresses are theirs.
     *
     * @return list<string>
     */
    private static function patterns(): array
    {
        static $patterns = null;
        if ($patterns !== null) {
            return $patterns;
        }
        $root = dirname(__DIR__, 2);
        $app = new App(new Paths($root, self::$storage, "$root/plugins", "$root/themes"));
        $app->config = ['database' => self::dbConfig(self::prefix())];
        (new \ReflectionProperty($app, 'request'))->setValue($app, new Request('GET', '/', id: 'urls'));
        Modules::register($app);
        $app->plugins()->seed();
        $app->plugins()->boot();
        $app->hooks->do('routes.register', $app->router, $app);

        $out = [];
        foreach ((new \ReflectionProperty($app->router, 'routes'))->getValue($app->router) as $pattern => $route) {
            if (isset($route['methods']['GET'])) {
                $out[] = (string) $pattern;
            }
        }
        sort($out);
        return $patterns = $out;
    }

    /** A pattern with something plausible in place of each placeholder. */
    private static function fill(string $pattern): string
    {
        return (string) preg_replace_callback('/\[(\.\.\.)?([a-zA-Z]+)\]/', static function (array $m): string {
            return match (strtolower($m[2])) {
                'slug' => 'romans',
                'id', 'fileid', 'videoid', 'seriesid', 'categoryid', 'markid', 'planid', 'groupid', 'eventid' => Id::new(),
                'token' => str_repeat('a', 43),
                'provider' => 'twilio',
                'part' => '1',
                'path' => 'nothing.txt',
                'name', 'type', 'format', 'kind' => 'x',
                default => 'x',
            };
        }, $pattern);
    }

    /**
     * Addresses that are not pages: they stream a file, start a download or
     * hand off to somewhere else, and asking for one in a test proves
     * nothing but costs a timeout.
     */
    private const SKIP = [
        '/cron/run',
        '/api/admin/tools/backup/[id]/download',
        '/api/admin/tools/uploads/[part]',
    ];

    public function test_1_the_site_has_the_addresses_the_map_says_it_has(): void
    {
        // The core and the twenty-odd bundled plugins between them. The
        // number is here so that a module quietly failing to register is a
        // failing test rather than a quieter site.
        self::assertGreaterThan(240, count(self::patterns()), 'core and the bundled plugins are all registered');
    }

    public function test_2_no_address_answers_with_a_server_error_to_an_administrator(): void
    {
        $broken = [];
        foreach (self::patterns() as $pattern) {
            if (in_array($pattern, self::SKIP, true)) {
                continue;
            }
            $answer = self::http('GET', self::fill($pattern));
            // 502 and 504 are this site answering honestly about somebody
            // else's: an endpoint that asks Bunny something, with no Bunny
            // to ask. A 500 is ours.
            if ($answer['status'] >= 500 && !in_array($answer['status'], [502, 504], true)) {
                $broken[] = "$pattern → {$answer['status']}";
            }
        }
        self::assertSame([], $broken);
    }

    public function test_3_no_address_answers_with_a_server_error_to_a_stranger(): void
    {
        // The more interesting half: a signed-out caller reaches the guards
        // rather than the pages, and a guard that throws is a 500 on a page
        // anybody can find.
        $broken = [];
        foreach (self::patterns() as $pattern) {
            if (in_array($pattern, self::SKIP, true)) {
                continue;
            }
            $answer = self::http('GET', self::fill($pattern), null, 'guest');
            if ($answer['status'] >= 500 && !in_array($answer['status'], [502, 504], true)) {
                $broken[] = "$pattern → {$answer['status']}";
            }
        }
        self::assertSame([], $broken);
    }

    public function test_4_the_pages_with_real_rows_behind_them_come_back(): void
    {
        foreach ([
            '/' => 200,
            '/categories/sermons' => 200,
            '/series/romans' => 200,
            '/videos/romans-1' => 200,
            '/speakers/a-preacher' => 200,
            '/search?q=romans' => 200,
            '/sitemap.xml' => 200,
            '/feed.xml' => 200,
            '/api/v1' => 200,
            '/offline.html' => 200,
            '/sw.js' => 200,
            '/api/manifest' => 200,
        ] as $path => $expected) {
            self::assertSame($expected, self::http('GET', $path, null, 'guest')['status'], $path);
        }
    }

    public function test_5_a_thing_that_is_not_there_is_a_404_rather_than_a_crash(): void
    {
        foreach (['/series/nope', '/videos/nope', '/categories/nope', '/speakers/nope', '/read/' . Id::new()] as $path) {
            self::assertContains(self::http('GET', $path, null, 'guest')['status'], [301, 302, 303, 401, 403, 404], $path);
        }
    }
}

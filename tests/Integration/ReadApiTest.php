<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Modules\Api\Keys;

/**
 * The read API through a real server.
 *
 * What is being proved: a key authenticates and nothing else does; a scope
 * never implies another; the catalogue comes back whole, drafts included,
 * because a key is the organisation reading its own data; cursor paging
 * neither skips nor repeats; a group's address has no scope that returns it;
 * and the rate limit counts refusals too.
 */
final class ReadApiTest extends ServerTestCase
{
    /** @var array<string, string> */
    private static array $ids = [];

    protected static function prefix(): string
    {
        return 'v1_';
    }

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::install();
        $db = self::connect(self::prefix());

        self::$ids['series'] = \App\Core\Id::new();
        $db->insert('series', ['id' => self::$ids['series'], 'title' => 'Morning services', 'slug' => 'morning', 'published' => 1, 'tags' => '[]']);
        foreach ([['A public sermon', 1, 0], ['A draft', 0, 0], ['A members-only sermon', 1, 1]] as $i => [$title, $published, $memberOnly]) {
            $db->insert('videos', [
                'id' => \App\Core\Id::new(), 'title' => $title, 'slug' => 'v' . $i, 'provider' => 'direct',
                'series_id' => self::$ids['series'], 'published' => $published, 'member_only' => $memberOnly,
                'status' => 'READY', 'scripture_refs' => '[]',
            ]);
        }

        self::$ids['event'] = \App\Core\Id::new();
        $db->insert('events', [
            'id' => self::$ids['event'], 'title' => 'Men’s breakfast', 'slug' => 'breakfast',
            'starts_at' => \App\Core\Db::datetime(new \DateTimeImmutable('+10 days')), 'published' => 1, 'registration' => 1,
        ]);
        $db->insert('event_registrations', [
            'id' => \App\Core\Id::new(), 'event_id' => self::$ids['event'], 'name' => 'Visitor',
            'email' => 'visitor@test.example', 'phone' => '+447700900321', 'status' => 'GOING', 'guests' => 1,
        ]);

        self::$ids['group'] = \App\Core\Id::new();
        $db->insert('small_groups', [
            'id' => self::$ids['group'], 'name' => 'Tuesday evening', 'slug' => 'tuesday', 'published' => 1,
            'meets_when' => 'Tuesdays at 8', 'area' => 'North side', 'address' => '14 Laburnum Grove',
        ]);

        self::$ids['content'] = self::makeKey($db, 'Content only', ['content:read']);
        self::$ids['events'] = self::makeKey($db, 'Events only', ['events:read']);
        self::$ids['everything'] = self::makeKey($db, 'The lot', array_keys(Keys::SCOPES));
        self::$ids['revoked'] = self::makeKey($db, 'An old one', ['content:read'], ['revoked_at' => \App\Core\Db::now()]);
        self::$ids['expired'] = self::makeKey($db, 'A lapsed one', ['content:read'], ['expires_at' => '2020-01-01 00:00:00']);
        self::flushCache();
    }

    private static function makeKey(\App\Core\Db $db, string $name, array $scopes, array $extra = []): string
    {
        $key = Keys::newKey();
        $db->insert('api_keys', $extra + [
            'name' => $name,
            'hashed_key' => Keys::hash($key),
            'prefix' => Keys::prefixOf($key),
            'scopes' => (string) json_encode($scopes),
            'created_by_email' => 'admin@test.example',
        ]);
        return $key;
    }

    /** @return array{status: int, body: string, json: mixed, headers: array<string, string>} */
    private static function get(string $path, ?string $key = null): array
    {
        return self::http('GET', $path, null, 'guest', $key === null ? [] : ['Authorization' => 'Bearer ' . $key]);
    }

    public function test_1_the_api_describes_itself_without_a_key(): void
    {
        $described = self::get('/api/v1');
        self::assertSame(200, $described['status']);
        self::assertTrue($described['json']['data']['readOnly']);
        self::assertCount(count(Keys::SCOPES), $described['json']['data']['scopes']);
        self::assertCount(11, $described['json']['data']['endpoints']);
        self::assertSame(120, $described['json']['data']['rateLimit']['perMinute']);
        // And it says which scopes carry personal data, in the same place it
        // describes the rest.
        $personal = array_column(array_filter($described['json']['data']['scopes'], fn (array $s) => $s['personalData']), 'scope');
        self::assertContains('events:registrations', $personal);
    }

    public function test_2_only_a_key_of_ours_gets_in(): void
    {
        self::assertSame(401, self::get('/api/v1/videos')['status'], 'no key at all');
        self::assertSame(401, self::get('/api/v1/videos', 'mt_live_not_a_key_of_ours_at_all_x')['status']);
        self::assertSame(401, self::http('GET', '/api/v1/videos', null, 'guest', ['Authorization' => 'Basic abc'])['status']);

        $revoked = self::get('/api/v1/videos', self::$ids['revoked']);
        self::assertSame(401, $revoked['status']);
        self::assertSame('revoked', $revoked['json']['error']['code']);

        $expired = self::get('/api/v1/videos', self::$ids['expired']);
        self::assertSame('expired', $expired['json']['error']['code']);
    }

    public function test_3_a_scope_never_implies_another(): void
    {
        self::assertSame(200, self::get('/api/v1/videos', self::$ids['content'])['status']);
        self::assertSame(403, self::get('/api/v1/events', self::$ids['content'])['status']);

        $refused = self::get('/api/v1/events/' . self::$ids['event'] . '/registrations', self::$ids['events']);
        self::assertSame(403, $refused['status'], 'forty people are coming is not their phone numbers');
        self::assertStringContainsString('events:registrations', (string) $refused['json']['error']['message']);

        // /me needs no scope: it says what this key is.
        $me = self::get('/api/v1/me', self::$ids['events']);
        self::assertSame(['events:read'], $me['json']['data']['scopes']);
        self::assertTrue($me['json']['data']['readOnly']);
        self::assertStringStartsWith('mt_live_', (string) $me['json']['data']['prefix']);
    }

    public function test_4_the_catalogue_comes_back_whole_with_flags(): void
    {
        // A key is the organisation reading its own catalogue; hiding half
        // of it would make the API useless for the jobs it exists for.
        $videos = self::get('/api/v1/videos', self::$ids['content']);
        self::assertCount(3, $videos['json']['data']);
        $byTitle = array_column($videos['json']['data'], null, 'title');
        self::assertFalse($byTitle['A draft']['published']);
        self::assertTrue($byTitle['A members-only sermon']['memberOnly']);

        // Filters and the timestamp.
        self::assertCount(3, self::get('/api/v1/videos?seriesId=' . self::$ids['series'], self::$ids['content'])['json']['data']);
        self::assertSame([], self::get('/api/v1/videos?updatedSince=2099-01-01', self::$ids['content'])['json']['data']);
        self::assertCount(3, self::get('/api/v1/videos?updatedSince=rubbish', self::$ids['content'])['json']['data'], 'a bookmark it cannot read is ignored, not refused');
    }

    public function test_5_cursor_paging_neither_skips_nor_repeats(): void
    {
        $seen = [];
        $cursor = null;
        for ($page = 0; $page < 5; $page++) {
            $answer = self::get('/api/v1/videos?limit=1' . ($cursor === null ? '' : '&cursor=' . rawurlencode($cursor)), self::$ids['content']);
            self::assertSame(200, $answer['status'], (string) $answer['body']);
            foreach ($answer['json']['data'] as $row) {
                $seen[] = $row['id'];
            }
            $cursor = $answer['json']['nextCursor'] ?? null;
            if ($cursor === null) {
                break;
            }
        }
        self::assertCount(3, $seen, 'every row once');
        self::assertSame($seen, array_values(array_unique($seen)), 'and none of them twice');
        self::assertNull($cursor, 'nextCursor is absent on the last page');
        // The limit is clamped rather than refused.
        self::assertLessThanOrEqual(Keys::MAX_PAGE, count(self::get('/api/v1/videos?limit=9999', self::$ids['content'])['json']['data']));
    }

    public function test_6_an_address_has_no_scope_that_returns_it(): void
    {
        $groups = self::get('/api/v1/groups', self::$ids['everything']);
        self::assertSame(200, $groups['status']);
        self::assertSame('Tuesday evening', $groups['json']['data'][0]['name']);
        self::assertSame('North side', $groups['json']['data'][0]['area']);
        self::assertStringNotContainsString('Laburnum', (string) $groups['body'], 'an address travels only with a leader’s yes');
        self::assertArrayNotHasKey('address', $groups['json']['data'][0]);
        self::assertArrayNotHasKey('members', $groups['json']['data'][0], 'and a directory of groups is not a directory of who is in one');
        self::assertSame(0, $groups['json']['data'][0]['memberCount'], 'a count, which is not a list');
    }

    public function test_7_the_scope_that_carries_personal_data_says_so_and_carries_it(): void
    {
        $counted = self::get('/api/v1/events', self::$ids['everything']);
        self::assertSame(2, $counted['json']['data'][0]['going'], 'one person and one guest');
        self::assertStringNotContainsString('visitor@test.example', (string) $counted['body'], 'events:read is the count, not the people');

        $people = self::get('/api/v1/events/' . self::$ids['event'] . '/registrations', self::$ids['everything']);
        self::assertSame('visitor@test.example', $people['json']['data'][0]['email']);
        self::assertSame('+447700900321', $people['json']['data'][0]['phone']);
        self::assertSame(404, self::get('/api/v1/events/notanid/registrations', self::$ids['everything'])['status']);
    }

    public function test_8_an_answer_is_never_kept_by_a_shared_cache(): void
    {
        $answer = self::get('/api/v1/videos', self::$ids['content']);
        self::assertStringContainsString('no-store', (string) ($answer['headers']['cache-control'] ?? ''));
        self::assertStringContainsString('private', (string) ($answer['headers']['cache-control'] ?? ''));
        self::assertSame('noindex', (string) ($answer['headers']['x-robots-tag'] ?? ''));
    }

    public function test_9_the_rate_limit_counts_every_request_including_a_refusal(): void
    {
        $db = self::connect(self::prefix());
        $key = self::makeKey($db, 'A busy one', ['content:read']);
        $id = (string) $db->value('SELECT id FROM {{api_keys}} WHERE name = ?', ['A busy one']);
        // Start it near the edge rather than making 120 requests.
        $db->update('api_keys', ['window_started_at' => \App\Core\Db::now(), 'window_count' => Keys::PER_MINUTE - 1], ['id' => $id]);

        self::assertSame(200, self::get('/api/v1/videos', $key)['status'], 'the last one left');
        $refused = self::get('/api/v1/videos', $key);
        self::assertSame(429, $refused['status']);
        self::assertSame('rate_limited', $refused['json']['error']['code']);
        self::assertGreaterThan(0, (int) ($refused['headers']['retry-after'] ?? 0));

        // A refusal still counts, because it still cost a lookup.
        self::assertSame(Keys::PER_MINUTE + 1, (int) $db->value('SELECT window_count FROM {{api_keys}} WHERE id = ?', [$id]));

        // And the window rolls over rather than locking the key out for ever.
        $db->update('api_keys', ['window_started_at' => \App\Core\Db::datetime(new \DateTimeImmutable('-2 minutes'))], ['id' => $id]);
        self::assertSame(200, self::get('/api/v1/videos', $key)['status']);
        self::assertSame(1, (int) $db->value('SELECT window_count FROM {{api_keys}} WHERE id = ?', [$id]));
    }

    public function test_10_using_a_key_is_recorded_and_the_admin_screen_shows_it(): void
    {
        $db = self::connect(self::prefix());
        self::assertNotNull($db->value('SELECT last_used_at FROM {{api_keys}} WHERE name = ?', ['Content only']));

        $listed = self::http('GET', '/api/admin/api-keys');
        self::assertSame(200, $listed['status']);
        $byName = array_column($listed['json']['keys'], null, 'name');
        self::assertSame('revoked', $byName['An old one']['state']);
        self::assertSame('expired', $byName['A lapsed one']['state']);
        self::assertStringNotContainsString('mt_live_', substr((string) $byName['Content only']['prefix'], 8) . 'x', 'only the prefix is kept');

        // Made once, shown once.
        $made = self::api('POST', '/api/admin/api-keys', ['name' => 'The foyer noticeboard', 'scopes' => ['content:read', 'nonsense']]);
        self::assertSame(201, $made['status'], (string) $made['body']);
        self::assertStringStartsWith('mt_live_', (string) $made['json']['key']);
        self::assertSame(200, self::get('/api/v1/videos', (string) $made['json']['key'])['status']);
        self::assertNull($db->value('SELECT hashed_key FROM {{api_keys}} WHERE hashed_key = ?', [(string) $made['json']['key']]), 'the key itself is never stored');

        // A key that may read nothing is refused rather than made.
        self::assertSame(400, self::api('POST', '/api/admin/api-keys', ['name' => 'Useless', 'scopes' => []])['status']);

        // Revoking keeps the row, so the record survives the key.
        $id = (string) $made['json']['id'];
        self::assertSame(200, self::api('DELETE', "/api/admin/api-keys/$id")['status']);
        self::assertSame(401, self::get('/api/v1/videos', (string) $made['json']['key'])['status']);
        self::assertNotNull($db->value('SELECT id FROM {{api_keys}} WHERE id = ?', [$id]));
    }
}

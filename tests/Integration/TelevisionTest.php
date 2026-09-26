<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * The television through a real server.
 *
 * The three things being proved are the ones the design is arranged around:
 * the code on the screen only ever *names* a request and the device's own
 * secret is what redeems it; one approval mints exactly one token, however
 * many polls arrive together; and only public content reaches a feed, which
 * is fetched with no session at all.
 */
final class TelevisionTest extends ServerTestCase
{
    /** @var array<string, mixed> */
    private static array $ids = [];

    protected static function prefix(): string
    {
        return 'tv_';
    }

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::install();
        self::$ids['ruth'] = self::member('ruth@test.example', 'ruth');
        self::$ids['sam'] = self::member('sam@test.example', 'sam');
        self::flushCache();
    }

    private static function pair(string $who = 'television'): array
    {
        return self::api('POST', '/api/tv/pair', ['deviceName' => 'Living room TV', 'deviceKind' => 'tv'], $who);
    }

    public function test_1_a_television_asks_for_a_code(): void
    {
        $started = self::pair();
        self::assertSame(201, $started['status'], (string) $started['body']);
        self::$ids['deviceCode'] = (string) $started['json']['deviceCode'];
        self::$ids['userCode'] = (string) $started['json']['userCode'];

        self::assertMatchesRegularExpression('/^[BCFGHJKLMNPRSTVWXZ]{6}$/', self::$ids['userCode'], 'from the alphabet with no lookalikes');
        self::assertSame(substr(self::$ids['userCode'], 0, 3) . '-' . substr(self::$ids['userCode'], 3), $started['json']['formattedUserCode']);
        self::assertNotSame(self::$ids['userCode'], self::$ids['deviceCode'], 'two different secrets');
        self::assertGreaterThan(30, strlen(self::$ids['deviceCode']), 'and the one that redeems is the long one');
        self::assertStringEndsWith('/link', (string) $started['json']['verificationUri']);

        // Neither secret is stored as it was given.
        $db = self::connect(self::prefix());
        $row = $db->one('SELECT * FROM {{tv_devices}} WHERE user_code = ?', [self::$ids['userCode']]);
        self::assertSame(hash('sha256', self::$ids['deviceCode']), $row['device_code_hash']);
        self::assertNull($row['token_hash']);
        self::assertSame('PENDING', $row['status']);

        $waiting = self::api('POST', '/api/tv/poll', ['deviceCode' => self::$ids['deviceCode']], 'television');
        self::assertSame('WAIT', $waiting['json']['status']);
        self::assertSame(5, $waiting['json']['interval']);
    }

    public function test_2_the_code_on_the_screen_only_names_a_request(): void
    {
        // A visitor cannot even ask what a code is: approving is a member's act.
        self::assertContains(self::http('POST', '/api/tv/lookup', null, 'guest')['status'], [401, 403]);

        $found = self::api('POST', '/api/tv/lookup', ['code' => strtolower(self::$ids['userCode'])], 'ruth');
        self::assertSame(200, $found['status'], (string) $found['body']);
        self::assertSame('Living room TV', $found['json']['deviceName']);
        self::assertStringContainsString('Living room TV', (string) $found['json']['prompt']);

        // A lookalike typed for a character the screen never showed still works.
        $typed = strtr(self::$ids['userCode'], ['L' => '1', 'S' => '5', 'B' => '8', 'G' => '6', 'Z' => '2', 'V' => 'U']);
        self::assertSame(200, self::api('POST', '/api/tv/lookup', ['code' => $typed], 'ruth')['status']);

        self::assertSame(404, self::api('POST', '/api/tv/lookup', ['code' => 'BBBBBB'], 'ruth')['status'], 'and a code nobody showed is not found');
        self::assertSame(400, self::api('POST', '/api/tv/lookup', ['code' => 'nope'], 'ruth')['status']);

        // Knowing the code on the screen is not enough to sign a television
        // in: only the secret that never left the set can redeem it, and to
        // the poll the user code is simply not a device code.
        self::assertSame('GONE', self::api('POST', '/api/tv/poll', ['deviceCode' => self::$ids['userCode']], 'television')['json']['status']);
    }

    public function test_3_one_approval_mints_exactly_one_token(): void
    {
        $said = self::api('POST', '/api/tv/approve', ['code' => self::$ids['userCode'], 'approve' => true], 'ruth');
        self::assertSame(200, $said['status'], (string) $said['body']);
        self::assertTrue($said['json']['approved']);

        // Two polls in the same second: one winner, and no second token.
        $answers = [];
        foreach (self::polls(self::$ids['deviceCode'], 4) as $answer) {
            $answers[] = $answer['status'] ?? '';
        }
        self::assertSame(1, count(array_filter($answers, fn (string $s) => $s === 'READY')), 'exactly one READY: ' . implode(',', $answers));
        foreach ($answers as $status) {
            self::assertContains($status, ['READY', 'GONE']);
        }

        $db = self::connect(self::prefix());
        $row = $db->one('SELECT * FROM {{tv_devices}} WHERE user_code = ?', [self::$ids['userCode']]);
        self::assertSame('LINKED', $row['status']);
        self::assertSame(self::$ids['ruth'], $row['user_id']);
        self::assertNotNull($row['token_hash']);
    }

    /**
     * Several polls at once, the way two timers firing together would.
     *
     * @return list<array<string, mixed>>
     */
    private static function polls(string $deviceCode, int $howMany): array
    {
        $multi = curl_multi_init();
        $handles = [];
        for ($i = 0; $i < $howMany; $i++) {
            $ch = curl_init(self::$base . '/api/tv/poll');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => (string) json_encode(['deviceCode' => $deviceCode]),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
                CURLOPT_TIMEOUT => 30,
            ]);
            curl_multi_add_handle($multi, $ch);
            $handles[] = $ch;
        }
        $running = null;
        do {
            curl_multi_exec($multi, $running);
            curl_multi_select($multi, 0.2);
        } while ($running > 0);
        $out = [];
        foreach ($handles as $ch) {
            $out[] = (array) json_decode((string) curl_multi_getcontent($ch), true);
            curl_multi_remove_handle($multi, $ch);
            curl_close($ch);
        }
        curl_multi_close($multi);
        return $out;
    }

    public function test_4_a_code_that_has_been_used_cannot_be_approved_again(): void
    {
        self::assertSame(400, self::api('POST', '/api/tv/approve', ['code' => self::$ids['userCode'], 'approve' => true], 'sam')['status']);
        self::assertSame(400, self::api('POST', '/api/tv/lookup', ['code' => self::$ids['userCode']], 'ruth')['status']);
    }

    public function test_5_an_approval_nobody_collected_expires(): void
    {
        $started = self::pair('television2');
        $db = self::connect(self::prefix());
        $code = (string) $started['json']['userCode'];
        self::api('POST', '/api/tv/approve', ['code' => $code, 'approve' => true], 'ruth');
        // Somebody approved it and walked away.
        $db->update('tv_devices', ['expires_at' => \App\Core\Db::datetime(new \DateTimeImmutable('-1 minute'))], ['user_code' => $code]);
        $answer = self::api('POST', '/api/tv/poll', ['deviceCode' => (string) $started['json']['deviceCode']], 'television2');
        self::assertSame('EXPIRED', $answer['json']['status'], 'rather than staying redeemable');
        self::assertNull($db->value('SELECT token_hash FROM {{tv_devices}} WHERE user_code = ?', [$code]));
    }

    public function test_6_a_refusal_is_said_rather_than_timing_out(): void
    {
        $started = self::pair('television3');
        self::api('POST', '/api/tv/approve', ['code' => (string) $started['json']['userCode'], 'approve' => false], 'ruth');
        $answer = self::api('POST', '/api/tv/poll', ['deviceCode' => (string) $started['json']['deviceCode']], 'television3');
        self::assertSame('REFUSED', $answer['json']['status']);
    }

    public function test_7_the_screen_and_signing_one_out(): void
    {
        // A television signed in the ordinary way, one poll at a time: the
        // token comes back as a cookie the set then holds.
        $started = self::pair('lounge');
        self::api('POST', '/api/tv/approve', ['code' => (string) $started['json']['userCode'], 'approve' => true], 'ruth');
        $ready = self::api('POST', '/api/tv/poll', ['deviceCode' => (string) $started['json']['deviceCode']], 'lounge');
        self::assertSame('READY', $ready['json']['status']);

        // The television is signed in: its own screen says so, without
        // putting an address on a screen in a room anybody can walk into.
        $screen = self::http('GET', '/tv', null, 'lounge');
        self::assertSame(200, $screen['status']);
        self::assertStringContainsString('Signed in as', $screen['body']);
        self::assertStringNotContainsString('ruth@test.example', $screen['body']);
        self::assertStringContainsString('<meta name="robots" content="noindex">', $screen['body']);
        self::assertStringNotContainsString('<nav', $screen['body'], 'a remote cannot use a sidebar');

        // A set nobody has paired sees a code instead.
        $cold = self::http('GET', '/tv', null, 'stranger');
        self::assertStringContainsString('data-tv-pair', $cold['body']);

        $mine = self::http('GET', '/api/profile/devices', null, 'ruth');
        self::assertCount(2, $mine['json']['devices'], 'the lounge set and the one paired concurrently');
        self::assertSame('Living room TV', $mine['json']['devices'][0]['name']);
        self::assertSame([], self::http('GET', '/api/profile/devices', null, 'sam')['json']['devices'], 'and not somebody else’s');

        $id = (string) $mine['json']['devices'][0]['id'];
        self::assertSame(404, self::api('DELETE', "/api/profile/devices/$id", null, 'sam')['status']);
        self::assertSame(200, self::api('DELETE', "/api/profile/devices/$id", null, 'ruth')['status']);

        // Signed out at once, not whenever a session happens to lapse.
        $after = self::http('GET', '/tv', null, 'lounge');
        self::assertStringContainsString('data-tv-pair', $after['body']);
        self::assertStringNotContainsString('Signed in as', $after['body']);
    }

    public function test_8_only_public_content_reaches_a_feed(): void
    {
        $db = self::connect(self::prefix());
        $series = \App\Core\Id::new();
        $db->insert('series', ['id' => $series, 'title' => 'Members only series', 'slug' => 'members-only-series', 'published' => 1, 'member_only' => 1, 'tags' => '[]']);
        $open = \App\Core\Id::new();
        $db->insert('series', ['id' => $open, 'title' => 'Morning services', 'slug' => 'morning-services', 'published' => 1, 'member_only' => 0, 'tags' => '[]']);
        $make = static function (array $extra) use ($db): string {
            $id = \App\Core\Id::new();
            $db->insert('videos', $extra + [
                'id' => $id,
                'title' => 'A sermon',
                'slug' => 'a-sermon-' . substr($id, -6),
                'description' => 'Matthew 5.',
                'provider' => 'direct',
                'provider_data' => (string) json_encode(['url' => 'https://files.test.example/a.mp4', 'type' => 'video/mp4']),
                'duration_seconds' => 1800,
                'status' => 'READY',
                'published' => 1,
                'scripture_refs' => '[]',
            ]);
            return $id;
        };
        $public = $make(['title' => 'Public sermon', 'series_id' => $open]);
        $make(['title' => 'Members sermon', 'member_only' => 1]);
        $make(['title' => 'Public in a closed series', 'series_id' => $series]);
        $make(['title' => 'No duration', 'duration_seconds' => null]);
        self::flushCache();

        $json = self::http('GET', '/api/tv/feed.json', null, 'guest');
        self::assertSame(200, $json['status']);
        $titles = array_column($json['json']['shortFormVideos'], 'title');
        self::assertContains('Public sermon', $titles);
        self::assertNotContains('Members sermon', $titles);
        self::assertNotContains('Public in a closed series', $titles, 'a public video inside a members-only series is members-only');
        self::assertNotContains('No duration', $titles);
        self::assertStringNotContainsString('Members sermon', $json['body']);

        $item = $json['json']['shortFormVideos'][array_search('Public sermon', $titles, true)];
        self::assertSame('https://files.test.example/a.mp4', $item['content']['videos'][0]['url']);
        self::assertSame(1800, $item['content']['duration']);
        self::assertSame(['Morning services'], $item['tags']);
        self::assertStringContainsString('max-age=3600', (string) ($json['headers']['cache-control'] ?? ''));

        $xml = self::http('GET', '/api/tv/feed.xml', null, 'guest');
        self::assertStringContainsString('Public sermon', $xml['body']);
        self::assertStringNotContainsString('Members sermon', $xml['body']);
        self::assertNotFalse(simplexml_load_string($xml['body']), 'and it parses');
        self::assertSame((string) $public, (string) $public);
    }
}

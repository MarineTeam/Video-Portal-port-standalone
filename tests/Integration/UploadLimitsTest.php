<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Db;

/**
 * What a chunked upload is allowed to be.
 *
 * Every large file arrives this way — a hymnal scan, a release zip, an
 * export — so the upload area is the one place a stranger with an account
 * can put bytes on the host's disk. The cap, the sweep and the refusal to
 * take anything the purpose does not allow are what keep that bounded.
 */
final class UploadLimitsTest extends ServerTestCase
{
    protected static function prefix(): string
    {
        return 'upl_';
    }

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::install();
        self::member('ruth@test.example', 'ruth');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $db = self::connect(self::prefix());
        $db->run('DELETE FROM {{uploads}}');
        $db->run('DELETE FROM {{settings}} WHERE name = ?', ['uploads.max_bytes']);
        self::flushCache();
    }

    private static function start(array $body, string $who = 'admin'): array
    {
        return self::api('POST', '/api/uploads', $body, $who);
    }

    public function test_1_a_file_larger_than_the_site_accepts_is_refused_before_a_byte_arrives(): void
    {
        $db = self::connect(self::prefix());
        $db->run(
            'INSERT INTO {{settings}} (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
            ['uploads.max_bytes', (string) json_encode(1_048_576)],
        );
        self::flushCache();

        $answer = self::start(['purpose' => 'import', 'fileName' => 'big.zip', 'size' => 5_000_000]);
        self::assertSame(413, $answer['status']);
        self::assertStringContainsString('MB', (string) $answer['body'], 'and says what the limit is');
        self::assertSame(0, (int) $db->value('SELECT COUNT(*) FROM {{uploads}}'), 'nothing is reserved on disk');
    }

    public function test_2_a_size_that_is_not_a_size_is_refused(): void
    {
        foreach ([0, -1, -5_000_000] as $size) {
            self::assertSame(413, self::start(['purpose' => 'import', 'fileName' => 'x.zip', 'size' => $size])['status'], (string) $size);
        }
    }

    public function test_3_an_upload_is_addressed_by_a_random_id(): void
    {
        $first = self::start(['purpose' => 'import', 'fileName' => 'a.zip', 'size' => 100]);
        $second = self::start(['purpose' => 'import', 'fileName' => 'a.zip', 'size' => 100]);

        self::assertSame(201, $first['status']);
        self::assertNotSame($first['json']['id'], $second['json']['id']);
        self::assertMatchesRegularExpression('/^[a-z0-9]{20,32}$/', (string) $first['json']['id'], 'not a name the caller chose');
    }

    public function test_4_a_purpose_nobody_defined_is_refused(): void
    {
        foreach (['', 'anything', '../../etc', 'IMPORT'] as $purpose) {
            self::assertSame(400, self::start(['purpose' => $purpose, 'fileName' => 'x.zip', 'size' => 100])['status'], $purpose);
        }
    }

    public function test_5_the_dangerous_purposes_need_the_right_person(): void
    {
        // A plugin is code that runs as the site; a release zip and an
        // import change all of it.
        foreach (['plugin', 'theme', 'release', 'import'] as $purpose) {
            self::assertSame(403, self::start(['purpose' => $purpose, 'fileName' => 'x.zip', 'size' => 100], 'ruth')['status'], $purpose);
        }
        self::assertSame(201, self::start(['purpose' => 'import', 'fileName' => 'x.zip', 'size' => 100])['status']);
    }

    public function test_6_those_purposes_take_a_zip_and_nothing_else(): void
    {
        foreach (['evil.php', 'notes.html', 'x.zip.php', 'x'] as $name) {
            self::assertSame(415, self::start(['purpose' => 'plugin', 'fileName' => $name, 'size' => 100])['status'], $name);
        }
    }

    public function test_7_an_ordinary_purpose_still_checks_the_kind_of_file(): void
    {
        self::assertSame(415, self::start(['purpose' => 'image', 'fileName' => 'notes.pdf', 'size' => 100])['status']);
        self::assertSame(201, self::start(['purpose' => 'image', 'fileName' => 'logo.png', 'size' => 100])['status']);
    }

    public function test_8_a_chunk_cannot_be_written_past_the_size_that_was_declared(): void
    {
        $id = (string) self::start(['purpose' => 'import', 'fileName' => 'a.zip', 'size' => 10])['json']['id'];
        $answer = self::http('PUT', "/api/uploads/$id/chunk?offset=0", str_repeat('x', 5000), 'admin', [
            'Content-Type' => 'application/octet-stream',
            'X-CSRF-Token' => self::token(),
        ]);
        self::assertContains($answer['status'], [400, 413], 'more bytes than it said it would send');
    }

    public function test_9_the_daily_sweep_clears_what_was_abandoned_and_keeps_what_is_live(): void
    {
        $db = self::connect(self::prefix());
        $abandoned = (string) self::start(['purpose' => 'import', 'fileName' => 'old.zip', 'size' => 100])['json']['id'];
        $live = (string) self::start(['purpose' => 'import', 'fileName' => 'new.zip', 'size' => 100])['json']['id'];
        $db->update('uploads', ['created_at' => Db::datetime(new \DateTimeImmutable('-2 days'))], ['id' => $abandoned]);

        // The sweep runs inside the site, through its own scheduled job.
        self::api('POST', '/admin/jobs/core.prune/run');

        self::assertNull($db->one('SELECT id FROM {{uploads}} WHERE id = ?', [$abandoned]), 'a day-old upload is gone');
        self::assertNotNull($db->one('SELECT id FROM {{uploads}} WHERE id = ?', [$live]), 'and one somebody is still sending is not');
    }

    private static function token(): string
    {
        preg_match('/name="csrf-token" content="([^"]+)"/', self::http('GET', '/')['body'], $m);
        return $m[1] ?? '';
    }
}

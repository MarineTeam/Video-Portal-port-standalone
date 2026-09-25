<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Id;

/**
 * The Sermon notes plugin through a real server: the note sheet in place
 * of the plain outline, answers kept only against the version they were
 * written for, private timestamped notes, and one text file holding both.
 */
final class SermonNotesTest extends ServerTestCase
{
    private static string $video = '';

    protected static function prefix(): string
    {
        return 'sn_';
    }

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::install();
        self::$video = Id::new();
        self::connect(self::prefix())->insert('videos', ['id' => self::$video, 'title' => 'Grace', 'slug' => 'grace', 'published' => 1, 'status' => 'READY', 'provider' => 'direct', 'external_id' => 'g', 'scripture_refs' => [], 'provider_data' => ['url' => 'https://cdn.example.org/g.mp4'], 'note_outline' => "Grace is ___.\n\nIt is ___ and ___."]);
        self::flushCache();
        self::member('ruth@test.example', 'ruth');
        self::member('boaz@test.example', 'boaz');
    }

    private static function version(): string
    {
        preg_match('/data-version="([0-9a-f]{64})"/', self::http('GET', '/videos/grace', null, 'ruth')['body'], $m);
        return $m[1] ?? '';
    }

    public function test_the_sheet_takes_the_outlines_place(): void
    {
        $member = self::http('GET', '/videos/grace', null, 'ruth')['body'];
        self::assertSame(3, substr_count($member, 'data-gap="'));
        self::assertStringNotContainsString('<summary>Notes</summary>', $member, 'no plain copy of the outline beside the sheet');
        $guest = self::http('GET', '/videos/grace', null, 'guest')['body'];
        self::assertStringNotContainsString('data-gap="', $guest);
        self::assertStringContainsString('Sign in to fill this in', $guest);
    }

    public function test_answers_are_kept_against_the_sheet_they_were_written_for(): void
    {
        $version = self::version();
        $r = self::api('PUT', '/api/videos/outline', ['videoId' => self::$video, 'outlineVersion' => $version, 'answers' => ['0' => 'unearned', '2' => 'free', '1' => '  ']], 'ruth');
        self::assertSame(['answers' => ['0' => 'unearned', '2' => 'free'], 'outlineVersion' => $version], $r['json']);
        self::assertStringContainsString('value="unearned"', self::http('GET', '/videos/grace', null, 'ruth')['body']);
        self::assertStringNotContainsString('value="unearned"', self::http('GET', '/videos/grace', null, 'boaz')['body'], 'nobody else\'s');
        self::assertSame(400, self::api('PUT', '/api/videos/outline', ['videoId' => self::$video, 'outlineVersion' => $version, 'answers' => ['7' => 'x']], 'ruth')['status'], 'no such blank');
        self::assertSame(409, self::api('PUT', '/api/videos/outline', ['videoId' => self::$video, 'outlineVersion' => 'old', 'answers' => []], 'ruth')['status']);

        // The admin inserts a blank at the top: Ruth's answers no longer line up, and the page says so.
        self::connect(self::prefix())->update('videos', ['note_outline' => "Read ___ first.\nGrace is ___.\n\nIt is ___ and ___."], ['id' => self::$video]);
        $page = self::http('GET', '/videos/grace', null, 'ruth')['body'];
        self::assertStringContainsString('has changed since you filled it in', $page);
        self::assertStringNotContainsString('value="unearned"', $page);
        self::assertStringContainsString('unearned · free', $page);
    }

    public function test_private_notes_and_the_text_file(): void
    {
        $a = self::api('POST', '/api/notes', ['videoId' => self::$video, 'timestamp' => '12:03', 'body' => 'Great point about grace'], 'ruth');
        self::assertSame(201, $a['status']);
        self::assertSame(723, $a['json']['timestampSeconds']);
        self::api('POST', '/api/notes', ['videoId' => self::$video, 'timestampSeconds' => 30, 'body' => 'Opening prayer'], 'ruth');
        self::assertSame(400, self::api('POST', '/api/notes', ['videoId' => self::$video, 'timestamp' => '9:99', 'body' => 'x'], 'ruth')['status']);
        self::assertSame(['Opening prayer', 'Great point about grace'], array_column((array) self::http('GET', '/api/notes?videoId=' . self::$video, null, 'ruth')['json'], 'body'));
        self::assertSame([], self::http('GET', '/api/notes?videoId=' . self::$video, null, 'boaz')['json']);
        self::assertSame(404, self::api('DELETE', '/api/notes/' . $a['json']['id'], null, 'boaz')['status'], 'not his');
        self::assertSame(200, self::api('PATCH', '/api/notes/' . $a['json']['id'], ['body' => 'Great point about grace!'], 'ruth')['status']);

        $file = self::http('GET', '/api/notes?format=text&videoId=' . self::$video, null, 'ruth');
        self::assertSame(200, $file['status']);
        self::assertStringContainsString("0:30 — Opening prayer\n12:03 — Great point about grace!\n", $file['body']);
        self::assertStringStartsWith("Grace\n", $file['body']);
        self::assertSame(401, self::api('GET', '/api/notes?videoId=' . self::$video, null, 'guest')['status']);
    }
}

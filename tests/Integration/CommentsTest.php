<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Id;

/**
 * The Comments plugin through a real server: reading for anybody who may
 * open the page, writing for members, one level of replies, authors
 * deleting their own, reports once per member, and a moderator whose
 * powers stop at the part of the library they were given.
 */
final class CommentsTest extends ServerTestCase
{
    /** @var array<string, string> */
    private static array $ids = [];

    protected static function prefix(): string
    {
        return 'cm_';
    }

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::install();
        $db = self::connect(self::prefix());
        $new = function (string $table, array $row) use ($db): string {
            $id = Id::new();
            $db->insert($table, ['id' => $id] + $row);
            return $id;
        };
        self::$ids['youth'] = $new('categories', ['name' => 'Youth', 'slug' => 'youth', 'published' => 1, 'tags' => []]);
        self::$ids['romans'] = $new('series', ['title' => 'Romans', 'slug' => 'romans', 'published' => 1, 'tags' => []]);
        self::$ids['camp'] = $new('series', ['title' => 'Camp', 'slug' => 'camp', 'published' => 1, 'tags' => [], 'category_id' => self::$ids['youth']]);
        self::$ids['ruth'] = self::member('ruth@test.example', 'ruth');
        self::$ids['boaz'] = self::member('boaz@test.example', 'boaz');
        $mod = self::member('mod@test.example', 'mod');
        $group = $new('permission_groups', ['name' => 'Youth moderators', 'capabilities' => ['moderate_comments']]);
        $new('group_assignments', ['user_id' => $mod, 'group_id' => $group, 'category_id' => self::$ids['youth']]);
        $db->update('users', ['name' => 'boaz@test.example'], ['id' => self::$ids['boaz']]);
        self::flushCache();
    }

    public function test_a_conversation(): void
    {
        $series = ['seriesId' => self::$ids['romans']];
        $first = self::api('POST', '/api/comments', $series + ['body' => 'Chapter 8 is the heart of it.'], 'ruth');
        self::assertSame(201, $first['status']);
        $reply = self::api('POST', '/api/comments', $series + ['body' => 'Agreed.', 'parentId' => $first['json']['id']], 'boaz')['json'];
        self::assertSame($first['json']['id'], $reply['parentId']);
        // A reply to a reply joins the same thread: one level deep.
        self::assertSame($first['json']['id'], self::api('POST', '/api/comments', $series + ['body' => 'Me too', 'parentId' => $reply['id']], 'ruth')['json']['parentId']);

        $thread = self::http('GET', '/api/comments?seriesId=' . self::$ids['romans'], null, 'guest')['json'];
        self::assertCount(1, $thread);
        self::assertCount(2, $thread[0]['replies']);
        self::assertSame('A member', $thread[0]['replies'][0]['author'], 'a name that is an address is never shown');
        self::assertStringNotContainsString('@test.example', self::http('GET', '/series/romans', null, 'guest')['body']);

        self::assertSame(401, self::api('POST', '/api/comments', $series + ['body' => 'hi'], 'guest')['status']);
        self::assertSame(403, self::api('DELETE', '/api/comments/' . $first['json']['id'], null, 'boaz')['status'], 'not his');
        self::assertSame(200, self::api('DELETE', '/api/comments/' . $reply['id'], null, 'boaz')['status'], 'his own');
    }

    public function test_reports_and_a_moderator_scoped_to_their_category(): void
    {
        $camp = self::api('POST', '/api/comments', ['seriesId' => self::$ids['camp'], 'body' => 'Rude words'], 'ruth')['json']['id'];
        $romans = self::api('POST', '/api/comments', ['seriesId' => self::$ids['romans'], 'body' => 'Off topic'], 'ruth')['json']['id'];
        foreach ([$camp, $romans] as $id) {
            self::assertSame(['reported' => true], self::api('POST', "/api/comments/$id/report", null, 'boaz')['json']);
        }
        self::assertSame(['reported' => true], self::api('POST', "/api/comments/$camp/report", null, 'boaz')['json'], 'once, however often');
        self::assertSame(400, self::api('POST', "/api/comments/$camp/report", null, 'ruth')['status'], 'not one\'s own');

        // The youth moderator sees and acts on Youth only.
        self::assertSame([$camp], array_column((array) self::api('GET', '/api/admin/comments', null, 'mod')['json'], 'id'));
        self::assertSame(404, self::api('PATCH', "/api/admin/comments/$romans", ['hidden' => true], 'mod')['status']);
        self::assertSame(403, self::api('DELETE', "/api/comments/$romans", null, 'mod')['status']);
        self::assertSame(['hidden' => true], self::api('PATCH', "/api/admin/comments/$camp", ['hidden' => true], 'mod')['json']);
        self::assertSame([], self::http('GET', '/api/comments?seriesId=' . self::$ids['camp'], null, 'guest')['json'], 'hidden from readers');
        self::assertSame(2, count((array) self::api('GET', '/api/admin/comments')['json']), 'an administrator sees everything');
        self::assertSame(403, self::api('GET', '/api/admin/comments', null, 'boaz')['status']);
    }
}

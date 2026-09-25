<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\App;
use App\Core\Db;
use App\Core\Id;
use App\Core\Migrator;
use App\Core\Paths;
use App\Modules\Profile\DataExport;
use App\Modules\Profile\ExportQueries;

/** "Download my data" against a real database: one member's rows, and nobody else's. */
final class DataExportTest extends DatabaseTestCase
{
    private const PREFIX = 'dx_';

    public function testOneMembersRowsAndNobodyElses(): void
    {
        $db = self::connect(self::PREFIX);
        self::dropPrefix($db, self::PREFIX);
        (new Migrator($db, dirname(__DIR__, 2) . '/app/Migrations'))->runAll();
        try {
            $ruth = $this->member($db, 'ruth@x.test', 'Ruth');
            $boaz = $this->member($db, 'boaz@x.test', 'Boaz');
            $db->run('UPDATE {{users}} SET password_hash = ?, calendar_token = ? WHERE id = ?', ['$argon2id$secret', 'cal-token-ruth', $ruth]);

            $open = $this->group($db, 'Tuesday', 'North', '1 Harbour Row');
            $full = $this->group($db, 'Friday', 'East', '9 Quay Street');
            $db->insert('small_group_members', ['id' => Id::new(), 'group_id' => $open, 'user_id' => $ruth, 'status' => 'ACTIVE']);
            $db->insert('small_group_members', ['id' => Id::new(), 'group_id' => $full, 'user_id' => $ruth, 'status' => 'WAITLIST']);
            $db->insert('small_group_members', ['id' => Id::new(), 'group_id' => $full, 'user_id' => $boaz, 'status' => 'ACTIVE']);

            $db->insert('push_subscriptions', ['id' => Id::new(), 'user_id' => $ruth, 'endpoint' => 'https://fcm.googleapis.com/fcm/send/abc:DEF', 'endpoint_hash' => hash('sha256', 'a'), 'p256dh' => 'BNc', 'auth' => 'xyz']);
            $db->insert('notifications', ['id' => Id::new(), 'user_id' => $ruth, 'title' => 'For Ruth', 'body' => 'hi']);
            $db->insert('notifications', ['id' => Id::new(), 'user_id' => $boaz, 'title' => 'For Boaz', 'body' => 'private']);
            $parent = Id::new();
            $db->insert('comments', ['id' => $parent, 'user_id' => $boaz, 'body' => 'Boaz wrote this']);
            $db->insert('comments', ['id' => Id::new(), 'user_id' => $ruth, 'body' => 'Ruth replied', 'parent_id' => $parent]);
            $db->insert('tv_devices', ['id' => Id::new(), 'user_code' => 'ABCD-EFGH', 'device_code_hash' => hash('sha256', 'd'), 'status' => 'LINKED', 'device_name' => 'Lounge', 'user_id' => $ruth, 'token_hash' => hash('sha256', 't'), 'expires_at' => Db::now()]);

            $app = new App(new Paths(dirname(__DIR__, 2), sys_get_temp_dir(), dirname(__DIR__, 2) . '/plugins', dirname(__DIR__, 2) . '/themes'));
            $app->config = ['database' => self::dbConfig(self::PREFIX)];
            $doc = DataExport::build($app, $ruth);
            $json = (string) json_encode($doc);

            self::assertSame('ruth@x.test', $doc['account']['email']);
            self::assertTrue($doc['account']['hasPassword']);
            self::assertSame(array_merge(['format', 'exportedAt'], array_keys(ExportQueries::QUERIES)), array_keys($doc), 'every section, always present');
            self::assertStringNotContainsString('Boaz', $json, 'nobody else’s rows or words');
            self::assertStringNotContainsString('private', $json);
            self::assertSame([['title' => 'For Ruth', 'body' => 'hi', 'url' => null, 'readAt' => null, 'createdAt' => $doc['notifications'][0]['createdAt']]], $doc['notifications']);
            self::assertTrue($doc['comments'][0]['isReply']);
            self::assertSame([['service' => 'https://fcm.googleapis.com', 'createdAt' => $doc['pushDevices'][0]['createdAt']]], $doc['pushDevices']);
            foreach (['cal-token-ruth', '$argon2id', 'abc:DEF', 'ABCD-EFGH', 'BNc'] as $secret) {
                self::assertStringNotContainsString($secret, $json, "$secret leaked");
            }
            $groups = array_column($doc['smallGroups'], null, 'groupName');
            self::assertSame('1 Harbour Row', $groups['Tuesday']['address'], 'a member of the group gets the address');
            self::assertArrayNotHasKey('address', $groups['Friday'], 'the waiting list gets the area, not the house');
            self::assertSame('East', $groups['Friday']['area']);
            self::assertSame([], DataExport::unsafeKeysIn($doc));
        } finally {
            self::dropPrefix($db, self::PREFIX);
        }
    }

    private function member(Db $db, string $email, string $name): string
    {
        $id = Id::new();
        $db->insert('users', ['id' => $id, 'email' => $email, 'name' => $name, 'authorized' => true]);
        return $id;
    }

    private function group(Db $db, string $name, string $area, string $address): string
    {
        $id = Id::new();
        $db->insert('small_groups', ['id' => $id, 'slug' => strtolower($name), 'name' => $name, 'area' => $area, 'address' => $address, 'published' => true]);
        return $id;
    }
}

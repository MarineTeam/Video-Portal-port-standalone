<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Db;
use App\Core\Migrator;
use App\Modules\Access\GuestLogin;

/** lib/authorization.test.ts — isGuestLoginEnabled / setGuestLoginEnabled */
final class GuestLoginTest extends DatabaseTestCase
{
    private const PREFIX = 'gl_';
    private static ?Db $db = null;

    public static function setUpBeforeClass(): void
    {
        $name = getenv('MT_TEST_DB_NAME');
        if (!is_string($name) || $name === '') {
            return;
        }
        self::$db = self::connect(self::PREFIX);
        self::dropPrefix(self::$db, self::PREFIX);
        (new Migrator(self::$db, dirname(__DIR__, 2) . '/app/Migrations'))->runAll();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            self::dropPrefix(self::$db, self::PREFIX);
        }
    }

    private function db(): Db
    {
        if (self::$db === null) {
            self::markTestSkipped('MT_TEST_DB_NAME is not set.');
        }
        return self::$db;
    }

    public function test_defaults_closed_on_a_never_touched_deployment(): void
    {
        $this->db()->run('DELETE FROM {{auth_settings}}');
        $this->assertFalse(GuestLogin::enabled($this->db()));
    }

    public function test_reflects_true_once_an_admin_has_opened_it(): void
    {
        $this->db()->run('DELETE FROM {{auth_settings}}');
        GuestLogin::set($this->db(), true);
        $this->assertTrue(GuestLogin::enabled($this->db()));
    }

    public function test_setGuestLoginEnabled_writes_both_create_and_update_with_the_same_value(): void
    {
        $this->db()->run('DELETE FROM {{auth_settings}}');
        GuestLogin::set($this->db(), true);   // create
        $this->assertTrue(GuestLogin::enabled($this->db()));
        GuestLogin::set($this->db(), false);  // update
        $this->assertFalse(GuestLogin::enabled($this->db()));
        GuestLogin::set($this->db(), true);
        $this->assertTrue(GuestLogin::enabled($this->db()));
        $this->assertSame(1, (int) $this->db()->value('SELECT COUNT(*) FROM {{auth_settings}}'));
    }
}

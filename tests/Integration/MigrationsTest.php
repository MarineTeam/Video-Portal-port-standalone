<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Migrator;
use PHPUnit\Framework\Attributes\TestDox;

final class MigrationsTest extends DatabaseTestCase
{
    #[TestDox('the schema applies to an empty database, and again without harm')]
    public function testFromEmpty(): void
    {
        $db = self::connect('mg_');
        self::dropPrefix($db, 'mg_');
        $migrator = new Migrator($db, dirname(__DIR__, 2) . '/app/Migrations');
        self::assertGreaterThan(0, $migrator->runAll());
        self::assertSame([], $migrator->pending());
        $tables = $db->column('SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE ?', ['mg\\_%']);
        // 95 models, 10 of the port's own, and schema_migrations.
        self::assertCount(106, $tables);
        // A file that died half way runs again safely.
        $migrator->apply(dirname(__DIR__, 2) . '/app/Migrations/0001_init.sql');
        self::dropPrefix($db, 'mg_');
    }

    #[TestDox('a duplicate is reported as a duplicate, a missing parent as a foreign-key refusal')]
    public function testErrorMapping(): void
    {
        $db = self::connect('mg_');
        self::dropPrefix($db, 'mg_');
        (new Migrator($db, dirname(__DIR__, 2) . '/app/Migrations'))->runAll();
        $db->insert('users', ['email' => 'a@b.test']);
        try {
            $db->insert('users', ['email' => 'a@b.test']);
            self::fail('Expected a duplicate');
        } catch (\Throwable $e) {
            self::assertTrue(\App\Core\Db::isDuplicate($e));
        }
        try {
            $db->insert('user_identities', ['user_id' => 'cnotthere00000000000000', 'sub' => 'x', 'provider' => 'x', 'email' => 'x@y.z']);
            self::fail('Expected a foreign key refusal');
        } catch (\Throwable $e) {
            self::assertTrue(\App\Core\Db::isForeignKey($e));
        }
        self::dropPrefix($db, 'mg_');
    }
}

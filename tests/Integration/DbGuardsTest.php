<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Db;
use App\Core\Migrator;

/**
 * The two places an identifier could come from somewhere it should not.
 *
 * Values are bound, always, so the injection surface is not values — it is
 * the prefix, which comes from the installer, and column names, which come
 * from arrays a caller builds. Both are checked against a pattern rather
 * than quoted, because a name that needs quoting is a name that is wrong.
 */
final class DbGuardsTest extends DatabaseTestCase
{
    private const PREFIX = 'guard_';
    private static ?Db $db = null;

    public static function setUpBeforeClass(): void
    {
        $name = getenv('MT_TEST_DB_NAME');
        if (!is_string($name) || $name === '') {
            return;
        }
        $db = self::connect(self::PREFIX);
        self::dropPrefix($db, self::PREFIX);
        (new Migrator($db, dirname(__DIR__, 2) . '/app/Migrations'))->runAll();
        self::$db = $db;
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            self::dropPrefix(self::$db, self::PREFIX);
        }
    }

    protected function setUp(): void
    {
        if (self::$db === null) {
            self::markTestSkipped('MT_TEST_DB_NAME is not set.');
        }
        self::$db->run('DELETE FROM {{speakers}}');
    }

    public function test_1_a_prefix_that_is_not_a_prefix_is_refused_at_construction(): void
    {
        $config = self::dbConfig(self::PREFIX);
        foreach ([
            'mt`; DROP TABLE users; --',
            'mt.other',
            'MT_',
            'mt-1',
            '',
            str_repeat('a', 17),
            'mt ',
        ] as $prefix) {
            try {
                Db::connect(['prefix' => $prefix] + $config);
                self::fail("prefix '$prefix' should be refused");
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('Table prefix', $e->getMessage(), $prefix);
            }
        }
    }

    public function test_2_an_ordinary_prefix_is_accepted(): void
    {
        foreach (['mt_', 'a', 'church2024_', str_repeat('a', 16)] as $prefix) {
            $db = Db::connect(['prefix' => $prefix] + self::dbConfig(self::PREFIX));
            self::assertSame($prefix, $db->prefix(), $prefix);
        }
    }

    public function test_3_a_column_name_that_is_not_one_is_refused(): void
    {
        foreach ([
            'name`, (SELECT password_hash FROM users) -- ',
            'name; DROP TABLE speakers',
            'name)',
            'users.name',
            'NAME',
            '',
            ' name',
        ] as $column) {
            try {
                self::$db->insert('speakers', ['id' => 'x', $column => 'y']);
                self::fail("column '$column' should be refused");
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('Bad column name', $e->getMessage(), $column);
            }
        }
    }

    public function test_4_the_same_guard_is_on_an_update_and_a_where(): void
    {
        self::$db->insert('speakers', ['id' => 'spk1', 'name' => 'A preacher', 'slug' => 'a-preacher']);
        foreach ([
            fn () => self::$db->update('speakers', ['name`, slug = `x' => 'y'], ['id' => 'spk1']),
            fn () => self::$db->update('speakers', ['name' => 'y'], ['id`, 1=1 -- ' => 'spk1']),
            fn () => self::$db->delete('speakers', ['id`; DROP TABLE speakers; --' => 'spk1']),
        ] as $i => $attempt) {
            try {
                $attempt();
                self::fail("attempt $i should be refused");
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('Bad column name', $e->getMessage());
            }
        }
        // And the table is still there with its row.
        self::assertSame('A preacher', self::$db->value('SELECT name FROM {{speakers}} WHERE id = ?', ['spk1']));
    }

    public function test_5_a_value_that_looks_like_sql_is_only_ever_a_value(): void
    {
        $nasty = "'); DROP TABLE speakers; --";
        self::$db->insert('speakers', ['id' => 'spk2', 'name' => $nasty, 'slug' => 'nasty']);
        self::assertSame($nasty, self::$db->value('SELECT name FROM {{speakers}} WHERE id = ?', ['spk2']));
        self::assertSame(1, (int) self::$db->value('SELECT COUNT(*) FROM {{speakers}}'), 'the table survived being told to drop itself');
    }

    public function test_6_a_like_search_treats_its_wildcards_as_text(): void
    {
        foreach ([['spk3', 'Pastor 100%'], ['spk4', 'Pastor 1000'], ['spk5', 'a_b'], ['spk6', 'axb']] as [$id, $name]) {
            self::$db->insert('speakers', ['id' => $id, 'name' => $name, 'slug' => $id]);
        }
        $find = fn (string $term) => self::$db->column(
            'SELECT name FROM {{speakers}} WHERE name LIKE ? ORDER BY name',
            ['%' . Db::likeEscape($term) . '%'],
        );
        self::assertSame(['Pastor 100%'], $find('100%'), 'the per cent is a character, not "anything"');
        self::assertSame(['a_b'], $find('a_b'), 'and the underscore is not "any one character"');
        // Without escaping, "%" alone would match every row; with it, none.
        self::assertSame([], $find('zzz%'));
    }

    public function test_7_the_backslash_itself_is_escaped_first(): void
    {
        // Escaping % and _ but not \ would let "\%" through as an escape.
        self::assertSame('\\\\\\%', Db::likeEscape('\\%'));
        self::$db->insert('speakers', ['id' => 'spk7', 'name' => 'back\\slash', 'slug' => 'back']);
        self::assertSame(
            ['back\\slash'],
            self::$db->column('SELECT name FROM {{speakers}} WHERE name LIKE ?', ['%' . Db::likeEscape('back\\slash') . '%']),
        );
    }
}

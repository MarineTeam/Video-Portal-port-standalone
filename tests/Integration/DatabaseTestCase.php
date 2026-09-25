<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Db;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests run against a real MySQL or MariaDB, named by
 * MT_TEST_DB_HOST / _PORT / _NAME / _USER / _PASS. Without them they skip.
 */
abstract class DatabaseTestCase extends TestCase
{
    /** @return array{host: string, port: int, name: string, user: string, password: string, prefix: string} */
    protected static function dbConfig(string $prefix = 'it_'): array
    {
        $name = getenv('MT_TEST_DB_NAME');
        if (!is_string($name) || $name === '') {
            self::markTestSkipped('MT_TEST_DB_NAME is not set.');
        }
        return [
            'host' => (string) (getenv('MT_TEST_DB_HOST') ?: '127.0.0.1'),
            'port' => (int) (getenv('MT_TEST_DB_PORT') ?: 3306),
            'name' => $name,
            'user' => (string) (getenv('MT_TEST_DB_USER') ?: 'root'),
            'password' => (string) (getenv('MT_TEST_DB_PASS') ?: ''),
            'prefix' => $prefix,
        ];
    }

    protected static function connect(string $prefix = 'it_'): Db
    {
        return Db::connect(self::dbConfig($prefix));
    }

    protected static function dropPrefix(Db $db, string $prefix): void
    {
        $db->pdo()->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($db->column('SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE ?', [Db::likeEscape($prefix) . '%']) as $table) {
            $db->pdo()->exec('DROP TABLE `' . str_replace('`', '', (string) $table) . '`');
        }
        $db->pdo()->exec('SET FOREIGN_KEY_CHECKS = 1');
    }
}

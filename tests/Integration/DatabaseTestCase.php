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
    /**
     * Restores the dump with the real mysql client, the way an administrator
     * or their host would.
     *
     * Not with a splitter written here. BackupTest has one, with a comment
     * saying it splits "the way the mysql client does" — and a parser that
     * agrees with the file it was written for proves only that. If the dump
     * ever holds something the real client reads differently, a splitter of
     * our own would keep passing while the restore that matters failed.
     */
    protected static function restoreWithTheRealClient(string $sql, string $prefix): void
    {
        $client = null;
        foreach (['mysql', 'mariadb'] as $candidate) {
            $found = @proc_open([$candidate, '--version'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (is_resource($found)) {
                fclose($pipes[1]);
                fclose($pipes[2]);
                if (proc_close($found) === 0) {
                    $client = $candidate;
                    break;
                }
            }
        }
        if ($client === null) {
            self::markTestSkipped('no mysql client to restore with.');
        }

        $db = self::dbConfig($prefix);
        $process = proc_open(
            [
                $client,
                '--host=' . $db['host'],
                '--port=' . (string) $db['port'],
                '--user=' . $db['user'],
                '--password=' . $db['password'],
                '--default-character-set=utf8mb4',
                $db['name'],
            ],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process, 'the client would not start');
        fwrite($pipes[0], $sql);
        fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        // A warning about the password on the command line is the one thing
        // it always says; anything else is the restore complaining.
        $err = trim((string) preg_replace('/^.*Using a password on the command line.*$/mi', '', $err));
        self::assertSame(0, $status, "the restore failed:\n$err\n$out");
        self::assertSame('', $err, "the restore complained:\n$err");
    }

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

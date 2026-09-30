<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Db;
use App\Core\Id;
use App\Modules\Tools\Backup;

/**
 * A backup is only worth what a restore gets back.
 *
 * BackupTest proves the mechanics: many gzip members read as one, awkward
 * quoting, a resumable write. It puts rows in two tables. This one takes the
 * backup off a site that has really been installed — the schema the migrator
 * builds, the rows the seeding writes, every plugin active — and then does
 * what a church does on its worst day: drops everything and replays the file
 * into an empty database.
 *
 * Then it compares all of it. Not a row count: every table's CREATE, and
 * every row of every table, column by column. A backup that quietly loses
 * one table's rows, or a column's default, or turns a NULL into an empty
 * string, is a backup that looks fine until the day it is needed.
 */
final class BackupFidelityTest extends ServerTestCase
{
    protected static function prefix(): string
    {
        return 'bkf_';
    }

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::install();
    }

    /**
     * One row in every table, with the values a dump written by hand gets
     * wrong: quotes and backslashes, newlines, characters outside the basic
     * plane, something that reads like SQL, a NULL that must stay NULL, and
     * zero where a number is wanted.
     *
     * A fresh install leaves almost every table empty, and an empty table
     * cannot show that its rows would have survived. Foreign keys are off
     * for this: the point is the shape of the data, not that it describes a
     * church that could exist.
     */
    /** @var list<string> tables that would not take a generated row */
    private static array $refused = [];

    private static function fillEveryTable(Db $db, Backup $backup): int
    {
        self::$refused = [];
        $filled = 0;
        $db->run('SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach ($backup->tables() as $table) {
                $columns = $db->all(
                    'SELECT column_name, data_type, is_nullable, extra, character_maximum_length, column_key
                     FROM information_schema.columns
                     WHERE table_schema = DATABASE() AND table_name = ? ORDER BY ordinal_position',
                    [$table],
                );
                $names = [];
                $values = [];
                foreach ($columns as $i => $column) {
                    $extra = strtolower((string) ($column['extra'] ?? ''));
                    // A generated or auto-increment column is not ours to write.
                    if (str_contains($extra, 'generated') || str_contains($extra, 'auto_increment')) {
                        continue;
                    }
                    $name = (string) $column['column_name'];
                    $nullable = strtoupper((string) $column['is_nullable']) === 'YES';
                    // Leave one nullable column of each table NULL, to prove
                    // a NULL does not come back as an empty string.
                    $value = $nullable && $i % 5 === 4
                        ? null
                        : self::valueFor((string) $column['data_type'], (int) ($column['character_maximum_length'] ?? 0), $i);
                    $names[] = '`' . str_replace('`', '``', $name) . '`';
                    $values[] = $value;
                }
                if ($names === []) {
                    continue;
                }
                $sql = 'INSERT INTO `' . str_replace('`', '``', $table) . '` (' . implode(', ', $names) . ')'
                    . ' VALUES (' . implode(', ', array_fill(0, count($names), '?')) . ')';
                try {
                    $db->pdo()->prepare($sql)->execute($values);
                    $filled++;
                } catch (\PDOException) {
                    // An enum, a check or a unique this guesses wrong for.
                    // The ones that refuse are named if the count falls short.
                    self::$refused[] = $table;
                }
            }
        } finally {
            $db->run('SET FOREIGN_KEY_CHECKS = 1');
        }
        return $filled;
    }

    private static function valueFor(string $type, int $max, int $seed): string|int|float
    {
        $awkward = "it's \"quoted\" \\ escaped\nnewline\ttab ✝ 🙏 '); DROP TABLE users; --";
        return match ($type) {
            'tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint' => $seed % 2,
            'decimal', 'float', 'double' => 1.5,
            'date' => '2024-02-29',
            'datetime', 'timestamp' => '2024-02-29 13:45:01',
            'time' => '13:45:01',
            'year' => 2024,
            'binary', 'varbinary', 'blob', 'tinyblob', 'mediumblob', 'longblob' => "\x00\x01\xffbytes",
            'json' => json_encode(['a' => [1, 2], 'text' => $awkward], JSON_UNESCAPED_UNICODE),
            default => $max > 0 ? mb_substr($awkward, 0, max(1, min($max, 120))) : $awkward,
        };
    }

    /**
     * Every table: how it is built, and everything in it.
     *
     * @return array{schema: array<string, string>, rows: array<string, list<array<string, mixed>>>}
     */
    private static function snapshot(Db $db, Backup $backup): array
    {
        $schema = [];
        $rows = [];
        foreach ($backup->tables() as $table) {
            $q = '`' . str_replace('`', '``', $table) . '`';
            $create = $db->one("SHOW CREATE TABLE $q");
            // AUTO_INCREMENT drifts with use and is not part of the shape.
            $schema[$table] = (string) preg_replace('/ AUTO_INCREMENT=\d+/', '', (string) ($create['Create Table'] ?? ''));
            $columns = $db->column("SELECT column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? ORDER BY ordinal_position", [$table]);
            $order = implode(', ', array_map(fn ($c) => '`' . str_replace('`', '``', (string) $c) . '`', $columns));
            $rows[$table] = $db->all("SELECT * FROM $q ORDER BY $order");
        }
        return ['schema' => $schema, 'rows' => $rows];
    }

    public function testAnInstalledSiteSurvivesBeingRestoredFromItsBackup(): void
    {
        $db = self::connect(self::prefix());

        $app = new \App\Core\App(new \App\Core\Paths(
            dirname(__DIR__, 2),
            self::$storage,
            dirname(__DIR__, 2) . '/plugins',
            dirname(__DIR__, 2) . '/themes',
        ));
        $app->config = ['database' => self::dbConfig(self::prefix())];
        $backup = new Backup($app, budget: 0.0);

        $tables = $backup->tables();
        self::assertGreaterThan(50, count($tables), 'an installed site has its whole schema');

        self::fillEveryTable($db, $backup);
        $before = self::snapshot($db, $backup);
        $withRows = array_keys(array_filter($before['rows'], fn ($r) => $r !== []));
        // Some tables will not take a row this guesses at — an enum value,
        // a check, a unique already used by the install. What matters is
        // that the great majority do, so that losing one would show.
        self::assertGreaterThan(
            80,
            count($withRows),
            'most of the ' . count($tables) . ' tables hold something. These would not take a row: '
                . implode(', ', self::$refused),
        );

        $state = $backup->start();
        for ($steps = 0; $state['stage'] !== 'ready' && $steps < 5000; $steps++) {
            $state = $backup->step($state['id']);
        }
        self::assertSame('ready', $state['stage']);

        $sql = '';
        $gz = gzopen((string) $backup->path($state['id']), 'rb');
        self::assertNotFalse($gz);
        while (!gzeof($gz)) {
            $sql .= (string) gzread($gz, 1 << 20);
        }
        gzclose($gz);
        self::assertStringEndsWith("-- end of backup\n", $sql);

        // The worst day: nothing left but the file.
        self::dropPrefix($db, self::prefix());
        self::assertSame([], $backup->tables(), 'the database really is empty first');

        self::restoreWithTheRealClient($sql, self::prefix());

        $after = self::snapshot($db, $backup);

        self::assertSame(
            array_keys($before['schema']),
            array_keys($after['schema']),
            'every table comes back, and no others',
        );
        foreach ($before['schema'] as $table => $create) {
            self::assertSame($create, $after['schema'][$table] ?? null, "$table is built the same way");
        }

        // The three that hold only what is in flight come back empty on
        // purpose — restoring somebody's session or a half-finished upload
        // into a new database would be worse than losing it.
        //
        // Named here rather than read from Backup, because a test that asks
        // the code what it means to do cannot catch the code meaning to do
        // the wrong thing. Adding a table to that list is how a backup comes
        // to leave the members behind, and this is what would say so.
        self::assertSame(
            ['sessions', 'rate_limits', 'uploads'],
            Backup::STRUCTURE_ONLY,
            'only what is in flight is left out of a backup',
        );
        foreach (['sessions', 'rate_limits', 'uploads'] as $transient) {
            $table = self::prefix() . $transient;
            if (!isset($after['rows'][$table])) {
                continue;
            }
            self::assertSame([], $after['rows'][$table], "$table is restored empty");
            unset($before['rows'][$table], $after['rows'][$table]);
        }

        foreach ($before['rows'] as $table => $rows) {
            self::assertSame($rows, $after['rows'][$table] ?? null, "every row of $table, column for column");
        }
    }
}

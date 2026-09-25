<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\App;
use App\Core\Db;
use App\Core\Id;
use App\Core\Migrator;
use App\Core\Paths;
use App\Modules\Plugins\PackageInstaller;
use App\Modules\Tools\Backup;

/**
 * The backup restores to exactly what it was taken from, however many
 * requests (and so gzip members) it took to write.
 */
final class BackupTest extends DatabaseTestCase
{
    private const PREFIX = 'bk_';

    public function testRoundTrip(): void
    {
        $db = self::connect(self::PREFIX);
        self::dropPrefix($db, self::PREFIX);
        (new Migrator($db, dirname(__DIR__, 2) . '/app/Migrations'))->runAll();
        $rows = [];
        for ($i = 0; $i < 600; $i++) {
            $rows[] = [Id::new(), "actor$i@x.test", 'test.action', 'Thing', (string) $i, $i % 7 === 0 ? null : "détail ‘{$i}’ \\ 'quoted' ;\n-- not a comment"];
        }
        foreach (array_chunk($rows, 100) as $chunk) {
            $db->run(
                'INSERT INTO {{audit_logs}} (id, actor_email, action, entity_type, entity_id, detail) VALUES ' . implode(', ', array_fill(0, count($chunk), '(?, ?, ?, ?, ?, ?)')),
                array_merge(...$chunk),
            );
        }
        $db->run('INSERT INTO {{settings}} (name, value) VALUES (?, ?)', ['test.json', json_encode(['a' => [1, 2], 'b' => "x\ny"])]);
        $before = $this->snapshot($db);

        $storage = sys_get_temp_dir() . '/mt-backup-' . bin2hex(random_bytes(4));
        mkdir($storage, 0775, true);
        $root = dirname(__DIR__, 2);
        $app = new App(new Paths($root, $storage, "$root/plugins", "$root/themes"));
        $app->config = ['database' => self::dbConfig(self::PREFIX)];
        try {
            $backup = new Backup($app, budget: 0.0);
            $state = $backup->start();
            $steps = 0;
            while ($state['stage'] !== 'ready' && $steps < 1000) {
                $state = $backup->step($state['id']);
                $steps++;
            }
            self::assertSame('ready', $state['stage']);
            self::assertGreaterThan(3, $steps, 'a zero budget writes one batch per step');
            $path = (string) $backup->path($state['id']);
            // Read as phpMyAdmin and gunzip do (zlib's gzread, which reads
            // every member); gzdecode() would stop after the first.
            $sql = '';
            $gz = gzopen($path, 'rb');
            self::assertNotFalse($gz);
            while (!gzeof($gz)) {
                $sql .= (string) gzread($gz, 1 << 20);
            }
            gzclose($gz);
            self::assertStringEndsWith("-- end of backup\n", $sql, 'every gzip member is read back');
            self::assertStringNotContainsString('`it_', $sql, 'only this site’s tables');

            self::dropPrefix($db, self::PREFIX);
            $pdo = $db->pdo();
            foreach (self::statements($sql) as $statement) {
                $pdo->exec($statement);
            }
            self::assertSame($before, $this->snapshot($db));
        } finally {
            PackageInstaller::removeTree($storage);
            self::dropPrefix($db, self::PREFIX);
        }
    }

    /** @return array<string, mixed> */
    private function snapshot(Db $db): array
    {
        return [
            'audit' => $db->all('SELECT id, actor_email, action, entity_type, entity_id, detail, created_at FROM {{audit_logs}} ORDER BY id'),
            'settings' => $db->all('SELECT name, value FROM {{settings}} ORDER BY name'),
            'tables' => count($db->column('SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE ?', [Db::likeEscape(self::PREFIX) . '%'])),
        ];
    }

    /**
     * Splits the dump the way the mysql client does for this file: a
     * statement ends at ";" at the end of a line outside a quoted string.
     *
     * @return list<string>
     */
    private static function statements(string $sql): array
    {
        $out = [];
        $current = '';
        $quote = null;
        $len = strlen($sql);
        for ($i = 0; $i < $len; $i++) {
            $c = $sql[$i];
            if ($quote === null && $c === '-' && substr($sql, $i, 3) === '-- ' && ($i === 0 || $sql[$i - 1] === "\n")) {
                $i = (int) (strpos($sql, "\n", $i) ?: $len);
                continue;
            }
            $current .= $c;
            if ($quote !== null) {
                if ($c === '\\') {
                    $current .= $sql[++$i];
                } elseif ($c === $quote) {
                    $quote = null;
                }
            } elseif ($c === "'" || $c === '"' || $c === '`') {
                $quote = $c;
            } elseif ($c === ';') {
                $out[] = trim($current);
                $current = '';
            }
        }
        if (trim($current) !== '') {
            $out[] = trim($current);
        }
        return array_values(array_filter($out, fn ($s) => $s !== '' && $s !== ';'));
    }
}

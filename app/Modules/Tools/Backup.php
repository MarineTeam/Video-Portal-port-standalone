<?php

declare(strict_types=1);

namespace App\Modules\Tools;

use App\Core\App;
use App\Core\Db;
use App\Core\Id;

/**
 * The database as a .sql.gz, written in PHP table by table — no mysqldump —
 * a few thousand rows per request so no step nears the host's time or
 * memory limit. Each step appends its own gzip member to the file, which
 * gunzip, zcat and phpMyAdmin read as one stream.
 *
 * The file lives under storage/tmp/backups with a random name and is deleted
 * once downloaded (or by the daily sweep). Secrets stay as they are stored:
 * encrypted with the app key in storage/config.php, so a restored copy needs
 * that file too.
 */
final class Backup
{
    public const BUDGET_SECONDS = 12.0;
    public const BATCH = 250;

    /** Tables whose rows are throwaway state: their structure is kept, not their rows. */
    public const STRUCTURE_ONLY = ['sessions', 'rate_limits', 'uploads'];

    public function __construct(private readonly App $app, private readonly float $budget = self::BUDGET_SECONDS)
    {
    }

    public function dir(): string
    {
        return $this->app->paths->storage('tmp/backups');
    }

    /** @return array<string, mixed>|null */
    public function state(string $id): ?array
    {
        if (!preg_match('/^[a-z0-9]{8,32}$/', $id)) {
            return null;
        }
        $state = json_decode((string) @file_get_contents($this->dir() . "/$id.json"), true);
        return is_array($state) ? $state : null;
    }

    /** @param array<string, mixed> $state */
    private function save(array $state): void
    {
        file_put_contents($this->dir() . '/' . $state['id'] . '.json', (string) json_encode($state, JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    /** @return list<string> this site's tables: those with its prefix (every table, if it has none) */
    public function tables(): array
    {
        $db = $this->app->db();
        $prefix = $db->prefix();
        $tables = $prefix === ''
            ? $db->column('SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = \'BASE TABLE\' ORDER BY table_name')
            : $db->column('SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = \'BASE TABLE\' AND table_name LIKE ? ORDER BY table_name', [Db::likeEscape($prefix) . '%']);
        return array_map('strval', $tables);
    }

    /** @return array<string, mixed> */
    public function start(): array
    {
        $dir = $this->dir();
        if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new \RuntimeException('storage/tmp is not writable.');
        }
        $id = Id::new();
        $state = [
            'id' => $id,
            'file' => bin2hex(random_bytes(16)) . '.sql.gz',
            'tables' => $this->tables(),
            'table' => 0,
            'after' => null,
            'rows' => 0,
            'stage' => 'running',
            'started' => gmdate('c'),
        ];
        $db = $this->app->db();
        $header = "-- Marine Team database backup\n"
            . '-- Site version ' . App::VERSION . ', database ' . $db->value('SELECT VERSION()') . ', made ' . gmdate('Y-m-d H:i:s') . " UTC\n"
            . "-- Restore into an empty database: phpMyAdmin → Import, or  gunzip < this.sql.gz | mysql <database>\n"
            . "-- Stored secrets are encrypted with the app key in storage/config.php; restore that file with the database.\n\n"
            . "/*!40101 SET NAMES utf8mb4 */;\nSET FOREIGN_KEY_CHECKS = 0;\nSET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\nSET time_zone = '+00:00';\n\n";
        $this->append($state['file'], $header);
        $this->save($state);
        return $state;
    }

    /** @return array<string, mixed> the state after as much as fits in one request */
    public function step(string $id): array
    {
        $state = $this->state($id);
        if ($state === null || $state['stage'] !== 'running') {
            throw new \RuntimeException('That backup has gone or already finished; start another.');
        }
        $db = $this->app->db();
        $pdo = $db->pdo();
        $prefixLength = strlen($db->prefix());
        $deadline = microtime(true) + $this->budget;
        $out = '';
        // At least one batch per step, however little time is left.
        while ($state['table'] < count($state['tables'])) {
            $table = (string) $state['tables'][$state['table']];
            $q = '`' . str_replace('`', '``', $table) . '`';
            if ($state['after'] === null) {
                $create = $db->one("SHOW CREATE TABLE $q");
                $out .= "DROP TABLE IF EXISTS $q;\n" . (string) ($create['Create Table'] ?? array_values($create ?? [])[1] ?? '') . ";\n\n";
                $state['after'] = [];
                if (in_array(substr($table, $prefixLength), self::STRUCTURE_ONLY, true)) {
                    $state['table']++;
                    $state['after'] = null;
                    continue;
                }
            }
            $keys = $this->primaryKey($table);
            [$rows, $last] = $this->batch($table, $keys, $state['after']);
            if ($rows !== []) {
                $columns = implode(', ', array_map(fn ($c) => '`' . str_replace('`', '``', (string) $c) . '`', array_keys($rows[0])));
                $values = [];
                foreach ($rows as $row) {
                    $values[] = '(' . implode(', ', array_map(fn ($v) => $v === null ? 'NULL' : $pdo->quote((string) $v), $row)) . ')';
                }
                $out .= "INSERT INTO $q ($columns) VALUES\n" . implode(",\n", $values) . ";\n";
                $state['rows'] += count($rows);
            }
            if (count($rows) < self::BATCH || $last === null) {
                $state['table']++;
                $state['after'] = null;
                $out .= "\n";
            } else {
                $state['after'] = $last;
            }
            if (strlen($out) > 4_000_000) {
                $this->append($state['file'], $out);
                $out = '';
            }
            if (microtime(true) >= $deadline) {
                break;
            }
        }
        if ($state['table'] >= count($state['tables'])) {
            $out .= "SET FOREIGN_KEY_CHECKS = 1;\n-- end of backup\n";
            $state['stage'] = 'ready';
        }
        $this->append($state['file'], $out);
        $state['bytes'] = (int) @filesize($this->dir() . '/' . $state['file']);
        $this->save($state);
        return $state;
    }

    /** @return list<string> */
    private function primaryKey(string $table): array
    {
        return array_map('strval', $this->app->db()->column(
            'SELECT column_name FROM information_schema.key_column_usage WHERE table_schema = DATABASE() AND table_name = ? AND constraint_name = \'PRIMARY\' ORDER BY ordinal_position',
            [$table],
        ));
    }

    /**
     * The next rows after $after (the last primary key written), in key
     * order. A table with no primary key is read by offset instead.
     *
     * @param list<string> $keys
     * @param array<int|string, mixed> $after
     * @return array{0: list<array<string, mixed>>, 1: ?array<int|string, mixed>}
     */
    private function batch(string $table, array $keys, array $after): array
    {
        $db = $this->app->db();
        $q = '`' . str_replace('`', '``', $table) . '`';
        if ($keys === []) {
            $offset = (int) ($after['offset'] ?? 0);
            $rows = $db->all("SELECT * FROM $q LIMIT " . self::BATCH . ' OFFSET ' . $offset);
            return [$rows, ['offset' => $offset + count($rows)]];
        }
        $cols = implode(', ', array_map(fn ($c) => '`' . str_replace('`', '``', $c) . '`', $keys));
        if ($after === []) {
            $rows = $db->all("SELECT * FROM $q ORDER BY $cols LIMIT " . self::BATCH);
        } else {
            $marks = implode(', ', array_fill(0, count($keys), '?'));
            $rows = $db->all("SELECT * FROM $q WHERE ($cols) > ($marks) ORDER BY $cols LIMIT " . self::BATCH, array_values($after));
        }
        $lastRow = $rows === [] ? null : $rows[count($rows) - 1];
        $last = $lastRow === null ? null : array_map(fn ($k) => $lastRow[$k], $keys);
        return [$rows, $last];
    }

    private function append(string $file, string $text): void
    {
        if ($text === '') {
            return;
        }
        $gz = gzopen($this->dir() . "/$file", 'ab6');
        if ($gz === false) {
            throw new \RuntimeException('storage/tmp is not writable.');
        }
        gzwrite($gz, $text);
        gzclose($gz);
    }

    /** The finished file, or null. */
    public function path(string $id): ?string
    {
        $state = $this->state($id);
        if ($state === null || $state['stage'] !== 'ready') {
            return null;
        }
        $path = $this->dir() . '/' . $state['file'];
        return is_file($path) ? $path : null;
    }

    public function delete(string $id): void
    {
        $state = $this->state($id);
        if ($state !== null) {
            @unlink($this->dir() . '/' . $state['file']);
            @unlink($this->dir() . "/$id.json");
        }
    }
}

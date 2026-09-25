<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Applies numbered migration files, one per call, so the installer and
 * /admin/update can drive it a request at a time with a progress bar — a slow
 * host's timeout can't leave the schema half-applied and forgotten.
 *
 *   NNNN_name.sql  statements separated by ";" at a line end; {{table}} is the
 *                  prefixed table, {prefix} the bare prefix (for constraint
 *                  and index names, which share one namespace per database).
 *   NNNN_name.php  returns a callable(Db): void, for data that needs code.
 *
 * MySQL DDL is not transactional, so every file must be re-runnable (IF NOT
 * EXISTS, checks before ALTER). schema_migrations records a file only after
 * the whole of it succeeded; a file that died half way simply runs again.
 */
final class Migrator
{
    /** @var array<string, string> source name => directory */
    private array $sources = [];

    public function __construct(private readonly Db $db, string $coreDir)
    {
        $this->sources['core'] = rtrim($coreDir, '/');
    }

    public function addSource(string $name, string $dir): void
    {
        if (!preg_match('/^[a-z0-9_-]+$/', $name)) {
            throw new \InvalidArgumentException("Bad migration source: $name");
        }
        $this->sources[$name] = rtrim($dir, '/');
    }

    public function ensureTable(): void
    {
        $this->db->run(
            'CREATE TABLE IF NOT EXISTS {{schema_migrations}} (
                name VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                checksum CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                applied_at DATETIME(3) NOT NULL,
                PRIMARY KEY (name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
    }

    /** @return list<array{name: string, file: string}> every migration, in order */
    public function all(): array
    {
        $out = [];
        foreach ($this->sources as $source => $dir) {
            $files = glob($dir . '/[0-9][0-9][0-9][0-9]_*.{sql,php}', GLOB_BRACE) ?: [];
            sort($files, SORT_STRING);
            foreach ($files as $file) {
                $base = basename($file);
                $out[] = ['name' => $source === 'core' ? $base : "$source/$base", 'file' => $file];
            }
        }
        return $out;
    }

    /** @return list<string> */
    public function applied(): array
    {
        $this->ensureTable();
        return array_map('strval', $this->db->column('SELECT name FROM {{schema_migrations}} ORDER BY name'));
    }

    /** @return list<array{name: string, file: string}> */
    public function pending(): array
    {
        $done = array_flip($this->applied());
        return array_values(array_filter($this->all(), fn ($m) => !isset($done[$m['name']])));
    }

    /**
     * Applies the next pending migration.
     *
     * @return array{applied: ?string, remaining: int, total: int}
     */
    public function runNext(): array
    {
        $pending = $this->pending();
        $total = count($this->all());
        if ($pending === []) {
            return ['applied' => null, 'remaining' => 0, 'total' => $total];
        }
        $next = $pending[0];
        $this->apply($next['file']);
        $this->db->run(
            'INSERT INTO {{schema_migrations}} (name, checksum, applied_at) VALUES (?, ?, ?)',
            [$next['name'], hash_file('sha256', $next['file']), Db::now()],
        );
        return ['applied' => $next['name'], 'remaining' => count($pending) - 1, 'total' => $total];
    }

    /** Runs everything pending — for the CLI and tests, never a web request. */
    public function runAll(): int
    {
        $n = 0;
        while ($this->runNext()['applied'] !== null) {
            $n++;
        }
        return $n;
    }

    public function apply(string $file): void
    {
        if (str_ends_with($file, '.php')) {
            $fn = require $file;
            if (!is_callable($fn)) {
                throw new \RuntimeException("Migration $file must return a callable.");
            }
            $fn($this->db);
            return;
        }
        foreach (self::statements((string) file_get_contents($file)) as $sql) {
            $this->db->run(str_replace('{prefix}', $this->db->prefix(), $sql));
        }
    }

    /** @return list<string> */
    public static function statements(string $sql): array
    {
        $lines = array_filter(
            preg_split('/\R/', $sql) ?: [],
            fn ($line) => !preg_match('/^\s*--/', $line),
        );
        $parts = preg_split('/;\s*(?:\R|$)/', implode("\n", $lines)) ?: [];
        return array_values(array_filter(array_map('trim', $parts), fn ($s) => $s !== ''));
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Tools\Import;

use App\Core\App;
use App\Core\Db;
use App\Core\Id;

/**
 * The export from the Next.js deployment, read into this database a batch
 * at a time.
 *
 * Three phases, each resumable, because a shared host will not hold a
 * request open for a hundred thousand rows:
 *
 *   unpack — each model's newline-delimited JSON out of the zip and onto
 *     disk, so a later step can pick up at a byte offset;
 *   load — the rows, parents before children, foreign keys left on so a
 *     row pointing at something that is not there is reported rather than
 *     written;
 *   relink — the columns that point within their own table (a category's
 *     parent, a comment's reply), which are left null on the way in because
 *     the row they name may be further down the same file.
 *
 * Nothing is deleted and nothing is overwritten. A row whose id is already
 * here is counted as "already present" and left alone, which is what makes
 * running the import twice safe.
 */
final class Importer
{
    public const BUDGET_SECONDS = 12.0;
    public const BATCH = 200;
    /** Errors kept per model. Past a few, the next hundred say the same thing. */
    public const ERRORS_KEPT = 10;

    /** @var array<string, array<string, string>> asked of the database once per table */
    private array $columns = [];

    public function __construct(private readonly App $app, private readonly float $budget = self::BUDGET_SECONDS)
    {
    }

    public function dir(): string
    {
        return $this->app->paths->storage('tmp/imports');
    }

    /** @return array<string, mixed>|null */
    public function state(string $id): ?array
    {
        if (preg_match('/^[a-z0-9]{8,32}$/', $id) !== 1) {
            return null;
        }
        $state = json_decode((string) @file_get_contents($this->dir() . "/$id/state.json"), true);
        return is_array($state) ? $state : null;
    }

    /** @param array<string, mixed> $state */
    private function save(array $state): void
    {
        file_put_contents($this->dir() . '/' . $state['id'] . '/state.json', (string) json_encode($state, JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    private function archive(array $state): Archive
    {
        $dir = $this->dir() . '/' . $state['id'];
        return new Archive($dir . '/export.zip', $dir);
    }

    /**
     * Take the uploaded zip and work out what is in it.
     *
     * @param string $zipPath a file already on disk — the upload machinery
     *        put it there, and it is moved rather than copied
     * @return array<string, mixed>
     */
    public function start(string $zipPath): array
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('This host has no zip extension, so the export cannot be opened here.');
        }
        $id = Id::new();
        $dir = $this->dir() . "/$id";
        if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new \RuntimeException('storage/tmp is not writable.');
        }
        if (!@rename($zipPath, "$dir/export.zip") && !@copy($zipPath, "$dir/export.zip")) {
            throw new \RuntimeException('The uploaded file could not be moved into storage/tmp.');
        }
        $db = $this->app->db();
        $manifest = (new Archive("$dir/export.zip", $dir))->manifest();
        $models = self::models($db, array_keys($manifest['tables']));
        $state = [
            'id' => $id,
            'phase' => 'unpack',
            'models' => $models,
            'at' => 0,
            'offset' => 0,
            'exportedAt' => $manifest['exportedAt'],
            'expected' => $manifest['tables'],
            'counts' => [],
            'dropped' => [],
            'errors' => [],
            'skippedModels' => array_values(array_intersect(array_keys($manifest['tables']), array_keys(Mapping::SKIPPED))),
            'unknownModels' => array_values(array_diff(array_keys($manifest['tables']), array_keys(Mapping::TABLES))),
            'selfRefs' => Order::selfReferences($db),
            'started' => gmdate('c'),
        ];
        $this->save($state);
        return $state;
    }

    /**
     * The models to load, in an order that puts a row's parents first.
     *
     * Only what the export actually holds, and only what this site has a
     * table for: a model dropped from the schema and a model the old site
     * never used both come to "nothing to do", and both are named on the
     * screen.
     *
     * @param list<string> $inExport
     * @return list<string>
     */
    public static function models(Db $db, array $inExport): array
    {
        $tables = [];
        foreach ($inExport as $model) {
            $table = Mapping::tableFor($model);
            if ($table !== null && !isset(Mapping::SKIPPED[$model])) {
                $tables[$table] = $model;
            }
        }
        $models = [];
        foreach (Order::sort($db, array_keys($tables)) as $table) {
            $models[] = $tables[$table];
        }
        return $models;
    }

    /** @return array<string, mixed> the state after as much as fits in one request */
    public function step(string $id): array
    {
        $state = $this->state($id);
        if ($state === null) {
            throw new \RuntimeException('That import has gone; upload the export again.');
        }
        if ($state['phase'] === 'done') {
            return $state;
        }
        $deadline = microtime(true) + $this->budget;
        do {
            $state = match ($state['phase']) {
                'unpack' => $this->unpackStep($state),
                'load' => $this->loadStep($state),
                default => $this->relinkStep($state),
            };
        } while ($state['phase'] !== 'done' && microtime(true) < $deadline);
        if ($state['phase'] === 'done' && !isset($state['finished'])) {
            $state['finished'] = gmdate('c');
        }
        $this->save($state);
        return $state;
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function unpackStep(array $state): array
    {
        $models = $state['models'];
        if ($state['at'] >= count($models)) {
            $state['phase'] = 'load';
            $state['at'] = 0;
            $state['offset'] = 0;
            return $state;
        }
        $this->archive($state)->unpack((string) $models[$state['at']]);
        $state['at']++;
        if ($state['at'] >= count($models)) {
            $state['phase'] = 'load';
            $state['at'] = 0;
            $state['offset'] = 0;
        }
        return $state;
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function loadStep(array $state): array
    {
        $models = $state['models'];
        if ($state['at'] >= count($models)) {
            $state['phase'] = 'relink';
            $state['at'] = 0;
            $state['offset'] = 0;
            return $state;
        }
        $model = (string) $models[$state['at']];
        $table = (string) Mapping::tableFor($model);
        $batch = $this->archive($state)->read($model, (int) $state['offset'], self::BATCH);
        $count = $state['counts'][$model] ?? ['read' => 0, 'written' => 0, 'present' => 0, 'refused' => 0];
        $count['read'] += count($batch['rows']) + $batch['unreadable'];
        $count['refused'] += $batch['unreadable'];
        $columns = $this->columns($table);
        $selfRefs = $state['selfRefs'][$table] ?? [];
        foreach ($batch['rows'] as $old) {
            $made = Row::convert($model, is_array($old) ? $old : [], $columns);
            foreach ($made['dropped'] as $field) {
                if (!in_array($field, $state['dropped'][$model] ?? [], true)) {
                    $state['dropped'][$model][] = $field;
                }
            }
            // Filled by the relink phase: the row it names may be further
            // down this same file.
            foreach ($selfRefs as $column) {
                if (($made['row'][$column] ?? null) !== null) {
                    $made['row'][$column] = null;
                }
            }
            [$state, $count] = $this->write($state, $count, $model, $table, $made);
        }
        $state['counts'][$model] = $count;
        $state['offset'] = $batch['offset'];
        if ($batch['done'] && $batch['rows'] === []) {
            $state['at']++;
            $state['offset'] = 0;
        }
        if ($state['at'] >= count($models)) {
            $state['phase'] = 'relink';
            $state['at'] = 0;
            $state['offset'] = 0;
        }
        return $state;
    }

    /**
     * One row, and whatever its arrays fan out into.
     *
     * @param array<string, mixed> $state
     * @param array<string, int> $count
     * @param array{row: array<string, mixed>, fanout: array<string, list<string>>, dropped: list<string>} $made
     * @return array{0: array<string, mixed>, 1: array<string, int>}
     */
    private function write(array $state, array $count, string $model, string $table, array $made): array
    {
        $db = $this->app->db();
        $id = $made['row']['id'] ?? null;
        try {
            $db->insert($table, $made['row']);
            $count['written']++;
        } catch (\PDOException $e) {
            if (Db::isDuplicate($e)) {
                // Already here: a second run of the same export, or a row
                // that was put in by hand. Either way it is not ours to
                // change.
                $count['present']++;
                return [$state, $count];
            }
            $count['refused']++;
            if (count($state['errors'][$model] ?? []) < self::ERRORS_KEPT) {
                $state['errors'][$model][] = ['id' => is_string($id) ? $id : '?', 'message' => self::reason($e)];
            }
            return [$state, $count];
        }
        foreach ($made['fanout'] as $into => $values) {
            $spec = null;
            foreach (Mapping::FANOUT[$model] ?? [] as $one) {
                if ($one[0] === $into) {
                    $spec = $one;
                }
            }
            if ($spec === null || !is_string($id)) {
                continue;
            }
            foreach ($values as $value) {
                $db->run("INSERT IGNORE INTO {{{$into}}} (`{$spec[1]}`, `{$spec[2]}`) VALUES (?, ?)", [$id, $value]);
            }
        }
        return [$state, $count];
    }

    /**
     * Fill the columns that point within their own table, now that every
     * row exists.
     *
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function relinkStep(array $state): array
    {
        $models = array_values(array_filter(
            $state['models'],
            fn ($m) => ($state['selfRefs'][(string) Mapping::tableFor((string) $m)] ?? []) !== [],
        ));
        if ($state['at'] >= count($models)) {
            $state['phase'] = 'done';
            return $state;
        }
        $model = (string) $models[$state['at']];
        $table = (string) Mapping::tableFor($model);
        $columns = $this->columns($table);
        $batch = $this->archive($state)->read($model, (int) $state['offset'], self::BATCH);
        $db = $this->app->db();
        foreach ($batch['rows'] as $old) {
            $made = Row::convert($model, is_array($old) ? $old : [], $columns);
            $set = [];
            foreach ($state['selfRefs'][$table] as $column) {
                if (($made['row'][$column] ?? null) !== null) {
                    $set[$column] = $made['row'][$column];
                }
            }
            $id = $made['row']['id'] ?? null;
            if ($set === [] || !is_string($id)) {
                continue;
            }
            try {
                $db->update($table, $set, ['id' => $id]);
            } catch (\PDOException $e) {
                // A parent that never made it in. The row itself is here
                // and readable; it has simply lost its place in the tree.
                if (count($state['errors'][$model] ?? []) < self::ERRORS_KEPT) {
                    $state['errors'][$model][] = ['id' => $id, 'message' => self::reason($e)];
                }
            }
        }
        $state['offset'] = $batch['offset'];
        if ($batch['done'] && $batch['rows'] === []) {
            $state['at']++;
            $state['offset'] = 0;
        }
        if ($state['at'] >= count($models)) {
            $state['phase'] = 'done';
        }
        return $state;
    }

    /**
     * A table's columns and their types, asked of the database rather than
     * assumed, so a plugin's own table imports on the same rules.
     *
     * @return array<string, string>
     */
    public function columns(string $table): array
    {
        if (isset($this->columns[$table])) {
            return $this->columns[$table];
        }
        $db = $this->app->db();
        $rows = $db->all(
            'SELECT column_name AS name, data_type AS type FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = ?',
            [$db->prefix() . $table],
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['name']] = strtolower((string) $row['type']);
        }
        return $this->columns[$table] = $out;
    }

    /** The database's complaint, without the file and line nobody can act on. */
    public static function reason(\PDOException $e): string
    {
        $message = $e->getMessage();
        $at = strpos($message, ': ');
        return $at === false ? $message : trim(substr($message, $at + 2));
    }

    /**
     * What to show when it is over: every model in the export beside what
     * arrived, so a number that does not match is visible rather than
     * buried.
     *
     * @param array<string, mixed> $state
     * @return list<array{model: string, table: string, expected: int, written: int, present: int, refused: int, ok: bool}>
     */
    public static function report(array $state): array
    {
        $out = [];
        foreach ($state['expected'] as $model => $expected) {
            $count = $state['counts'][$model] ?? ['read' => 0, 'written' => 0, 'present' => 0, 'refused' => 0];
            $out[] = [
                'model' => (string) $model,
                'table' => Mapping::tableFor((string) $model) ?? '—',
                'expected' => (int) $expected,
                'written' => (int) $count['written'],
                'present' => (int) $count['present'],
                'refused' => (int) $count['refused'],
                'ok' => isset(Mapping::SKIPPED[$model])
                    || (int) $count['written'] + (int) $count['present'] === (int) $expected,
            ];
        }
        usort($out, fn (array $a, array $b) => [$a['ok'], $a['model']] <=> [$b['ok'], $b['model']]);
        return $out;
    }
}

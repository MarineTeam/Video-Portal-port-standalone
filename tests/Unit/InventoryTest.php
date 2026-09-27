<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Access\Capabilities;
use App\Modules\Plugins\Features;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * The map's inventories against the appendices of the brief they copy.
 *
 * The brief calls Appendix C the compatibility contract's list, and the map is
 * where this port answers C (addresses), B (models) and D (the original's test
 * files) row by row. A row quietly dropped, added or
 * re-methodded here would make the map agree with the code while both had
 * drifted from what was asked for — and the code is held to the map by
 * tests/Integration/RouteAuditTest.php, so this is the end of that chain that
 * nothing else covers.
 */
final class InventoryTest extends TestCase
{
    /** @return array{pages: array<string, list<string>>, routes: array<string, list<string>>} */
    private static function brief(): array
    {
        $out = ['pages' => [], 'routes' => []];
        $mode = '';
        foreach (file(dirname(__DIR__, 2) . '/PORT_PROMPT.md') ?: [] as $line) {
            if (str_contains($line, 'C.1 — Pages')) {
                $mode = 'pages';
                continue;
            }
            if (str_contains($line, 'C.2 — Routes')) {
                $mode = 'routes';
                continue;
            }
            if ($mode !== '' && str_starts_with($line, 'Appendix D')) {
                break;
            }
            $text = trim($line);
            if ($mode === '' || !str_starts_with($text, '/')) {
                continue;
            }
            $parts = preg_split('/\s+/', $text) ?: [];
            $path = (string) array_shift($parts);
            $out[$mode][$path] = $mode === 'pages'
                ? ['GET']
                : array_values(array_filter($parts, fn (string $m) => preg_match('/^[A-Z]+$/', $m) === 1));
        }
        return $out;
    }

    /** @return array{pages: array<string, list<string>>, routes: array<string, list<string>>} */
    private static function map(): array
    {
        $out = ['pages' => [], 'routes' => []];
        $table = '';
        foreach (file(dirname(__DIR__, 2) . '/docs/PORT_MAP.md') ?: [] as $line) {
            if (str_starts_with($line, '### ')) {
                // A sub-heading ends the table above it: the port's own
                // addresses are not the brief's.
                $table = '';
                continue;
            }
            if (str_starts_with($line, '## ')) {
                $table = preg_match('/^## (Pages|Routes) \(/', $line, $m) === 1 ? strtolower($m[1]) : '';
                continue;
            }
            if ($table === '' || !str_starts_with($line, '| `')) {
                continue;
            }
            $cells = array_map('trim', explode('|', trim($line, "| \n")));
            if (preg_match('/^`([^`]+)`$/', $cells[0], $m) !== 1) {
                continue;
            }
            $out[$table][$m[1]] = $table === 'pages'
                ? ['GET']
                : array_values(array_filter(preg_split('/\s+/', $cells[1] ?? '') ?: [], fn (string $x) => preg_match('/^[A-Z]+$/', $x) === 1));
        }
        return $out;
    }

    #[TestDox('the map lists every model of Appendix B, and names a table the schema really has')]
    public function testModels(): void
    {
        $brief = [];
        foreach (file(dirname(__DIR__, 2) . '/PORT_PROMPT.md') ?: [] as $line) {
            if (preg_match('/^model ([A-Za-z0-9_]+) \{/', $line, $m) === 1) {
                $brief[] = $m[1];
            }
        }
        $map = self::table('Models \(Appendix B\)');

        self::assertCount(95, $brief, 'the appendix says 95 models');
        self::assertSame([], array_values(array_diff($brief, array_keys($map))), 'in the brief, missing from the map');
        self::assertSame([], array_values(array_diff(array_keys($map), $brief)), 'in the map, not in the brief');

        $schema = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Migrations/0001_init.sql');
        foreach (glob(dirname(__DIR__, 2) . '/plugins/*/migrations/*.sql') ?: [] as $file) {
            $schema .= (string) file_get_contents($file);
        }
        preg_match_all('/CREATE TABLE IF NOT EXISTS \{\{(\w+)\}\}/', $schema, $found);
        $tables = array_flip($found[1]);
        $absent = [];
        foreach ($map as $model => $cells) {
            $table = preg_match('/^`([^`]+)`$/', $cells[0] ?? '', $m) === 1 ? $m[1] : '';
            if ($table !== '' && !isset($tables[$table])) {
                $absent[] = "$model names $table, which the schema does not create";
            }
        }

        self::assertSame([], $absent);
    }

    #[TestDox('every test file of Appendix D is answered, and every answer names a file that is there')]
    public function testTestFiles(): void
    {
        $brief = [];
        $inside = false;
        foreach (file(dirname(__DIR__, 2) . '/PORT_PROMPT.md') ?: [] as $line) {
            if (str_starts_with($line, 'Appendix D')) {
                $inside = true;
                continue;
            }
            if ($inside && str_starts_with($line, 'Appendix E')) {
                break;
            }
            if ($inside && preg_match('/^[a-z0-9\/_.-]+\.test\.tsx?$/', trim($line)) === 1) {
                $brief[trim($line)] = true;
            }
        }
        $map = self::table('Test files \(Appendix D\)');

        self::assertCount(73, $brief, 'the appendix says 73 files');
        self::assertSame([], array_values(array_diff(array_keys($brief), array_keys($map))), 'in the brief, missing from the map');
        self::assertSame([], array_values(array_diff(array_keys($map), array_keys($brief))), 'in the map, not in the brief');

        // A row that names a test must name one that exists — the failure this
        // catches is a table of reassuring paths that were never written.
        $wrong = [];
        foreach ($map as $original => $cells) {
            $status = strtolower($cells[0] ?? '');
            $notes = $cells[1] ?? '';
            preg_match_all('#tests/[\w./-]+\.(?:php|mjs)#', $notes, $named);
            foreach ($named[0] as $path) {
                if (!is_file(dirname(__DIR__, 2) . '/' . $path)) {
                    $wrong[] = "$original names $path, which is not there";
                }
            }
            if ($status === 'done' && $named[0] === []) {
                $wrong[] = "$original says done and names no test";
            }
            if ($status !== 'done' && trim($notes) === '') {
                $wrong[] = "$original says $status and gives no reason";
            }
        }

        self::assertSame([], $wrong);
    }

    #[TestDox('the bundled features are Appendix E.1 — the slugs a database already holds')]
    public function testFeatures(): void
    {
        $brief = self::briefText('E.1 — The 31 bundled features', 9000);
        preg_match_all('/\{ slug: "([^"]+)", name: "([^"]+)", description: "((?:[^"\\\\]|\\\\.)*)" \}/', $brief, $m, PREG_SET_ORDER);
        $want = [];
        foreach ($m as $row) {
            $want[$row[1]] = ['name' => $row[2], 'description' => str_replace('\\"', '"', $row[3])];
        }
        $have = [];
        foreach (Features::META as $row) {
            $have[$row['slug']] = ['name' => $row['name'], 'description' => $row['description']];
        }

        self::assertCount(31, $want, 'the appendix says 31 features');
        // A slug is what the plugins table stores, so it has to be the same
        // word an import from the original brings across.
        self::assertSame([], array_values(array_diff(array_keys($want), array_keys($have))), 'in the registry, missing here');
        self::assertSame([], array_values(array_diff(array_keys($have), array_keys($want))), 'here, not in the registry');

        $differ = [];
        foreach ($want as $slug => $row) {
            foreach (['name', 'description'] as $field) {
                if ($have[$slug][$field] === $row[$field]) {
                    continue;
                }
                // The one deliberate rewording: the original names Auth0, and
                // this port's sign-in provider is whatever the slot holds.
                if ($slug === 'profiles' && $field === 'description'
                    && $have[$slug][$field] === str_replace('Auth0', 'sign-in', $row[$field])) {
                    continue;
                }
                $differ[] = "$slug $field: the registry says \"{$row[$field]}\", this says \"{$have[$slug][$field]}\"";
            }
        }

        self::assertSame([], $differ);
    }

    #[TestDox('the capabilities are Appendix E.2 — the keys a permission group already holds')]
    public function testCapabilities(): void
    {
        $brief = self::briefText('E.2 — Capabilities', 6000);
        preg_match_all('/\{ key: "([^"]+)", label: "([^"]+)", hint: "((?:[^"\\\\]|\\\\.)*)" \}/', $brief, $m, PREG_SET_ORDER);
        $want = [];
        foreach ($m as $row) {
            $want[$row[1]] = ['label' => $row[2], 'hint' => str_replace('\\"', '"', $row[3])];
        }
        $have = Capabilities::all();

        self::assertCount(15, $want, 'the appendix says 15 capabilities');
        self::assertSame([], array_values(array_diff(array_keys($want), array_keys($have))), 'in the registry, missing here');
        self::assertSame([], array_values(array_diff(array_keys($have), array_keys($want))), 'here, not in the registry');

        $differ = [];
        foreach ($want as $key => $row) {
            foreach (['label', 'hint'] as $field) {
                if (($have[$key][$field] ?? null) !== $row[$field]) {
                    $differ[] = "$key $field: the registry says \"{$row[$field]}\", this says \"" . ($have[$key][$field] ?? '') . '"';
                }
            }
        }

        self::assertSame([], $differ);
    }

    #[TestDox('every cron path of Appendix F names a job this port schedules')]
    public function testScheduledJobs(): void
    {
        $brief = (string) file_get_contents(dirname(__DIR__, 2) . '/PORT_PROMPT.md');
        preg_match_all('#"path": "/api/cron/([a-z-]+)"#', $brief, $m);
        $want = array_values(array_unique($m[1]));

        $source = '';
        foreach (['app', 'plugins'] as $dir) {
            /** @var iterable<\SplFileInfo> $files */
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 2) . "/$dir"));
            foreach ($files as $file) {
                if ($file->getExtension() === 'php') {
                    $source .= (string) file_get_contents($file->getPathname());
                }
            }
        }
        preg_match_all("/->register\(\s*'([a-z0-9:_.-]+)'/", $source, $r);
        $registered = array_unique($r[1]);

        self::assertCount(8, $want, 'the appendix schedules eight');
        // /api/cron/<name> is an alias of /cron/run?job=<name>, so a name that
        // no longer matches is a cron line that quietly stops firing.
        self::assertSame([], array_values(array_diff($want, $registered)));
    }

    #[TestDox('the manifest is Appendix I.2, key for key')]
    public function testManifest(): void
    {
        $brief = self::briefText('I.2 — public/manifest.json', 4000);
        // Stop at the next section: I.3 is full of braces.
        $brief = substr($brief, 0, (int) strpos($brief, 'I.3 —'));
        $start = (int) strpos($brief, '{');
        $want = json_decode(substr($brief, $start, (int) strrpos($brief, '}') - $start + 1), true);
        $have = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/public/manifest.json'), true);

        self::assertIsArray($want);
        self::assertSame($want, $have, 'an installed app is identified by these; changing one re-installs it as a different app');
    }

    /** The brief from a heading onwards, for the registries quoted verbatim in it. */
    private static function briefText(string $heading, int $length): string
    {
        $brief = (string) file_get_contents(dirname(__DIR__, 2) . '/PORT_PROMPT.md');
        $at = strpos($brief, $heading);
        self::assertIsInt($at, "the brief still has $heading");
        return substr($brief, $at, $length);
    }

    /**
     * One of the map's tables, keyed by its first column.
     *
     * @return array<string, list<string>> the remaining cells of each row
     */
    private static function table(string $heading): array
    {
        $out = [];
        $inside = false;
        foreach (file(dirname(__DIR__, 2) . '/docs/PORT_MAP.md') ?: [] as $line) {
            if (preg_match('/^## ' . $heading . '/', $line) === 1) {
                $inside = true;
                continue;
            }
            if ($inside && str_starts_with($line, '## ')) {
                break;
            }
            if (!$inside || !str_starts_with($line, '| ')) {
                continue;
            }
            $cells = array_map('trim', explode('|', trim($line, "| \n")));
            $first = array_shift($cells);
            // The heading row and the rule under it are not rows.
            if (in_array($first, ['Model', 'Original', 'Path', '---'], true)) {
                continue;
            }
            if (preg_match('/^`([^`]+)`$/', (string) $first, $m) === 1) {
                $out[$m[1]] = $cells;
            } elseif (preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', (string) $first) === 1) {
                $out[(string) $first] = $cells;
            }
        }
        return $out;
    }

    #[TestDox('the map lists every page of Appendix C.1 and no page of its own')]
    public function testPages(): void
    {
        $brief = array_keys(self::brief()['pages']);
        $map = array_keys(self::map()['pages']);

        self::assertCount(91, $brief, 'the appendix says 91 pages');
        self::assertSame([], array_values(array_diff($brief, $map)), 'in the brief, missing from the map');
        self::assertSame([], array_values(array_diff($map, $brief)), 'in the map, not in the brief');
    }

    #[TestDox('the map lists every route of Appendix C.2, with the methods it asks for')]
    public function testRoutes(): void
    {
        $brief = self::brief()['routes'];
        $map = self::map()['routes'];

        self::assertCount(218, $brief, 'the appendix says 218 routes');
        self::assertSame([], array_values(array_diff(array_keys($brief), array_keys($map))), 'in the brief, missing from the map');
        self::assertSame([], array_values(array_diff(array_keys($map), array_keys($brief))), 'in the map, not in the brief');

        $differ = [];
        foreach ($brief as $path => $methods) {
            sort($methods);
            $mine = $map[$path];
            sort($mine);
            if ($methods !== $mine) {
                $differ[] = "$path: the brief asks for " . implode(' ', $methods) . ', the map says ' . implode(' ', $mine);
            }
        }

        self::assertSame([], $differ);
    }
}

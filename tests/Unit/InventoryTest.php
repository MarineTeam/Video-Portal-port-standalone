<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * The map's address tables against Appendix C of the brief they copy.
 *
 * The brief calls that appendix the compatibility contract's list, and the map
 * is where this port answers it row by row. A row quietly dropped, added or
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

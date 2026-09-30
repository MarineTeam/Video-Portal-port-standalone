<?php

declare(strict_types=1);

namespace Tests\Unit\Tools;

use App\Modules\Tools\Import\Export;
use App\Modules\Tools\Import\Mapping;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * The two ends of the migration agree on what a migration contains.
 *
 * One end is a Node script run on a laptop against the old deployment; the
 * other is PHP on the new host. They share nothing but the zip, so the list
 * of models and the name of the format exist twice, and a model added to
 * one and not the other is either a table silently left behind or a file
 * the importer never opens. Neither says anything at the time.
 */
final class ImportModelsTest extends TestCase
{
    private static function exporter(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/tools/export-from-nextjs/export.mjs');
    }

    /** @return list<string> */
    private static function listIn(string $js, string $pattern): array
    {
        self::assertSame(1, preg_match($pattern, $js, $m), "the exporter still declares this list");
        preg_match_all("/'([A-Za-z]+)'/", $m[1], $names);
        return $names[1];
    }

    #[TestDox('the exporter sends every model the importer knows, and no others')]
    public function testTheModelLists(): void
    {
        $sent = self::listIn(self::exporter(), '/const MODELS = \[(.*?)\];/s');
        $known = array_merge(
            array_keys(Mapping::TABLES),
            array_keys(Mapping::SKIPPED),
            array_keys(Mapping::FANOUT),
        );

        self::assertSame([], array_values(array_diff($sent, $known)), 'sent, but the importer has never heard of it');
        self::assertSame([], array_values(array_diff($known, $sent)), 'expected, but the exporter never sends it');
        self::assertGreaterThan(90, count($sent), 'the whole data model, not a handful');
    }

    #[TestDox('what the exporter refuses to send is what the importer expects not to get')]
    public function testTheCredentialsLeftBehind(): void
    {
        $never = self::listIn(self::exporter(), '/NEVER_EXPORTED = new Set\(\[(.*?)\]\)/s');
        sort($never);
        $skipped = array_keys(Mapping::SKIPPED);
        sort($skipped);
        self::assertSame($skipped, $never, 'both ends agree which models never travel');

        foreach (Mapping::SKIPPED as $model => $why) {
            self::assertNotSame('', trim($why), "$model says why, because the screen shows it");
        }
    }

    #[TestDox('both ends name the same format')]
    public function testTheFormat(): void
    {
        self::assertSame(
            1,
            preg_match("/const FORMAT = '([^']+)'/", self::exporter(), $m),
            'the exporter declares a format',
        );
        self::assertSame(Export::FORMAT, $m[1]);
    }
}

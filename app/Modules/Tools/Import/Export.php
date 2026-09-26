<?php

declare(strict_types=1);

namespace App\Modules\Tools\Import;

/**
 * What the export format is called, in one place.
 *
 * tools/export-from-nextjs/export.mjs writes this string into the manifest
 * and the importer refuses anything else, so somebody who points the screen
 * at last week's site backup is told what they picked rather than watching
 * it fail a table at a time.
 */
final class Export
{
    public const FORMAT = 'marine-team-nextjs-export/1';
}

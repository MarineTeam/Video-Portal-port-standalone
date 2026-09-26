<?php

declare(strict_types=1);

namespace App\Modules\Tools\Import;

/**
 * The export zip: a manifest and one newline-delimited JSON file per model.
 *
 * Entries are unpacked to plain files before anything is read from them.
 * A compressed zip entry cannot be seeked, so resuming a half-finished
 * table would mean re-reading every line before it — fine for a hundred
 * rows and quadratic for a hundred thousand. Unpacked, a step picks up at a
 * byte offset.
 */
final class Archive
{
    public const MANIFEST = 'manifest.json';

    public function __construct(private readonly string $zipPath, private readonly string $workDir)
    {
    }

    /**
     * The manifest, checked far enough to say whether this is one of ours.
     *
     * @return array{format: string, exportedAt: string, tables: array<string, int>}
     */
    public function manifest(): array
    {
        $zip = $this->open();
        $raw = $zip->getFromName(self::MANIFEST);
        $zip->close();
        if ($raw === false) {
            throw new \RuntimeException('That zip has no manifest.json, so it is not an export from the old site.');
        }
        $manifest = json_decode($raw, true);
        if (!is_array($manifest) || !is_array($manifest['tables'] ?? null)) {
            throw new \RuntimeException('The manifest in that zip is unreadable.');
        }
        $format = (string) ($manifest['format'] ?? '');
        if ($format !== Export::FORMAT) {
            throw new \RuntimeException("That export says it is \"$format\"; this importer reads \"" . Export::FORMAT . '".');
        }
        $tables = [];
        foreach ($manifest['tables'] as $model => $count) {
            if (is_string($model) && is_int($count)) {
                $tables[$model] = $count;
            }
        }
        return ['format' => $format, 'exportedAt' => (string) ($manifest['exportedAt'] ?? ''), 'tables' => $tables];
    }

    /** Where one model's rows are unpacked to. */
    public function file(string $model): string
    {
        return $this->workDir . '/' . preg_replace('/[^A-Za-z0-9]/', '', $model) . '.ndjson';
    }

    /**
     * Unpack one model's rows, in chunks, so a large table does not have to
     * fit in memory. A model the export has no entry for is not an error: an
     * old site with no small groups exports no small groups.
     *
     * @return int bytes written
     */
    public function unpack(string $model): int
    {
        $zip = $this->open();
        $from = $zip->getStream("$model.ndjson");
        if ($from === false) {
            $zip->close();
            file_put_contents($this->file($model), '');
            return 0;
        }
        $to = fopen($this->file($model), 'wb');
        if ($to === false) {
            fclose($from);
            $zip->close();
            throw new \RuntimeException('storage/tmp is not writable.');
        }
        $written = 0;
        while (!feof($from)) {
            $chunk = fread($from, 1 << 20);
            if ($chunk === false || $chunk === '') {
                break;
            }
            fwrite($to, $chunk);
            $written += strlen($chunk);
        }
        fclose($to);
        fclose($from);
        $zip->close();
        return $written;
    }

    /**
     * Rows from one unpacked file, starting at a byte offset.
     *
     * @return array{rows: list<array<string, mixed>>, offset: int, done: bool, unreadable: int}
     *         `offset` is where the next batch starts; a line that is not
     *         JSON is counted, not thrown, so one bad line does not end an
     *         import of a hundred thousand good ones.
     */
    public function read(string $model, int $offset, int $limit): array
    {
        $path = $this->file($model);
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return ['rows' => [], 'offset' => $offset, 'done' => true, 'unreadable' => 0];
        }
        fseek($handle, $offset);
        $rows = [];
        $unreadable = 0;
        while (count($rows) < $limit) {
            $line = fgets($handle);
            if ($line === false) {
                break;
            }
            // A final line with no newline is still a row; one that is only
            // whitespace is the file's trailing newline.
            $text = trim($line);
            if ($text === '') {
                continue;
            }
            $row = json_decode($text, true);
            if (is_array($row)) {
                $rows[] = $row;
            } else {
                $unreadable++;
            }
        }
        $offset = (int) ftell($handle);
        $done = feof($handle) || $offset >= (int) @filesize($path);
        fclose($handle);
        return ['rows' => $rows, 'offset' => $offset, 'done' => $done, 'unreadable' => $unreadable];
    }

    private function open(): \ZipArchive
    {
        $zip = new \ZipArchive();
        if ($zip->open($this->zipPath) !== true) {
            throw new \RuntimeException('That file is not a readable zip.');
        }
        return $zip;
    }
}

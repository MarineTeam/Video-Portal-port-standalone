<?php

declare(strict_types=1);

namespace App\Modules\Tools\Import;

use App\Core\App;
use App\Services\Files\FilesProvider;

/**
 * Files that are still in the old site's Bunny Storage, copied onto this
 * server a few at a time.
 *
 * The import itself does not move a byte: a file row points at the same
 * path it always did, and if this site's Files slot is also Bunny Storage
 * that is the end of it. When it is not — a church leaving Bunny along with
 * Next.js — the rows point at a place this site cannot serve from, and the
 * bytes have to follow. That is what this does.
 *
 * One file per step, streamed through a temporary file rather than held in
 * memory, because the point of the exercise is scanned hymnals. A file that
 * cannot be read is left exactly as it was, still pointing at Bunny, and
 * named on the screen: a row rewritten to point at bytes that never arrived
 * would be worse than one that still works.
 */
final class FilePull
{
    public const BUDGET_SECONDS = 12.0;

    public function __construct(private readonly App $app, private readonly float $budget = self::BUDGET_SECONDS)
    {
    }

    /** How many rows still point at Bunny Storage, and how many bytes that is. */
    public function pending(): array
    {
        $row = $this->app->db()->one(
            "SELECT COUNT(*) AS files, COALESCE(SUM(size_bytes), 0) AS bytes
               FROM {{file_assets}}
              WHERE backend = 'bunny' AND storage_path <> ''",
        );
        return ['files' => (int) ($row['files'] ?? 0), 'bytes' => (int) ($row['bytes'] ?? 0)];
    }

    /**
     * Whether this is worth offering at all: there are files in Bunny, and
     * this site is not itself on Bunny.
     */
    public function needed(): bool
    {
        return $this->app->services()->activeId('files') !== 'bunny' && $this->pending()['files'] > 0;
    }

    /**
     * As many files as fit in one request.
     *
     * @return array{moved: int, bytes: int, left: int, failures: list<array{id: string, title: string, why: string}>}
     */
    public function step(): array
    {
        $db = $this->app->db();
        $services = $this->app->services();
        $from = $services->get('files', 'bunny');
        $to = $services->active('files');
        if (!$from instanceof FilesProvider) {
            throw new \RuntimeException('Bunny Storage is not set up here, so there is nothing to read the old files with. Add it at Admin → Services first.');
        }
        if (!$to instanceof FilesProvider || $to::id() === 'bunny') {
            throw new \RuntimeException('This site already keeps its files in Bunny Storage; they are where they belong.');
        }
        $deadline = microtime(true) + $this->budget;
        $moved = 0;
        $bytes = 0;
        $failures = [];
        do {
            $file = $db->one(
                "SELECT id, title, storage_path FROM {{file_assets}}
                  WHERE backend = 'bunny' AND storage_path <> '' ORDER BY id LIMIT 1 OFFSET ?",
                [count($failures)],
            );
            if ($file === null) {
                break;
            }
            try {
                $bytes += $this->one($from, $to, (string) $file['id'], (string) $file['storage_path']);
                $moved++;
            } catch (\RuntimeException $e) {
                // Left pointing at Bunny, which still works, and counted so
                // the next query steps over it rather than retrying it for
                // the rest of the run.
                $failures[] = ['id' => (string) $file['id'], 'title' => (string) $file['title'], 'why' => $e->getMessage()];
            }
        } while (microtime(true) < $deadline);

        return ['moved' => $moved, 'bytes' => $bytes, 'left' => $this->pending()['files'], 'failures' => $failures];
    }

    /**
     * One file: read it out of Bunny, write it where this site keeps files,
     * and only then point the row at the copy.
     *
     * @return int bytes copied
     */
    private function one(FilesProvider $from, FilesProvider $to, string $id, string $path): int
    {
        $in = $from->open($path);
        if (!is_resource($in)) {
            throw new \RuntimeException('Bunny Storage would not give us that file.');
        }
        $temp = $this->app->paths->storage('tmp/pull-' . bin2hex(random_bytes(8)));
        $out = fopen($temp, 'wb');
        if ($out === false) {
            fclose($in);
            throw new \RuntimeException('storage/tmp is not writable.');
        }
        $bytes = (int) stream_copy_to_stream($in, $out);
        fclose($out);
        fclose($in);
        $object = self::objectName($id, $path);
        try {
            if ($bytes === 0) {
                throw new \RuntimeException('That file came back empty.');
            }
            $to->put($temp, $object);
        } catch (\Throwable $e) {
            @unlink($temp);
            throw $e instanceof \RuntimeException ? $e : new \RuntimeException($e->getMessage());
        }
        // Only once the bytes are there. A row pointing at a copy that does
        // not exist would be worse than one still pointing at Bunny.
        @unlink($temp);
        $this->app->db()->update('file_assets', ['backend' => $to::id(), 'storage_path' => $object], ['id' => $id]);
        return $bytes;
    }

    /**
     * What to call the copy.
     *
     * Not what Bunny called it: files were uploaded there under their own
     * names, and "Hymnal Scan (2019).pdf" is not a name a store here will
     * take. The port's own convention is the id and the extension, and
     * nothing outside the row reads this column, so renaming costs nothing.
     */
    public static function objectName(string $id, string $path): string
    {
        $extension = strtolower((string) preg_replace('/[^A-Za-z0-9]/', '', pathinfo($path, PATHINFO_EXTENSION)));
        if ($extension === '' || strlen($extension) > 8) {
            $extension = 'bin';
        }
        return 'files/' . strtolower((string) preg_replace('/[^A-Za-z0-9_-]/', '', $id)) . '.' . $extension;
    }
}

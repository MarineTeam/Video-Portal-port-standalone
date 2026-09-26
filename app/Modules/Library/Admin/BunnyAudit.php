<?php

declare(strict_types=1);

namespace App\Modules\Library\Admin;

use App\Core\App;
use App\Services\Files\BunnyStorageProvider;
use App\Services\Video\BunnyStreamProvider;

/**
 * What is at Bunny against what this site thinks is at Bunny.
 *
 * Two questions, and they are not the same question:
 *
 *   **Orphans** — objects and videos at Bunny that no row points at. Nobody
 *     can reach them and the church is paying to keep them. This is the
 *     common one: a file deleted here before the port, an upload that failed
 *     half way, a video somebody added from Bunny's own dashboard.
 *   **Missing** — rows here pointing at something that is not at Bunny. Every
 *     one of these is a broken download or a video that will not play, and
 *     nobody finds out until somebody taps it on a Sunday.
 *
 * Nothing is deleted from here. An orphan is somebody's mistake or somebody's
 * intention and a screen cannot tell which; the list is the useful part, and
 * the deleting is done at Bunny by a person who has looked.
 */
final class BunnyAudit
{
    /** Objects listed per walk. A zone with more than this is reported as partial. */
    public const MAX_OBJECTS = 5000;
    public const MAX_VIDEOS = 2000;

    public function __construct(private readonly App $app)
    {
    }

    /**
     * @return array{
     *     files: array{checked: bool, why: ?string, atBunny: int, orphans: list<array<string, mixed>>, missing: list<array<string, mixed>>, partial: bool},
     *     videos: array{checked: bool, why: ?string, atBunny: int, orphans: list<array<string, mixed>>, missing: list<array<string, mixed>>, partial: bool}
     * }
     */
    public function run(): array
    {
        return ['files' => $this->files(), 'videos' => $this->videos()];
    }

    /** @return array<string, mixed> */
    private function files(): array
    {
        $services = $this->app->services();
        $rows = $this->app->db()->all("SELECT id, title, storage_path FROM {{file_assets}} WHERE backend = 'bunny' AND storage_path <> ''");
        // A provider instance exists whether or not anybody filled its form
        // in, so the saved settings are what says it is set up. Asking Bunny
        // with an empty key would answer 401 and read as "every file is
        // gone", which is the worst possible way to be wrong here.
        $provider = $services->savedConfig('files', 'bunny') === [] ? null : $services->get('files', 'bunny');
        if (!$provider instanceof BunnyStorageProvider) {
            return self::notChecked('Bunny Storage is not set up here.', count($rows));
        }
        try {
            [$atBunny, $partial] = $this->walk($provider, '');
        } catch (\RuntimeException $e) {
            return self::notChecked($e->getMessage(), count($rows));
        }
        $known = [];
        $missing = [];
        foreach ($rows as $row) {
            $path = (string) $row['storage_path'];
            $known[$path] = true;
            if (!isset($atBunny[$path])) {
                $missing[] = ['id' => (string) $row['id'], 'title' => (string) $row['title'], 'path' => $path];
            }
        }
        $orphans = [];
        foreach ($atBunny as $path => $size) {
            if (!isset($known[$path])) {
                $orphans[] = ['path' => $path, 'bytes' => $size];
            }
        }
        // Biggest first: that is the order somebody clearing space wants.
        usort($orphans, fn (array $a, array $b) => $b['bytes'] <=> $a['bytes']);
        return [
            'checked' => true,
            'why' => null,
            'atBunny' => count($atBunny),
            'orphans' => $orphans,
            'missing' => $missing,
            'partial' => $partial,
        ];
    }

    /**
     * Every object in the zone, walked a folder at a time.
     *
     * Bunny lists one folder per request, so a deep zone is many requests;
     * the cap is there because an audit that never finishes is worse than one
     * that says it only got so far.
     *
     * @return array{0: array<string, int>, 1: bool} path => bytes, and whether the cap was hit
     */
    private function walk(BunnyStorageProvider $provider, string $dir): array
    {
        $found = [];
        $queue = [$dir];
        $partial = false;
        while ($queue !== []) {
            $next = array_shift($queue);
            foreach ($provider->list((string) $next) as $entry) {
                if (count($found) >= self::MAX_OBJECTS) {
                    return [$found, true];
                }
                if ($entry['isDirectory']) {
                    $queue[] = $entry['path'];
                    continue;
                }
                $found[$entry['path']] = $entry['size'];
            }
        }
        return [$found, $partial];
    }

    /** @return array<string, mixed> */
    private function videos(): array
    {
        $services = $this->app->services();
        $rows = $this->app->db()->all(
            "SELECT id, title, external_id FROM {{videos}} WHERE provider = 'bunny' AND deleted_at IS NULL AND external_id IS NOT NULL AND external_id <> ''",
        );
        $provider = $services->savedConfig('video', 'bunny') === [] ? null : $services->get('video', 'bunny');
        if (!$provider instanceof BunnyStreamProvider) {
            return self::notChecked('bunny.net Stream is not set up here.', count($rows));
        }
        $atBunny = [];
        $partial = false;
        try {
            for ($page = 1; ; $page++) {
                $batch = $provider->listLibrary($page);
                foreach ($batch as $video) {
                    $atBunny[$video['guid']] = $video['title'];
                }
                if (count($batch) < 100) {
                    break;
                }
                if (count($atBunny) >= self::MAX_VIDEOS) {
                    $partial = true;
                    break;
                }
            }
        } catch (\Throwable $e) {
            return self::notChecked($e->getMessage(), count($rows));
        }
        $known = [];
        $missing = [];
        foreach ($rows as $row) {
            $guid = (string) $row['external_id'];
            $known[$guid] = true;
            if (!isset($atBunny[$guid])) {
                $missing[] = ['id' => (string) $row['id'], 'title' => (string) $row['title'], 'guid' => $guid];
            }
        }
        $orphans = [];
        foreach ($atBunny as $guid => $title) {
            if (!isset($known[$guid])) {
                $orphans[] = ['guid' => $guid, 'title' => $title];
            }
        }
        return [
            'checked' => true,
            'why' => null,
            'atBunny' => count($atBunny),
            'orphans' => $orphans,
            'missing' => $missing,
            'partial' => $partial,
        ];
    }

    /** @return array<string, mixed> */
    private static function notChecked(string $why, int $rows): array
    {
        return ['checked' => false, 'why' => $why, 'atBunny' => 0, 'orphans' => [], 'missing' => [], 'rows' => $rows, 'partial' => false];
    }
}

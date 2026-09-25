<?php

declare(strict_types=1);

namespace App\Modules\Uploads;

use App\Core\ApiError;
use App\Core\App;
use App\Core\Db;
use App\Core\Id;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\Middleware;
use App\Modules\Files\UploadTypes;

/**
 * Uploads through PHP, in slices no larger than half the host's measured
 * upload_max_filesize, so a 2 MB limit is no limit. Each upload is addressed
 * by a random id and assembled only under storage/tmp/uploads/<id>/ — ".."
 * and absolute paths can't be expressed — size-capped by the admin's
 * setting, and swept after a day.
 *
 * Video bytes never come this way except for the host-disk video provider;
 * the other providers take the browser's upload directly.
 */
final class Uploads
{
    public const DEFAULT_MAX_BYTES = 2_147_483_648; // 2 GB; the admin can lower it

    public static function register(Router $r, App $app): void
    {
        $staff = Middleware::staff($app);
        $r->post('/api/uploads', fn (Request $req) => self::create($app, $req), [$staff]);
        $r->add(['PUT', 'POST'], '/api/uploads/[id]/chunk', fn (Request $req, array $p) => self::chunk($app, $req, $p['id']), [$staff]);
        $r->get('/api/uploads/[id]', fn (Request $req, array $p) => self::status($app, $p['id']), [$staff]);
    }

    /** The slice size the browser should use: half the host's limit, at most 8 MB. */
    public static function chunkSize(): int
    {
        $limit = min(self::iniBytes((string) ini_get('upload_max_filesize')), self::iniBytes((string) ini_get('post_max_size')));
        if ($limit <= 0) {
            $limit = 2 * 1024 * 1024;
        }
        return max(256 * 1024, min(8 * 1024 * 1024, intdiv($limit, 2)));
    }

    public static function iniBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return PHP_INT_MAX;
        }
        $unit = strtolower(substr($value, -1));
        $number = (int) $value;
        return match ($unit) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => (int) $value,
        };
    }

    private static function dir(App $app, string $id): string
    {
        if (!preg_match('/^[a-z0-9]{8,32}$/', $id)) {
            throw ApiError::notFound();
        }
        return $app->paths->storage('tmp/uploads/' . $id);
    }

    private static function create(App $app, Request $req): Response
    {
        $input = $req->input();
        $purpose = is_string($input['purpose'] ?? null) ? $input['purpose'] : '';
        $name = is_string($input['fileName'] ?? null) ? mb_substr($input['fileName'], 0, 255) : '';
        $size = is_int($input['size'] ?? null) ? $input['size'] : (int) ($input['size'] ?? -1);
        if (!isset(UploadTypes::PURPOSES[$purpose]) && $purpose !== 'plugin' && $purpose !== 'theme' && $purpose !== 'release' && $purpose !== 'import') {
            throw ApiError::invalid('Unknown upload purpose.');
        }
        if (in_array($purpose, ['plugin', 'theme', 'release', 'import'], true)) {
            if (!$app->currentUser()->can('manage_plugins')) {
                throw ApiError::forbidden();
            }
            if (UploadTypes::extensionOf($name) !== 'zip') {
                throw new ApiError('Only a .zip can be uploaded here.', 415);
            }
        } elseif (UploadTypes::uploadType($name, $purpose) === null) {
            throw new ApiError(UploadTypes::refusal($purpose), 415);
        }
        $max = (int) $app->settings()->get('uploads.max_bytes', self::DEFAULT_MAX_BYTES);
        if ($size <= 0 || $size > $max) {
            throw new ApiError('That file is larger than this site accepts (' . round($max / 1048576) . ' MB).', 413);
        }
        $id = Id::new();
        $dir = self::dir($app, $id);
        if (!@mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new \RuntimeException('storage/tmp is not writable.');
        }
        touch("$dir/data");
        $app->db()->insert('uploads', [
            'id' => $id,
            'user_id' => $app->currentUser()->id(),
            'purpose' => $purpose,
            'file_name' => $name,
            'size_bytes' => $size,
            'meta' => [],
        ]);
        return Response::json(['id' => $id, 'chunkSize' => self::chunkSize()], 201);
    }

    private static function row(App $app, string $id): array
    {
        $row = $app->db()->one('SELECT * FROM {{uploads}} WHERE id = ?', [$id]);
        if ($row === null || ($row['user_id'] !== null && $row['user_id'] !== $app->currentUser()->id())) {
            throw ApiError::notFound();
        }
        return $row;
    }

    private static function chunk(App $app, Request $req, string $id): Response
    {
        $row = self::row($app, $id);
        if ($row['status'] !== 'OPEN') {
            throw ApiError::conflict('This upload is already finished.');
        }
        $offset = (int) ($req->query('offset') ?? -1);
        $received = (int) $row['received_bytes'];
        if ($offset !== $received) {
            // The browser resumes from what the server says it holds.
            return Response::json(['received' => $received], 409);
        }
        $data = $req->body !== '' ? $req->body : (isset($req->files['chunk']['tmp_name']) ? (string) file_get_contents($req->files['chunk']['tmp_name']) : '');
        if ($data === '' || strlen($data) > self::chunkSize() * 2) {
            throw ApiError::invalid('Empty or oversized slice.');
        }
        if ($received + strlen($data) > (int) $row['size_bytes']) {
            throw new ApiError('More data than the file was said to hold.', 413);
        }
        $file = self::dir($app, $id) . '/data';
        $handle = fopen($file, 'c');
        if ($handle === false || !flock($handle, LOCK_EX)) {
            throw new \RuntimeException('Could not open the upload for writing.');
        }
        clearstatcache(true, $file);
        if (filesize($file) !== $received) {
            ftruncate($handle, $received);
        }
        fseek($handle, $received);
        fwrite($handle, $data);
        fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);
        $received += strlen($data);
        $complete = $received === (int) $row['size_bytes'];
        $app->db()->update('uploads', ['received_bytes' => $received, 'status' => $complete ? 'COMPLETE' : 'OPEN'], ['id' => $id]);
        return Response::json(['received' => $received, 'complete' => $complete]);
    }

    private static function status(App $app, string $id): Response
    {
        $row = self::row($app, $id);
        return Response::json(['id' => $id, 'received' => (int) $row['received_bytes'], 'size' => (int) $row['size_bytes'], 'status' => $row['status']]);
    }

    /**
     * Hands a finished upload to whoever asked for it: the assembled file's
     * path, its client name and purpose. The caller moves the file away; the
     * row is marked used so the same upload can't be finalised twice.
     *
     * @return array{path: string, fileName: string, purpose: string, size: int}
     */
    public static function take(App $app, string $id, string $purpose): array
    {
        $row = self::row($app, $id);
        if ($row['purpose'] !== $purpose || $row['status'] !== 'COMPLETE') {
            throw ApiError::invalid('That upload is not finished.');
        }
        $claimed = $app->db()->run('UPDATE {{uploads}} SET status = ? WHERE id = ? AND status = ?', ['USED', $id, 'COMPLETE'])->rowCount();
        if ($claimed !== 1) {
            throw ApiError::conflict('That upload has already been used.');
        }
        $path = self::dir($app, $id) . '/data';
        if (!is_file($path) || filesize($path) !== (int) $row['size_bytes']) {
            throw ApiError::invalid('The upload is incomplete.');
        }
        return ['path' => $path, 'fileName' => (string) $row['file_name'], 'purpose' => $purpose, 'size' => (int) $row['size_bytes']];
    }

    public static function discard(App $app, string $id): void
    {
        $dir = self::dir($app, $id);
        foreach (glob("$dir/*") ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);
        $app->db()->delete('uploads', ['id' => $id]);
    }

    /** The daily sweep: anything older than a day goes. */
    public static function sweep(App $app): int
    {
        $n = 0;
        foreach ($app->db()->column('SELECT id FROM {{uploads}} WHERE created_at < ?', [Db::datetime(new \DateTimeImmutable('-1 day'))]) as $id) {
            self::discard($app, (string) $id);
            $n++;
        }
        return $n;
    }
}

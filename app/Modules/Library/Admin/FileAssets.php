<?php

declare(strict_types=1);

namespace App\Modules\Library\Admin;

use App\Core\ApiError;
use App\Core\App;
use App\Core\Log;
use App\Services\Files\FilesProvider;

/** A file's bytes, wherever its backend keeps them. */
final class FileAssets
{
    /**
     * Removes the stored object behind a file row. A failure stops the purge
     * (the row stays in the trash to try again) rather than orphaning bytes
     * nobody could find afterwards.
     *
     * @param array<string, mixed> $file
     */
    public static function delete(App $app, array $file): void
    {
        $object = (string) ($file['storage_path'] ?? '');
        if ($object === '') {
            return;
        }
        $provider = $app->services()->get('files', (string) ($file['backend'] ?? 'local'));
        if (!$provider instanceof FilesProvider) {
            throw new ApiError('The service this file is stored with isn’t set up any more, so its bytes can’t be removed. Set it up again under Services, or leave the file in the trash.', 409, 'provider_missing');
        }
        try {
            $provider->delete($object);
        } catch (\Throwable $e) {
            Log::warning('File delete failed: ' . $e->getMessage());
            throw new ApiError('The file couldn’t be removed from storage: ' . $e->getMessage(), 502, 'provider_error');
        }
    }
}

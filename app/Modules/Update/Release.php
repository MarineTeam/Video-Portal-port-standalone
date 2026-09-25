<?php

declare(strict_types=1);

namespace App\Modules\Update;

use App\Core\App;
use App\Core\Id;
use App\Modules\Plugins\PackageInstaller;

/**
 * A release zip uploaded on /admin/update, applied in steps a request each:
 *
 *   prepare  the zip is inspected without extracting anything: no "..", no
 *            absolute paths, no symlinks, at most 30,000 entries and 500 MB;
 *            MANIFEST.sig must verify MANIFEST.json against the release key
 *            that ships in app/release-key.pub (Ed25519), and the zip must
 *            hold exactly the files the manifest lists. Nothing is written
 *            until all of that holds.
 *   extract  into storage/tmp/release/<id>/new, then every file's SHA-256 is
 *            checked against the manifest.
 *   swap     each unit the release owns (a top-level folder or file; each
 *            bundled plugin, the default theme, each entry under public/) is
 *            moved aside into storage/tmp/release/<id>/old and the new one
 *            moved in. storage/, third-party plugins and installed themes are
 *            never touched. A failure part way puts back what was moved.
 *   rollback puts the old units back, until the update is finished.
 *
 * The site is in maintenance mode from the swap until the update finishes.
 */
final class Release
{
    public const MAX_ENTRIES = 30_000;
    public const MAX_BYTES = 500 * 1024 * 1024;
    private const ID = '/^[a-z0-9]{8,32}$/';

    public function __construct(private readonly App $app)
    {
    }

    // Pure checks -----------------------------------------------------------------

    /** The shipped public key, or null when this copy was built without one. */
    public static function publicKey(string $file): ?string
    {
        if (!is_file($file)) {
            return null;
        }
        foreach (preg_split('/\R/', (string) file_get_contents($file)) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $key = base64_decode($line, true);
            return is_string($key) && strlen($key) === 32 ? $key : null;
        }
        return null;
    }

    public static function verifySignature(string $manifestJson, string $signatureB64, string $publicKey): bool
    {
        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            return false;
        }
        $signature = base64_decode(trim($signatureB64), true);
        if (!is_string($signature) || strlen($signature) !== 64 || strlen($publicKey) !== 32) {
            return false;
        }
        try {
            return sodium_crypto_sign_verify_detached($signature, $manifestJson, $publicKey);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array{version: string, files: array<string, string>}
     */
    public static function parseManifest(string $json): array
    {
        $data = json_decode($json, true);
        if (!is_array($data) || !is_string($data['version'] ?? null) || !preg_match('/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/', $data['version']) || !is_array($data['files'] ?? null)) {
            throw new \RuntimeException('MANIFEST.json is not a release manifest.');
        }
        $files = [];
        foreach ($data['files'] as $path => $hash) {
            if (!is_string($path) || !self::isSafePath($path) || !is_string($hash) || !preg_match('/^[a-f0-9]{64}$/', $hash)) {
                throw new \RuntimeException('MANIFEST.json lists a path or checksum that isn’t allowed.');
            }
            $files[$path] = $hash;
        }
        if ($files === []) {
            throw new \RuntimeException('MANIFEST.json lists no files.');
        }
        return ['version' => $data['version'], 'files' => $files];
    }

    public static function isSafePath(string $path): bool
    {
        if ($path === '' || str_starts_with($path, '/') || str_contains($path, '\\') || str_contains($path, "\0") || preg_match('/^[A-Za-z]:/', $path)) {
            return false;
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }
        return true;
    }

    /**
     * The units a release owns: what the swap replaces whole.
     *
     * @param list<string> $paths
     * @return list<string>
     */
    public static function units(array $paths): array
    {
        $units = [];
        foreach ($paths as $path) {
            $parts = explode('/', $path);
            $unit = match (true) {
                $parts[0] === 'storage' => null,
                in_array($parts[0], ['plugins', 'themes', 'public'], true) && count($parts) > 1 => $parts[0] . '/' . $parts[1],
                default => $parts[0],
            };
            if ($unit !== null) {
                $units[$unit] = true;
            }
        }
        $out = array_keys($units);
        sort($out, SORT_STRING);
        return $out;
    }

    /**
     * Inspects an open archive: returns the prefix every entry shares (a
     * release is usually zipped as one folder) and the files under it.
     *
     * @return array{prefix: string, files: list<string>}
     */
    public static function inspect(\ZipArchive $zip): array
    {
        if ($zip->numFiles > self::MAX_ENTRIES) {
            throw new \RuntimeException('The archive has too many entries to be a release.');
        }
        $total = 0;
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                throw new \RuntimeException('The archive is damaged.');
            }
            $name = (string) $stat['name'];
            $total += (int) $stat['size'];
            if ($total > self::MAX_BYTES) {
                throw new \RuntimeException('The archive unpacks to more than 500 MB.');
            }
            if ($zip->getExternalAttributesIndex($i, $opsys, $attr) && $opsys === \ZipArchive::OPSYS_UNIX && (($attr >> 16) & 0170000) === 0120000) {
                throw new \RuntimeException('The archive contains a symbolic link.');
            }
            if (!self::isSafePath(rtrim($name, '/'))) {
                throw new \RuntimeException('The archive contains a path that isn’t allowed.');
            }
            if (!str_ends_with($name, '/')) {
                $names[] = $name;
            }
        }
        $prefix = '';
        if (!in_array('MANIFEST.json', $names, true)) {
            $first = explode('/', $names[0] ?? '')[0];
            if ($first !== '' && in_array("$first/MANIFEST.json", $names, true)) {
                $prefix = $first . '/';
            }
        }
        $files = [];
        foreach ($names as $name) {
            if ($prefix !== '' && !str_starts_with($name, $prefix)) {
                throw new \RuntimeException('Everything in the archive must be inside its one folder.');
            }
            $files[] = substr($name, strlen($prefix));
        }
        return ['prefix' => $prefix, 'files' => $files];
    }

    // Steps -----------------------------------------------------------------------

    private function dir(string $id): string
    {
        if (!preg_match(self::ID, $id)) {
            throw new \InvalidArgumentException('Bad release id.');
        }
        return $this->app->paths->storage('tmp/release/' . $id);
    }

    /** @return array<string, mixed>|null */
    public function state(string $id): ?array
    {
        $file = $this->dir($id) . '/state.json';
        $state = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        return is_array($state) ? $state : null;
    }

    /** @param array<string, mixed> $state */
    private function save(string $id, array $state): void
    {
        file_put_contents($this->dir($id) . '/state.json', (string) json_encode($state, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), LOCK_EX);
    }

    /** @return array<string, mixed>|null the release in progress, if any */
    public function current(): ?array
    {
        foreach (glob($this->app->paths->storage('tmp/release') . '/*/state.json') ?: [] as $file) {
            $state = json_decode((string) file_get_contents($file), true);
            if (is_array($state) && in_array($state['stage'] ?? '', ['verified', 'extracted', 'swapped'], true)) {
                return $state;
            }
        }
        return null;
    }

    /** @return array<string, mixed> the new state */
    public function prepare(string $zipPath, ?string $publicKey): array
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('This host has no zip extension. Upload the release’s files by FTP instead, then come back to this page.');
        }
        if ($publicKey === null) {
            throw new \RuntimeException('This copy of the site has no release key (app/release-key.pub), so it can’t check a release zip. Upload the release’s files by FTP instead.');
        }
        if ($this->current() !== null) {
            throw new \RuntimeException('Another release is already part way through. Finish or discard it first.');
        }
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new \RuntimeException('That file is not a readable zip archive.');
        }
        try {
            ['prefix' => $prefix, 'files' => $files] = self::inspect($zip);
            $json = $zip->getFromName($prefix . 'MANIFEST.json');
            $sig = $zip->getFromName($prefix . 'MANIFEST.sig');
        } finally {
            $zip->close();
        }
        if (!is_string($json) || !is_string($sig)) {
            throw new \RuntimeException('That zip is not a signed release (MANIFEST.json and MANIFEST.sig are missing).');
        }
        if (!self::verifySignature($json, $sig, $publicKey)) {
            throw new \RuntimeException('The release’s signature does not match. It was not built by this software’s maintainers, or it was changed after it was built.');
        }
        $manifest = self::parseManifest($json);
        $listed = array_keys($manifest['files']);
        $inZip = array_values(array_diff($files, ['MANIFEST.json', 'MANIFEST.sig']));
        sort($listed, SORT_STRING);
        sort($inZip, SORT_STRING);
        if ($listed !== $inZip) {
            throw new \RuntimeException('The zip does not hold exactly the files its manifest lists.');
        }
        if (version_compare($manifest['version'], Updater::codeVersion(), '<')) {
            throw new \RuntimeException("That release is version {$manifest['version']}, older than this site's " . Updater::codeVersion() . '. Going back a version isn’t supported.');
        }
        $id = Id::new();
        $dir = $this->dir($id);
        if (!@mkdir($dir, 0770, true)) {
            throw new \RuntimeException('storage/tmp is not writable.');
        }
        if (!@rename($zipPath, "$dir/release.zip") && !@copy($zipPath, "$dir/release.zip")) {
            PackageInstaller::removeTree($dir);
            throw new \RuntimeException('storage/tmp is not writable.');
        }
        $state = [
            'id' => $id,
            'version' => $manifest['version'],
            'from' => Updater::codeVersion(),
            'prefix' => $prefix,
            'stage' => 'verified',
            // The manifest itself goes in too, so the next release knows
            // what this one installed and can remove what it drops.
            'units' => [...self::units($listed), 'MANIFEST.json', 'MANIFEST.sig'],
            'swapped' => [],
            'at' => gmdate('c'),
        ];
        $this->save($id, $state);
        return $state;
    }

    /** @return array<string, mixed> */
    public function extract(string $id): array
    {
        $state = $this->require($id, 'verified');
        $dir = $this->dir($id);
        PackageInstaller::removeTree("$dir/unpacked");
        $zip = new \ZipArchive();
        if ($zip->open("$dir/release.zip") !== true || !$zip->extractTo("$dir/unpacked")) {
            throw new \RuntimeException('The release could not be extracted.');
        }
        $zip->close();
        $root = rtrim("$dir/unpacked/" . $state['prefix'], '/');
        $manifest = self::parseManifest((string) file_get_contents("$root/MANIFEST.json"));
        foreach ($manifest['files'] as $path => $hash) {
            $file = "$root/$path";
            if (is_link($file) || !is_file($file) || !hash_equals($hash, (string) hash_file('sha256', $file))) {
                throw new \RuntimeException("$path doesn’t match the manifest; the upload may be damaged. Discard it and upload again.");
            }
        }
        $state['stage'] = 'extracted';
        $state['root'] = $root;
        $this->save($id, $state);
        @unlink("$dir/release.zip");
        return $state;
    }

    /** @return array<string, mixed> */
    public function swap(string $id): array
    {
        $state = $this->require($id, 'extracted');
        $root = $this->app->paths->root;
        $dir = $this->dir($id);
        $staged = (string) $state['root'];
        @mkdir("$dir/old", 0770, true);
        $state['dropped'] = self::dropped($root, $state['units']);
        $done = [];
        try {
            foreach ($state['dropped'] as $unit) {
                @mkdir(dirname("$dir/old/$unit"), 0770, true);
                self::move("$root/$unit", "$dir/old/$unit");
                $done[] = $unit;
            }
            foreach ($state['units'] as $unit) {
                $target = "$root/$unit";
                $aside = "$dir/old/$unit";
                if (file_exists($target) || is_link($target)) {
                    @mkdir(dirname($aside), 0770, true);
                    self::move($target, $aside);
                }
                @mkdir(dirname($target), 0775, true);
                self::move("$staged/$unit", $target);
                $done[] = $unit;
            }
        } catch (\Throwable $e) {
            $this->restore($dir, $root, $staged, $done, [...$state['dropped'], ...$state['units']]);
            throw new \RuntimeException('The new files could not be moved into place (' . $e->getMessage() . '). Everything was put back; the site’s folders may not be writable by PHP on this host — upload the release by FTP instead.');
        }
        $state['stage'] = 'swapped';
        $state['swapped'] = $done;
        $this->save($id, $state);
        Updater::clearCaches();
        return $state;
    }

    /** @return array<string, mixed> */
    public function rollback(string $id): array
    {
        $state = $this->require($id, 'swapped');
        $dir = $this->dir($id);
        $this->restore($dir, $this->app->paths->root, (string) $state['root'], $state['swapped'], [...($state['dropped'] ?? []), ...$state['units']]);
        $state['stage'] = 'rolled_back';
        $this->save($id, $state);
        Updater::clearCaches();
        return $state;
    }

    /**
     * Moves each swapped unit back to staging and its old copy back into place.
     *
     * @param list<string> $done
     * @param list<string> $units
     */
    private function restore(string $dir, string $root, string $staged, array $done, array $units): void
    {
        foreach (array_reverse($units) as $unit) {
            $target = "$root/$unit";
            $aside = "$dir/old/$unit";
            // A swapped unit goes back to staging (a dropped one has no new
            // copy at the target), then the old copy back into place.
            if (in_array($unit, $done, true) && file_exists($target) && !file_exists("$staged/$unit")) {
                @mkdir(dirname("$staged/$unit"), 0770, true);
                try {
                    self::move($target, "$staged/$unit");
                } catch (\Throwable) {
                }
            }
            if (file_exists($aside) && !file_exists($target)) {
                try {
                    self::move($aside, $target);
                } catch (\Throwable) {
                }
            }
        }
    }

    /**
     * Units the installed release had (by the MANIFEST.json it left at the
     * root) that the new one doesn't: moved aside with the rest, so a file
     * a release drops doesn't linger. Without an installed manifest (a
     * development checkout) nothing is dropped.
     *
     * @param list<string> $units
     * @return list<string>
     */
    public static function dropped(string $root, array $units): array
    {
        $file = "$root/MANIFEST.json";
        if (!is_file($file)) {
            return [];
        }
        try {
            $installed = self::parseManifest((string) file_get_contents($file));
        } catch (\RuntimeException) {
            return [];
        }
        $out = [];
        foreach (self::units(array_keys($installed['files'])) as $unit) {
            if (!in_array($unit, $units, true) && file_exists("$root/$unit")) {
                $out[] = $unit;
            }
        }
        return $out;
    }

    public function discard(string $id): void
    {
        $state = $this->state($id);
        if (($state['stage'] ?? null) === 'swapped') {
            throw new \RuntimeException('The new files are in place: finish the update, or roll it back first.');
        }
        PackageInstaller::removeTree($this->dir($id));
    }

    /** Called when the update finishes: the old copies are no longer needed. */
    public function complete(): void
    {
        foreach (glob($this->app->paths->storage('tmp/release') . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            PackageInstaller::removeTree($dir);
        }
    }

    /** @return array<string, mixed> */
    private function require(string $id, string $stage): array
    {
        $state = $this->state($id);
        if ($state === null) {
            throw new \RuntimeException('That release upload has gone; upload it again.');
        }
        if (($state['stage'] ?? null) !== $stage) {
            throw new \RuntimeException("That release is at the “{$state['stage']}” step, not “{$stage}”.");
        }
        return $state;
    }

    /** rename(), or copy then delete where storage/ is on another disk. */
    public static function move(string $from, string $to): void
    {
        if (@rename($from, $to)) {
            return;
        }
        if (is_dir($from) && !is_link($from)) {
            if (!@mkdir($to, 0775, true) && !is_dir($to)) {
                throw new \RuntimeException("can’t create $to");
            }
            foreach (scandir($from) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    self::move("$from/$entry", "$to/$entry");
                }
            }
            if (!@rmdir($from)) {
                throw new \RuntimeException("can’t remove $from");
            }
            return;
        }
        if (!@copy($from, $to) || !@unlink($from)) {
            throw new \RuntimeException("can’t move $from");
        }
    }
}

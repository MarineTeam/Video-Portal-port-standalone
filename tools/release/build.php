<?php

declare(strict_types=1);

/*
 * Development only: builds the release zip hosts upload.
 *
 *   composer install --no-dev --optimize-autoloader   (vendor/ is shipped)
 *   RELEASE_SIGNING_KEY=… php tools/release/build.php [--out build/marine-team-<version>.zip]
 *
 * The zip holds one folder, marine-team/, with everything a host needs and
 * nothing it doesn't: no tests, tools, CI, docs for contributors, or
 * anything in storage/ but its guard files. MANIFEST.json lists every file
 * with its SHA-256; MANIFEST.sig is the Ed25519 signature of MANIFEST.json
 * made with RELEASE_SIGNING_KEY. Without the key the zip is unsigned: it
 * installs by FTP, but /admin/update won't apply it.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$root = dirname(__DIR__, 2);
require $root . '/app/autoload.php';

$opts = getopt('', ['out:']);
$version = App\Core\App::VERSION;
$out = is_string($opts['out'] ?? null) ? $opts['out'] : "$root/build/marine-team-$version.zip";

/** Paths (relative, forward slashes) never shipped. */
function excluded(string $path): bool
{
    static $prefixes = ['.git/', '.github/', 'tests/', 'tools/', 'build/', 'node_modules/', '.phpunit.cache/', 'docs/', 'public/media/'];
    static $files = ['.gitignore', '.gitattributes', 'phpunit.xml', 'phpstan.neon', 'composer.lock', 'package.json', 'package-lock.json', 'PORT_PROMPT.md', 'MANIFEST.json', 'MANIFEST.sig', '.editorconfig'];
    foreach ($prefixes as $prefix) {
        if (str_starts_with($path, $prefix)) {
            return true;
        }
    }
    if (in_array($path, $files, true)) {
        return true;
    }
    if (str_starts_with($path, 'storage/')) {
        return !in_array($path, ['storage/.htaccess', 'storage/index.php'], true);
    }
    // Dev-only vendored packages never ship.
    return (bool) preg_match('#^vendor/(phpunit|phpstan|sebastian|theseer|myclabs|nikic|phar-io|bin)/#', $path);
}

$files = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    /** @var SplFileInfo $file */
    if ($file->isLink() || !$file->isFile()) {
        continue;
    }
    $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
    if (!excluded($rel)) {
        $files[$rel] = hash_file('sha256', $file->getPathname());
    }
}
ksort($files, SORT_STRING);
$manifest = json_encode(['name' => 'marine-team', 'version' => $version, 'built' => gmdate('c'), 'files' => $files], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";

$secret = getenv('RELEASE_SIGNING_KEY');
$signature = null;
if (is_string($secret) && $secret !== '') {
    $key = base64_decode($secret, true);
    if (!is_string($key) || strlen($key) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
        fwrite(STDERR, "RELEASE_SIGNING_KEY is not a base64 Ed25519 secret key.\n");
        exit(1);
    }
    $signature = base64_encode(sodium_crypto_sign_detached($manifest, $key)) . "\n";
}

@mkdir(dirname($out), 0775, true);
@unlink($out);
$zip = new ZipArchive();
if ($zip->open($out, ZipArchive::CREATE) !== true) {
    fwrite(STDERR, "Can't write $out\n");
    exit(1);
}
$zip->addEmptyDir('marine-team/storage');
foreach (array_keys($files) as $rel) {
    $zip->addFile("$root/$rel", "marine-team/$rel");
}
$zip->addFromString('marine-team/MANIFEST.json', $manifest);
if ($signature !== null) {
    $zip->addFromString('marine-team/MANIFEST.sig', $signature);
}
$zip->close();
printf("%s: %d files, version %s, %s\n", $out, count($files), $version, $signature === null ? 'UNSIGNED' : 'signed');

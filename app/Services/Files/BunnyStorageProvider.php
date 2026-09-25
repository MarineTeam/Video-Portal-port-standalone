<?php

declare(strict_types=1);

namespace App\Services\Files;

use App\Core\Http;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\BaseProvider;
use App\Services\TestContext;
use App\Services\TestResult;
use App\Services\Video\Bunny;

/**
 * Files in a Bunny Storage zone, read through its pull zone.
 *
 * With the pull zone's token authentication key set, a reader is sent to a
 * URL signed for ten minutes (bound to their address too, if chosen) and
 * the bytes never pass through PHP. Without it the zone would answer anyone
 * who has a file's address, so the site never hands one out: it proxies the
 * file, Range and all, and Admin → Services says that is what it is doing.
 *
 * The optional public podcast zone is a separate storage zone holding only
 * the audio an administrator published to a podcast — see PodcastMirror.
 */
final class BunnyStorageProvider extends BaseProvider implements FilesProvider
{
    public const SIGNED_TTL = 600;

    /** Where proxied bytes go: the response (default), or a test's collector. */
    private static ?\Closure $write = null;

    /** For tests: collect the proxied body instead of echoing it (and leave output buffers alone). */
    public static function writeUsing(?callable $write): void
    {
        self::$write = $write === null ? null : \Closure::fromCallable($write);
    }
    public const REGIONS = ['' => 'Falkenstein (default)', 'uk' => 'London', 'se' => 'Stockholm', 'ny' => 'New York', 'la' => 'Los Angeles', 'sg' => 'Singapore', 'syd' => 'Sydney', 'br' => 'São Paulo', 'jh' => 'Johannesburg'];

    public static function slot(): string
    {
        return 'files';
    }

    public static function id(): string
    {
        return 'bunny';
    }

    public static function label(): string
    {
        return 'Bunny Storage';
    }

    public static function requiresOutboundHttps(): bool
    {
        return true;
    }

    public static function limits(): string
    {
        return 'Needs outbound HTTPS. Turn on token authentication for the pull zone and add its key here: without it every file is proxied through this site, using its bandwidth.';
    }

    public static function configSchema(): array
    {
        return [
            ['key' => 'zone', 'label' => 'Storage zone name', 'type' => 'text', 'required' => true],
            ['key' => 'api_key', 'label' => 'Storage zone password', 'type' => 'password', 'secret' => true, 'required' => true, 'help' => 'Storage zone → FTP & API access → Password.'],
            ['key' => 'region', 'label' => 'Region', 'type' => 'select', 'options' => self::REGIONS, 'default' => ''],
            ['key' => 'pull_zone_host', 'label' => 'Pull zone hostname', 'type' => 'text', 'required' => true, 'help' => 'e.g. gracechurch-files.b-cdn.net'],
            ['key' => 'token_key', 'label' => 'Pull zone token authentication key', 'type' => 'password', 'secret' => true, 'help' => 'Strongly recommended: Pull zone → Security → Token authentication. Not the storage password.'],
            ['key' => 'bind_ip', 'label' => 'Bind signed links to the reader’s address', 'type' => 'toggle', 'default' => false, 'help' => 'Stops a link being passed on, but breaks for people whose address changes mid-read (mobile networks).'],
            ['key' => 'public_zone', 'label' => 'Public podcast storage zone (optional)', 'type' => 'text', 'help' => 'A separate zone holding only published podcast audio. Never the zone above.'],
            ['key' => 'public_api_key', 'label' => 'Public zone password', 'type' => 'password', 'secret' => true],
            ['key' => 'public_region', 'label' => 'Public zone region', 'type' => 'select', 'options' => self::REGIONS, 'default' => ''],
            ['key' => 'public_pull_zone_host', 'label' => 'Public zone pull zone hostname', 'type' => 'text', 'help' => 'Token authentication must stay off for this one.'],
        ];
    }

    // Addresses ------------------------------------------------------------------------------

    public static function storageHost(string $region): string
    {
        $region = strtolower(trim($region));
        return ($region === '' || $region === 'de' ? '' : $region . '.') . 'storage.bunnycdn.com';
    }

    /** Each path segment encoded; the object names this site makes need none of it, imported ones may. */
    public static function encodePath(string $object): string
    {
        return implode('/', array_map('rawurlencode', explode('/', ltrim($object, '/'))));
    }

    private function storageUrl(string $object, bool $public = false): string
    {
        $zone = $this->str($public ? 'public_zone' : 'zone');
        return 'https://' . self::storageHost($this->str($public ? 'public_region' : 'region')) . '/' . rawurlencode($zone) . '/' . self::encodePath($object);
    }

    /** @return array<string, string> */
    private function auth(bool $public = false): array
    {
        return ['AccessKey' => $this->str($public ? 'public_api_key' : 'api_key')];
    }

    public function cdnUrl(string $object): string
    {
        return 'https://' . $this->str('pull_zone_host') . '/' . self::encodePath($object);
    }

    public function signs(): bool
    {
        return $this->str('token_key') !== '';
    }

    /**
     * A pull-zone URL signed for $ttl seconds: Bunny's token authentication,
     * SHA-256 of key . path . expiry (. address when bound).
     */
    public static function signedUrl(string $url, string $key, int $expires, ?string $ip = null): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $token = rtrim(strtr(base64_encode(hash('sha256', $key . rawurldecode($path) . $expires . ($ip ?? ''), true)), '+/', '-_'), '=');
        return $url . (str_contains($url, '?') ? '&' : '?') . 'token=' . $token . '&expires=' . $expires;
    }

    // FilesProvider ----------------------------------------------------------------------------

    public function put(string $localPath, string $object): void
    {
        $this->upload($localPath, $object, false);
    }

    private function upload(string $localPath, string $object, bool $public): void
    {
        $r = Http::request('PUT', $this->storageUrl($object, $public), $this->auth($public) + ['Content-Type' => 'application/octet-stream', 'Accept' => 'application/json'], null, ['bodyFile' => $localPath, 'timeout' => 600]);
        if ($r->status !== 201 && !$r->ok()) {
            throw new \RuntimeException('Bunny Storage refused the upload (' . $r->status . ').');
        }
        @unlink($localPath);
    }

    public function delete(string $object): void
    {
        $r = Http::request('DELETE', $this->storageUrl($object), $this->auth());
        if (!$r->ok() && $r->status !== 404) {
            throw new \RuntimeException('Bunny Storage refused the delete (' . $r->status . ').');
        }
    }

    public function exists(string $object): bool
    {
        $dir = dirname($object) === '.' ? '' : dirname($object) . '/';
        foreach ($this->list($dir) as $entry) {
            if (!$entry['isDirectory'] && $entry['name'] === basename($object)) {
                return true;
            }
        }
        return false;
    }

    public function serve(string $object, Request $request, array $headers): Response
    {
        if ($this->signs()) {
            $expires = Bunny::expiry(self::SIGNED_TTL, time());
            $url = self::signedUrl($this->cdnUrl($object), $this->str('token_key'), $expires, (bool) $this->cfg('bind_ip', false) ? $request->ip : null);
            // The redirect is per person and per moment: nobody may cache it.
            return Response::redirect($url, 302)->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
        }
        return $this->proxy($object, $request, $headers);
    }

    /**
     * Streams the file through this site, forwarding Range and conditional
     * requests, with the pull zone's own status and length.
     *
     * @param array<string, string> $headers
     */
    private function proxy(string $object, Request $request, array $headers): Response
    {
        $forward = [];
        foreach (['range' => 'Range', 'if-none-match' => 'If-None-Match', 'if-modified-since' => 'If-Modified-Since'] as $in => $out) {
            $v = $request->header($in);
            if ($v !== null) {
                $forward[$out] = $v;
            }
        }
        $url = $this->cdnUrl($object);
        $method = $request->method === 'HEAD' ? 'HEAD' : 'GET';
        $write = self::$write;
        return Response::stream(static function () use ($url, $forward, $method, $write): void {
            if ($write === null) {
                // A file can be bigger than memory_limit: nothing may buffer it.
                while (ob_get_level() > 0) {
                    ob_end_clean();
                }
            }
            try {
                Http::request($method, $url, $forward, null, [
                    'timeout' => 3600,
                    'onHeaders' => static function (int $status, array $upstream): void {
                        if (headers_sent()) {
                            return;
                        }
                        // What the pull zone said is what the reader gets, bar our own type and disposition.
                        http_response_code($status === 0 ? 502 : $status);
                        foreach (['content-length', 'content-range', 'etag', 'last-modified', 'accept-ranges'] as $h) {
                            if (isset($upstream[$h])) {
                                header(ucwords($h, '-') . ': ' . $upstream[$h]);
                            }
                        }
                        if ($status >= 400) {
                            header_remove('Content-Disposition');
                        }
                    },
                    'sink' => static function (string $chunk) use ($write): void {
                        if ($write !== null) {
                            $write($chunk);
                            return;
                        }
                        echo $chunk;
                        flush();
                    },
                ]);
            } catch (HttpException) {
                if (!headers_sent()) {
                    http_response_code(502);
                }
            }
        }, 200, $headers + ['Accept-Ranges' => 'bytes']);
    }

    /** @return resource|null a temporary copy, for an export or a probe */
    public function open(string $object): mixed
    {
        $tmp = fopen('php://temp/maxmemory:8388608', 'w+b');
        if ($tmp === false) {
            return null;
        }
        try {
            $r = Http::request('GET', $this->storageUrl($object), $this->auth(), null, ['timeout' => 600, 'sink' => static function (string $chunk) use ($tmp): void {
                fwrite($tmp, $chunk);
            }]);
        } catch (HttpException) {
            fclose($tmp);
            return null;
        }
        if (!$r->ok()) {
            fclose($tmp);
            return null;
        }
        rewind($tmp);
        return $tmp;
    }

    // Listing, for the importer and "replace from storage" ---------------------------------------

    /**
     * One directory of the zone.
     *
     * @return list<array{name: string, path: string, isDirectory: bool, size: int, modified: ?string}>
     */
    public function list(string $dir = ''): array
    {
        $dir = trim($dir, '/');
        $r = Http::request('GET', $this->storageUrl($dir === '' ? '' : $dir . '/'), $this->auth() + ['Accept' => 'application/json']);
        if ($r->status === 404) {
            return [];
        }
        $d = $r->json();
        if (!$r->ok() || !is_array($d)) {
            throw new \RuntimeException('Bunny Storage didn’t list the folder (' . $r->status . ').');
        }
        $out = [];
        foreach ($d as $e) {
            if (!is_array($e) || !is_string($e['ObjectName'] ?? null)) {
                continue;
            }
            $out[] = [
                'name' => $e['ObjectName'],
                'path' => ltrim(($dir === '' ? '' : $dir . '/') . $e['ObjectName'], '/'),
                'isDirectory' => (bool) ($e['IsDirectory'] ?? false),
                'size' => (int) ($e['Length'] ?? 0),
                'modified' => isset($e['LastChanged']) ? (string) $e['LastChanged'] : null,
            ];
        }
        usort($out, fn ($a, $b) => [!$a['isDirectory'], strtolower($a['name'])] <=> [!$b['isDirectory'], strtolower($b['name'])]);
        return $out;
    }

    // The public podcast zone -------------------------------------------------------------------

    public function hasPublicZone(): bool
    {
        return $this->str('public_zone') !== '' && $this->str('public_api_key') !== '' && $this->str('public_pull_zone_host') !== '';
    }

    public function publicUrl(string $publicPath): string
    {
        return 'https://' . $this->str('public_pull_zone_host') . '/' . self::encodePath($publicPath);
    }

    /** Copies a private object into the public zone (through a temporary file: the zones are separate). */
    public function copyToPublic(string $object, string $publicPath): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'mtpod');
        if ($tmp === false) {
            throw new \RuntimeException('No temporary space for the copy.');
        }
        $out = fopen($tmp, 'wb');
        try {
            $r = Http::request('GET', $this->storageUrl($object), $this->auth(), null, ['timeout' => 600, 'sink' => static function (string $chunk) use ($out): void {
                fwrite($out, $chunk);
            }]);
            fclose($out);
            if (!$r->ok()) {
                throw new \RuntimeException('The private copy couldn’t be read (' . $r->status . ').');
            }
            $this->upload($tmp, $publicPath, true);
        } finally {
            @unlink($tmp);
        }
    }

    public function deletePublic(string $publicPath): void
    {
        $r = Http::request('DELETE', $this->storageUrl($publicPath, true), $this->auth(true));
        if (!$r->ok() && $r->status !== 404) {
            throw new \RuntimeException('The public zone refused the delete (' . $r->status . ').');
        }
    }

    // The test ---------------------------------------------------------------------------------

    public function test(TestContext $context): TestResult
    {
        $steps = [];
        if ($this->str('public_zone') !== '' && $this->str('public_zone') === $this->str('zone')) {
            return TestResult::fail('The public podcast zone must be a different storage zone: pointing it at this one would publish every file.');
        }
        $probe = '.marine-team-probe-' . bin2hex(random_bytes(6)) . '.txt';
        $tmp = (string) tempnam(sys_get_temp_dir(), 'mtprobe');
        file_put_contents($tmp, 'probe');
        try {
            try {
                $this->list('');
            } catch (\Throwable $e) {
                return TestResult::fail('Bunny Storage refused the zone name or password: ' . $e->getMessage());
            }
            $steps[] = 'Storage zone password accepted';
            $this->put($tmp, $probe);
            $steps[] = 'Wrote a test file';
            $plain = Http::request('GET', $this->cdnUrl($probe), [], null, ['timeout' => 15, 'maxBytes' => 1024]);
            if ($this->signs()) {
                $signed = Http::request('GET', self::signedUrl($this->cdnUrl($probe), $this->str('token_key'), time() + 300), [], null, ['timeout' => 15, 'maxBytes' => 1024]);
                if (!$signed->ok()) {
                    return TestResult::fail('The pull zone refused a signed link (' . $signed->status . '): check the token key and the hostname.', $steps);
                }
                if ($plain->ok()) {
                    return TestResult::fail('The pull zone serves files without a token: turn on token authentication for it, or remove the key here.', $steps);
                }
                $steps[] = 'Token authentication is on: files open only through signed links';
            } else {
                if (!$plain->ok()) {
                    return TestResult::fail('The pull zone didn’t serve the test file (' . $plain->status . '). Check the hostname, or add its token key if token authentication is on.', $steps);
                }
                $steps[] = 'Warning: without a token key every file is proxied through this site';
            }
            if ($this->hasPublicZone()) {
                $this->copyToPublic($probe, 'probe/' . $probe);
                $public = Http::request('GET', $this->publicUrl('probe/' . $probe), [], null, ['timeout' => 15, 'maxBytes' => 1024]);
                $this->deletePublic('probe/' . $probe);
                if (!$public->ok()) {
                    return TestResult::fail('The public podcast zone didn’t serve its test file (' . $public->status . '). Its token authentication must be off.', $steps);
                }
                $steps[] = 'The public podcast zone serves published audio';
            }
            return TestResult::ok($this->signs() ? 'Bunny Storage works, with signed links.' : 'Bunny Storage works, but files go through this site until token authentication is on.', $steps);
        } catch (\Throwable $e) {
            return TestResult::fail('The test failed: ' . $e->getMessage(), $steps);
        } finally {
            @unlink($tmp);
            try {
                $this->delete($probe);
            } catch (\Throwable) {
            }
        }
    }
}

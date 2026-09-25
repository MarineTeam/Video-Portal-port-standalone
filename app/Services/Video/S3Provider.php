<?php

declare(strict_types=1);

namespace App\Services\Video;

use App\Core\Http;
use App\Core\HttpException;
use App\Core\Url;
use App\Support\SigV4;
use App\Services\TestContext;
use App\Services\TestResult;

/**
 * S3-compatible storage: Cloudflare R2, Backblaze B2, Wasabi, AWS S3. The
 * browser PUTs straight to the bucket with presigned URLs (one PUT, or
 * multipart above 100 MB); playback is a fifteen-minute presigned GET minted
 * per request after the site has decided the viewer may watch — which is
 * what makes members-only real here — or the public address when the admin
 * says the bucket is public. No transcoding: upload a web-ready MP4.
 */
final class S3Provider extends BaseVideoProvider
{
    public const SINGLE_PUT_MAX = 100 * 1024 * 1024;
    public const PART_SIZE = 16 * 1024 * 1024;

    public static function id(): string
    {
        return 's3';
    }

    public static function label(): string
    {
        return 'S3-compatible storage (R2, B2, Wasabi, AWS)';
    }

    public static function requiresOutboundHttps(): bool
    {
        return true;
    }

    public static function limits(): string
    {
        return 'No transcoding: upload an MP4 that browsers can play (H.264/AAC). The bucket’s CORS must allow this site to PUT and to read the ETag header. Egress pricing varies — R2’s is free.';
    }

    public static function configSchema(): array
    {
        return [
            ['key' => 'endpoint', 'label' => 'Endpoint (https://…)', 'type' => 'text', 'required' => true, 'help' => 'R2: https://<account>.r2.cloudflarestorage.com · B2: https://s3.<region>.backblazeb2.com · AWS: https://s3.<region>.amazonaws.com'],
            ['key' => 'region', 'label' => 'Region', 'type' => 'text', 'default' => 'auto', 'help' => '“auto” for R2.'],
            ['key' => 'bucket', 'label' => 'Bucket', 'type' => 'text', 'required' => true],
            ['key' => 'accessKeyId', 'label' => 'Access key ID', 'type' => 'text', 'required' => true],
            ['key' => 'secretAccessKey', 'label' => 'Secret access key', 'type' => 'text', 'secret' => true, 'required' => true],
            ['key' => 'prefix', 'label' => 'Folder in the bucket', 'type' => 'text', 'default' => 'videos/'],
            ['key' => 'publicBaseUrl', 'label' => 'Public address or CDN (optional)', 'type' => 'text'],
            ['key' => 'publicBucket', 'label' => 'The bucket is public (skip signing playback)', 'type' => 'toggle'],
        ];
    }

    private function signer(): SigV4
    {
        return new SigV4($this->str('accessKeyId'), $this->str('secretAccessKey'), $this->str('region', 'auto'));
    }

    /** Path-style: https://endpoint/bucket/key — works on every S3-compatible service. */
    public function objectUrl(string $key): string
    {
        return rtrim($this->str('endpoint'), '/') . '/' . rawurlencode($this->str('bucket')) . '/' . SigV4::encodePath($key);
    }

    private function key(string $name): string
    {
        $prefix = trim($this->str('prefix', 'videos/'), '/');
        return ($prefix === '' ? '' : $prefix . '/') . $name;
    }

    public function capabilities(): VideoCapabilities
    {
        return new VideoCapabilities(upload: true, mp4: true, enforcesPrivacy: !(bool) $this->cfg('publicBucket', false), progressEvents: true);
    }

    public function createUpload(string $title, UploadHints $hints): UploadTicket
    {
        $ext = strtolower(pathinfo($hints->fileName, PATHINFO_EXTENSION));
        $name = bin2hex(random_bytes(12)) . '.' . (in_array($ext, ['mp4', 'm4v', 'mov', 'webm'], true) ? $ext : 'mp4');
        $key = $this->key($name);
        $type = str_starts_with($hints->mimeType, 'video/') ? $hints->mimeType : 'video/mp4';
        if ($hints->size <= self::SINGLE_PUT_MAX) {
            return new UploadTicket('put', $name, ['url' => $this->signer()->presign('PUT', $this->objectUrl($key), 3600), 'headers' => []], ['key' => $key, 'type' => $type]);
        }
        $url = $this->objectUrl($key) . '?uploads=';
        $r = Http::request('POST', $url, $this->signer()->sign('POST', $url, ['content-type' => $type]));
        if (!$r->ok() || !preg_match('#<UploadId>([^<]+)</UploadId>#', $r->body, $m)) {
            throw new VideoProviderException('The bucket wouldn’t start a multipart upload (' . $r->status . ').');
        }
        $uploadId = html_entity_decode($m[1], ENT_XML1);
        $parts = [];
        $count = (int) ceil($hints->size / self::PART_SIZE);
        for ($n = 1; $n <= $count; $n++) {
            $parts[] = ['number' => $n, 'url' => $this->signer()->presign('PUT', $this->objectUrl($key), 6 * 3600, ['partNumber' => (string) $n, 'uploadId' => $uploadId])];
        }
        return new UploadTicket('multipart', $name, ['partSize' => self::PART_SIZE, 'parts' => $parts], ['key' => $key, 'type' => $type, 'uploadId' => $uploadId]);
    }

    /** For multipart: $video->data['parts'] holds the ETags the browser read back. */
    public function completeUpload(VideoRef $video): VideoInfo
    {
        $key = (string) ($video->data['key'] ?? '');
        if (isset($video->data['uploadId'])) {
            $xml = '<CompleteMultipartUpload>';
            foreach ((array) ($video->data['parts'] ?? []) as $part) {
                $xml .= '<Part><PartNumber>' . (int) $part['number'] . '</PartNumber><ETag>' . htmlspecialchars((string) $part['etag'], ENT_XML1) . '</ETag></Part>';
            }
            $xml .= '</CompleteMultipartUpload>';
            $url = $this->objectUrl($key) . '?uploadId=' . rawurlencode((string) $video->data['uploadId']);
            $r = Http::request('POST', $url, $this->signer()->sign('POST', $url, ['content-type' => 'application/xml'], $xml), $xml);
            if (!$r->ok() || str_contains($r->body, '<Error>')) {
                throw new VideoProviderException('The bucket wouldn’t put the parts together (' . $r->status . ').');
            }
        }
        $url = $this->objectUrl($key);
        $head = Http::request('HEAD', $url, $this->signer()->sign('HEAD', $url));
        if (!$head->ok()) {
            throw new VideoProviderException('The file isn’t in the bucket (' . $head->status . '). The upload may have been refused by the bucket’s CORS settings.');
        }
        return new VideoInfo('READY');
    }

    public function owns(VideoRef $video): bool
    {
        return isset($video->data['key']);
    }

    public function delete(VideoRef $video): void
    {
        $url = $this->objectUrl((string) ($video->data['key'] ?? ''));
        $r = Http::request('DELETE', $url, $this->signer()->sign('DELETE', $url));
        if (!$r->ok() && $r->status !== 404) {
            throw new VideoProviderException('The bucket refused the delete (' . $r->status . ').');
        }
    }

    public function playbackUrl(VideoRef $video): string
    {
        $key = (string) ($video->data['key'] ?? '');
        if ((bool) $this->cfg('publicBucket', false) && $this->str('publicBaseUrl') !== '') {
            return rtrim($this->str('publicBaseUrl'), '/') . '/' . SigV4::encodePath($key);
        }
        return $this->signer()->presign('GET', $this->objectUrl($key), 900);
    }

    public function player(VideoRef $video, PlayerOptions $options): PlayerSpec
    {
        return PlayerSpec::native([['src' => $this->playbackUrl($video), 'type' => (string) ($video->data['type'] ?? 'video/mp4')]], isset($video->data['poster']) ? (string) $video->data['poster'] : null);
    }

    public function thumbnailUrl(VideoRef $video, ?string $file): ?string
    {
        return isset($video->data['poster']) ? (string) $video->data['poster'] : null;
    }

    public function mp4(VideoRef $video, int $maxHeight): Mp4Result
    {
        return ($video->data['type'] ?? 'video/mp4') === 'video/mp4' ? Mp4Result::ok($this->playbackUrl($video)) : Mp4Result::reason('not_supported');
    }

    /** The CORS rule the bucket needs, to paste into its settings. */
    public static function corsRule(string $origin): string
    {
        return (string) json_encode([['AllowedOrigins' => [$origin], 'AllowedMethods' => ['PUT', 'GET', 'HEAD'], 'AllowedHeaders' => ['*'], 'ExposeHeaders' => ['ETag'], 'MaxAgeSeconds' => 3600]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    public function test(TestContext $context): TestResult
    {
        foreach (['endpoint', 'bucket', 'accessKeyId', 'secretAccessKey'] as $field) {
            if ($this->str($field) === '') {
                return TestResult::fail('Fill in the ' . $field . '.');
            }
        }
        if (!str_starts_with($this->str('endpoint'), 'https://')) {
            return TestResult::fail('The endpoint must start with https://.');
        }
        $key = $this->key('probe-' . bin2hex(random_bytes(6)) . '.txt');
        $body = 'marine-team probe ' . gmdate('c');
        try {
            $put = Http::request('PUT', $this->signer()->presign('PUT', $this->objectUrl($key), 300), [], $body);
            if (!$put->ok()) {
                return TestResult::fail('A presigned PUT was refused (' . $put->status . '): ' . self::s3Error($put->body));
            }
            $get = Http::request('GET', $this->signer()->presign('GET', $this->objectUrl($key), 300));
            if (!$get->ok() || $get->body !== $body) {
                return TestResult::fail('The probe was written but a presigned GET didn’t read it back (' . $get->status . ').');
            }
            $url = $this->objectUrl($key);
            $del = Http::request('DELETE', $url, $this->signer()->sign('DELETE', $url));
            if (!$del->ok() && $del->status !== 204) {
                return TestResult::fail('The probe couldn’t be deleted (' . $del->status . '). The key needs delete permission too.');
            }
        } catch (HttpException $e) {
            return TestResult::fail('The endpoint couldn’t be reached: ' . $e->getMessage());
        }
        return TestResult::ok('Write, read and delete all work from this server. Uploads come from the browser, so the bucket’s CORS must also allow this site — paste this rule into the bucket’s CORS settings if uploads fail: ' . self::corsRule(Url::origin()));
    }

    private static function s3Error(string $xml): string
    {
        return preg_match('#<Message>([^<]+)</Message>#', $xml, $m) ? html_entity_decode($m[1], ENT_XML1) : 'no reason given';
    }
}

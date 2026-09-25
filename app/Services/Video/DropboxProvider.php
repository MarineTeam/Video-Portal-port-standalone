<?php

declare(strict_types=1);

namespace App\Services\Video;

use App\Core\Http;
use App\Services\TestContext;
use App\Services\TestResult;

/**
 * A Dropbox shared link, made direct (dl=1), checked to be a video that
 * answers Range requests, and played in this site's own <video>. Dropbox
 * doesn't transcode: the file must already be H.264/AAC MP4.
 *
 * Uploads are optional, through an app-folder app: PHP keeps the refresh
 * token and hands the admin's browser a four-hour access token for the
 * upload-session calls, to a path PHP chose; once the browser says it is
 * done, PHP checks the file is there and makes its public shared link.
 */
final class DropboxProvider extends BaseVideoProvider
{
    public static function id(): string
    {
        return 'dropbox';
    }

    public static function label(): string
    {
        return 'Dropbox shared link';
    }

    public static function limits(): string
    {
        return 'The link itself is the credential: anyone who has it can watch, members-only or not. Dropbox pauses links that pass its daily bandwidth (20 GB a day on Basic, 200 GB on paid plans). The file must already be an H.264/AAC MP4.';
    }

    public const API = 'https://api.dropboxapi.com';
    public const CONTENT = 'https://content.dropboxapi.com';

    public static function requiresOutboundHttps(): bool
    {
        return true;
    }

    public static function cspSources(): array
    {
        return ['connect' => [self::CONTENT]];
    }

    public static function configSchema(): array
    {
        return [
            ['key' => 'appKey', 'label' => 'App key (for uploads; an app-folder app)', 'type' => 'text', 'help' => 'Leave the three upload fields empty to use shared links only.'],
            ['key' => 'appSecret', 'label' => 'App secret', 'type' => 'text', 'secret' => true],
            ['key' => 'refreshToken', 'label' => 'Refresh token (offline access, from the app’s one-time consent)', 'type' => 'text', 'secret' => true],
        ];
    }

    private function canUpload(): bool
    {
        return $this->str('appKey') !== '' && $this->str('appSecret') !== '' && $this->str('refreshToken') !== '';
    }

    public function capabilities(): VideoCapabilities
    {
        return new VideoCapabilities(upload: $this->canUpload(), link: true, mp4: true, progressEvents: true);
    }

    /** A short-lived access token from the saved refresh token. */
    private function accessToken(): string
    {
        $r = Http::request('POST', self::API . '/oauth2/token', ['Content-Type' => 'application/x-www-form-urlencoded'], http_build_query([
            'grant_type' => 'refresh_token',
            'refresh_token' => $this->str('refreshToken'),
            'client_id' => $this->str('appKey'),
            'client_secret' => $this->str('appSecret'),
        ]));
        $token = $r->json()['access_token'] ?? null;
        if (!$r->ok() || !is_string($token)) {
            throw new VideoProviderException('Dropbox refused the saved consent (' . (string) ($r->json()['error_description'] ?? $r->json()['error'] ?? $r->status) . '). Give it again and save the new refresh token.');
        }
        return $token;
    }

    /**
     * One RPC call.
     *
     * @param array<string, mixed>|null $args
     * @return array{int, array<string, mixed>}
     */
    private function rpc(string $token, string $endpoint, ?array $args): array
    {
        $r = Http::request('POST', self::API . '/2/' . $endpoint, ['Authorization' => 'Bearer ' . $token] + ($args !== null ? ['Content-Type' => 'application/json'] : []), $args !== null ? (string) json_encode($args, JSON_UNESCAPED_SLASHES) : null);
        $d = $r->json();
        return [$r->status, is_array($d) ? $d : []];
    }

    public function createUpload(string $title, UploadHints $hints): UploadTicket
    {
        if (!$this->canUpload()) {
            parent::createUpload($title, $hints);
        }
        $ext = strtolower(pathinfo($hints->fileName, PATHINFO_EXTENSION));
        $name = \App\Support\Slug::slugify($title !== '' ? $title : pathinfo($hints->fileName, PATHINFO_FILENAME));
        // A path of this site's choosing, unique so the commit never renames it.
        $path = '/' . ($name !== '' ? mb_substr($name, 0, 80) . '-' : '') . bin2hex(random_bytes(4)) . '.' . (preg_match('/^[a-z0-9]{2,5}$/', $ext) ? $ext : 'mp4');
        return new UploadTicket('dropbox', substr(hash('sha256', 'dropbox-upload:' . $path), 0, 40), [
            'accessToken' => $this->accessToken(),
            'path' => $path,
            'content' => self::CONTENT,
        ], ['uploaded' => true, 'path' => $path]);
    }

    public function completeUpload(VideoRef $video): VideoInfo
    {
        $path = (string) ($video->data['path'] ?? '');
        if ($path === '' || ($video->data['uploaded'] ?? false) !== true) {
            return $this->get($video);
        }
        $token = $this->accessToken();
        [$status, $meta] = $this->rpc($token, 'files/get_metadata', ['path' => $path, 'include_media_info' => true]);
        if ($status !== 200 || ($meta['.tag'] ?? '') !== 'file') {
            throw new VideoProviderException('Dropbox has no finished upload at ' . $path . '.');
        }
        [$status, $link] = $this->rpc($token, 'sharing/create_shared_link_with_settings', ['path' => $path, 'settings' => ['requested_visibility' => 'public', 'audience' => 'public', 'access' => 'viewer']]);
        if ($status === 409 && isset($link['error']['shared_link_already_exists']['metadata']['url'])) {
            $link = $link['error']['shared_link_already_exists']['metadata'];
        } elseif ($status === 409) {
            throw new VideoProviderException('Dropbox wouldn’t make a public link (' . (string) ($link['error_summary'] ?? 'refused') . '). A team may forbid public links.');
        }
        $direct = Links::dropboxDirect((string) ($link['url'] ?? ''));
        if ($direct === null) {
            throw new VideoProviderException('Dropbox gave no shared link for the upload.');
        }
        $ms = $meta['media_info']['metadata']['duration'] ?? null;
        return new VideoInfo('READY', is_numeric($ms) ? (int) round((float) $ms / 1000) : null, data: ['url' => $direct, 'page' => (string) $link['url']]);
    }

    public function owns(VideoRef $video): bool
    {
        return ($video->data['uploaded'] ?? false) === true && isset($video->data['path']);
    }

    public function delete(VideoRef $video): void
    {
        if (!$this->owns($video) || !$this->canUpload()) {
            return;
        }
        [$status, $d] = $this->rpc($this->accessToken(), 'files/delete_v2', ['path' => (string) $video->data['path']]);
        if ($status !== 200 && !str_contains((string) ($d['error_summary'] ?? ''), 'not_found')) {
            throw new VideoProviderException('Dropbox refused the delete (' . (string) ($d['error_summary'] ?? $status) . ').');
        }
    }

    public function matchesLink(string $url): bool
    {
        return Links::dropboxDirect($url) !== null;
    }

    public function resolveLink(string $url): LinkedVideo
    {
        $direct = Links::dropboxDirect($url) ?? throw new VideoProviderException('That isn’t a Dropbox shared link.');
        $r = Http::fetchUntrusted('HEAD', $direct);
        $type = strtolower(trim(explode(';', (string) ($r->header('content-type') ?? ''))[0]));
        if (!$r->ok()) {
            throw new VideoProviderException($r->status === 429 || $r->status === 509
                ? 'Dropbox has paused that link for passing its bandwidth limit. Try again tomorrow.'
                : 'Dropbox didn’t serve that link (' . $r->status . '). Check that it is shared with anyone who has the link.');
        }
        if (!str_starts_with($type, 'video/') && $type !== 'application/octet-stream' && $type !== 'application/binary') {
            throw new VideoProviderException('That Dropbox file is ' . $type . ', not a video.');
        }
        $name = urldecode(basename((string) parse_url($direct, PHP_URL_PATH)));
        return new LinkedVideo(substr(hash('sha256', $direct), 0, 40), preg_replace('/\.[a-z0-9]{2,5}$/i', '', $name) ?: null, null, null, trim($url), ['url' => $direct]);
    }

    public function player(VideoRef $video, PlayerOptions $options): PlayerSpec
    {
        return PlayerSpec::native([['src' => (string) ($video->data['url'] ?? ''), 'type' => 'video/mp4']], isset($video->data['poster']) ? (string) $video->data['poster'] : null);
    }

    public function thumbnailUrl(VideoRef $video, ?string $file): ?string
    {
        return isset($video->data['poster']) ? (string) $video->data['poster'] : null;
    }

    public function mp4(VideoRef $video, int $maxHeight): Mp4Result
    {
        return Mp4Result::ok((string) ($video->data['url'] ?? ''));
    }

    public function test(TestContext $context): TestResult
    {
        if (!$this->canUpload()) {
            return TestResult::ok('Link-only: nothing to set up. Paste a shared link to a video.');
        }
        try {
            [$status, $account] = $this->rpc($this->accessToken(), 'users/get_current_account', null);
        } catch (VideoProviderException $e) {
            return TestResult::fail($e->getMessage());
        }
        if ($status !== 200) {
            return TestResult::fail('The token works but Dropbox refused the account lookup (' . $status . '). Give the app the account_info.read, files.content.write and sharing.write permissions.');
        }
        return TestResult::ok('Signed in to Dropbox as ' . (string) ($account['name']['display_name'] ?? $account['email'] ?? 'the app’s account') . '. Uploads go to the app’s folder.', ['Refresh token exchanged', 'Account read']);
    }
}

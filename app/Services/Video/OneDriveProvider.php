<?php

declare(strict_types=1);

namespace App\Services\Video;

use App\Core\Http;
use App\Services\TestContext;
use App\Services\TestResult;

/**
 * A OneDrive or SharePoint sharing link, encoded as a shares id (u! plus
 * base64url). Personal OneDrive resolves anonymously through
 * api.onedrive.com and streams from …/root/content. OneDrive for Business
 * and SharePoint need an Entra app with Files.Read.All: the player gets the
 * short-lived pre-authenticated download URL Graph mints per request, after
 * the site has decided the viewer may watch.
 *
 * Uploads are optional and go to one Business or SharePoint drive, with the
 * same app holding Files.ReadWrite.All: Graph's upload session gives the
 * browser a pre-authenticated URL to PUT ranges to, at a path PHP chose;
 * PHP then makes an anonymous view link, or says the tenant forbids them.
 * Personal OneDrive stays link-only (see PORT_MAP's deviations).
 */
final class OneDriveProvider extends BaseVideoProvider
{
    public static function id(): string
    {
        return 'onedrive';
    }

    public static function label(): string
    {
        return 'OneDrive / SharePoint shared link';
    }

    public static function requiresOutboundHttps(): bool
    {
        return true;
    }

    public static function limits(): string
    {
        return 'The link is the credential, so members-only can only hide the page here. Microsoft throttles files that are watched a lot, and a tenant can forbid anonymous links.';
    }

    public static function configSchema(): array
    {
        return [
            ['key' => 'tenantId', 'label' => 'Entra tenant ID (for Business / SharePoint links)', 'type' => 'text'],
            ['key' => 'clientId', 'label' => 'Application (client) ID', 'type' => 'text'],
            ['key' => 'clientSecret', 'label' => 'Client secret', 'type' => 'text', 'secret' => true],
            ['key' => 'testLink', 'label' => 'A Business sharing link to test with', 'type' => 'text'],
            ['key' => 'uploadDriveId', 'label' => 'Drive ID to upload to (optional; needs Files.ReadWrite.All)', 'type' => 'text', 'help' => 'A user’s OneDrive for Business or a SharePoint document library, from Graph (…/drives).'],
            ['key' => 'uploadFolder', 'label' => 'Folder in that drive', 'type' => 'text', 'default' => 'Videos'],
        ];
    }

    public static function cspSources(): array
    {
        // Upload sessions are pre-authenticated URLs on the tenant's SharePoint host.
        return ['connect' => ['https://*.sharepoint.com']];
    }

    private function canUpload(): bool
    {
        return $this->business() && preg_match('/^[A-Za-z0-9!_-]{8,200}$/', $this->str('uploadDriveId')) === 1;
    }

    private function driveBase(): string
    {
        return 'https://graph.microsoft.com/v1.0/drives/' . rawurlencode($this->str('uploadDriveId'));
    }

    /** A drive path, each segment encoded, for root:/…: addressing. */
    private static function encodePath(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', trim($path, '/'))));
    }

    private function business(): bool
    {
        return $this->str('tenantId') !== '' && $this->str('clientId') !== '' && $this->str('clientSecret') !== '';
    }

    public function capabilities(): VideoCapabilities
    {
        return new VideoCapabilities(upload: $this->canUpload(), link: true, thumbnails: true, duration: true, mp4: true, progressEvents: true);
    }

    public function matchesLink(string $url): bool
    {
        return Links::onedrive($url) !== null;
    }

    private function graphToken(): string
    {
        return \App\Core\Cache::memo('onedrive-token:' . hash('sha256', $this->str('tenantId') . '|' . $this->str('clientId') . '|' . $this->str('clientSecret')), fn () => $this->fetchGraphToken());
    }

    private function fetchGraphToken(): string
    {
        $r = Http::request('POST', 'https://login.microsoftonline.com/' . rawurlencode($this->str('tenantId')) . '/oauth2/v2.0/token', ['Content-Type' => 'application/x-www-form-urlencoded'], http_build_query([
            'client_id' => $this->str('clientId'),
            'client_secret' => $this->str('clientSecret'),
            'scope' => 'https://graph.microsoft.com/.default',
            'grant_type' => 'client_credentials',
        ]));
        $token = $r->json()['access_token'] ?? null;
        if (!$r->ok() || !is_string($token)) {
            throw new VideoProviderException('Microsoft refused the app’s credentials: ' . (string) ($r->json()['error_description'] ?? $r->status));
        }
        return $token;
    }

    /** @return array<string, mixed> the drive item */
    private function item(string $link, bool $business): array
    {
        $shares = Links::sharesId($link);
        if ($business) {
            if (!$this->business()) {
                throw new VideoProviderException('A SharePoint or OneDrive for Business link needs the Entra app settings for this provider.');
            }
            $r = Http::request('GET', 'https://graph.microsoft.com/v1.0/shares/' . $shares . '/driveItem?$select=name,size,video,file,@microsoft.graph.downloadUrl&$expand=thumbnails', ['Authorization' => 'Bearer ' . $this->graphToken()]);
        } else {
            $r = Http::request('GET', 'https://api.onedrive.com/v1.0/shares/' . $shares . '/root?expand=thumbnails');
        }
        $d = $r->json();
        if (!$r->ok() || !is_array($d)) {
            throw new VideoProviderException('Microsoft couldn’t open that link (' . (string) ($d['error']['message'] ?? $r->status) . '). Check it is shared with anyone who has the link.');
        }
        return $d;
    }

    public function resolveLink(string $url): LinkedVideo
    {
        $link = Links::onedrive($url) ?? throw new VideoProviderException('That isn’t a OneDrive or SharePoint link.');
        $d = $this->item($link['url'], $link['business']);
        if (!str_starts_with((string) ($d['file']['mimeType'] ?? 'video/'), 'video/')) {
            throw new VideoProviderException('That file isn’t a video.');
        }
        $ms = $d['video']['duration'] ?? null;
        return new LinkedVideo(
            substr(hash('sha256', $link['url']), 0, 40),
            preg_replace('/\.[a-z0-9]{2,5}$/i', '', (string) ($d['name'] ?? '')) ?: null,
            is_numeric($ms) ? (int) round((float) $ms / 1000) : null,
            isset($d['thumbnails'][0]['large']['url']) ? (string) $d['thumbnails'][0]['large']['url'] : null,
            $link['url'],
            ['link' => $link['url'], 'business' => $link['business'], 'mimeType' => (string) ($d['file']['mimeType'] ?? 'video/mp4')],
        );
    }

    public function createUpload(string $title, UploadHints $hints): UploadTicket
    {
        if (!$this->canUpload()) {
            parent::createUpload($title, $hints);
        }
        $ext = strtolower(pathinfo($hints->fileName, PATHINFO_EXTENSION));
        $name = \App\Support\Slug::slugify($title !== '' ? $title : pathinfo($hints->fileName, PATHINFO_FILENAME));
        $folder = trim($this->str('uploadFolder', 'Videos'), '/');
        // A path of this site's choosing, unique, so the upload never lands on another file.
        $path = ($folder !== '' ? $folder . '/' : '') . ($name !== '' ? $name . '-' : '') . bin2hex(random_bytes(4)) . '.' . (preg_match('/^[a-z0-9]{2,5}$/', $ext) ? $ext : 'mp4');
        $r = Http::request('POST', $this->driveBase() . '/root:/' . self::encodePath($path) . ':/createUploadSession', [
            'Authorization' => 'Bearer ' . $this->graphToken(),
            'Content-Type' => 'application/json',
        ], '{"item":{"@microsoft.graph.conflictBehavior":"fail"}}');
        $url = $r->json()['uploadUrl'] ?? null;
        if (!$r->ok() || !is_string($url) || !str_starts_with($url, 'https://')) {
            throw new VideoProviderException('Microsoft wouldn’t open an upload: ' . (string) ($r->json()['error']['message'] ?? $r->status));
        }
        return new UploadTicket('resumable', substr(hash('sha256', 'onedrive-upload:' . $this->str('uploadDriveId') . ':' . $path), 0, 40), ['url' => $url, 'method' => 'PUT'], ['uploaded' => true, 'path' => $path]);
    }

    public function completeUpload(VideoRef $video): VideoInfo
    {
        $path = (string) ($video->data['path'] ?? '');
        if (($video->data['uploaded'] ?? false) !== true || $path === '') {
            return $this->get($video);
        }
        $headers = ['Authorization' => 'Bearer ' . $this->graphToken()];
        $r = Http::request('GET', $this->driveBase() . '/root:/' . self::encodePath($path) . '?$select=id,file,video', $headers);
        $item = $r->json();
        if (!$r->ok() || !is_array($item) || !is_string($item['id'] ?? null)) {
            throw new VideoProviderException('Microsoft has no finished upload at ' . $path . '.');
        }
        $l = Http::request('POST', $this->driveBase() . '/items/' . rawurlencode($item['id']) . '/createLink', $headers + ['Content-Type' => 'application/json'], '{"type":"view","scope":"anonymous"}');
        $web = $l->json()['link']['webUrl'] ?? null;
        if (!$l->ok() || !is_string($web)) {
            throw new VideoProviderException($l->status === 403 || $l->status === 400
                ? 'The upload is in the drive, but this tenant forbids “anyone with the link” sharing, so it can’t be played here. Allow anonymous links for the site in the SharePoint admin centre, or share it another way.'
                : 'Microsoft wouldn’t make a link for the upload: ' . (string) ($l->json()['error']['message'] ?? $l->status));
        }
        $ms = $item['video']['duration'] ?? null;
        return new VideoInfo('READY', is_numeric($ms) ? (int) round((float) $ms / 1000) : null, data: [
            'link' => $web,
            'business' => Links::onedrive($web)['business'] ?? true,
            'itemId' => $item['id'],
            'mimeType' => (string) ($item['file']['mimeType'] ?? 'video/mp4'),
        ]);
    }

    public function owns(VideoRef $video): bool
    {
        return ($video->data['uploaded'] ?? false) === true && isset($video->data['itemId']);
    }

    public function delete(VideoRef $video): void
    {
        if (!$this->owns($video) || !$this->canUpload()) {
            return;
        }
        $r = Http::request('DELETE', $this->driveBase() . '/items/' . rawurlencode((string) $video->data['itemId']), ['Authorization' => 'Bearer ' . $this->graphToken()]);
        if (!$r->ok() && $r->status !== 404) {
            throw new VideoProviderException('Microsoft refused the delete: ' . (string) ($r->json()['error']['message'] ?? $r->status));
        }
    }

    private function streamUrl(VideoRef $video): string
    {
        if ($this->owns($video) && $this->canUpload()) {
            // Our own upload: the drive item's short-lived download address.
            $r = Http::request('GET', $this->driveBase() . '/items/' . rawurlencode((string) $video->data['itemId']) . '?$select=id,@microsoft.graph.downloadUrl', ['Authorization' => 'Bearer ' . $this->graphToken()]);
            $url = $r->json()['@microsoft.graph.downloadUrl'] ?? null;
            if (is_string($url)) {
                return $url;
            }
        }
        $link = (string) ($video->data['link'] ?? '');
        if (($video->data['business'] ?? false) === true) {
            $url = $this->item($link, true)['@microsoft.graph.downloadUrl'] ?? null;
            if (!is_string($url)) {
                throw new VideoProviderException('Microsoft gave no download address for that file.');
            }
            return $url;
        }
        return 'https://api.onedrive.com/v1.0/shares/' . Links::sharesId($link) . '/root/content';
    }

    public function player(VideoRef $video, PlayerOptions $options): PlayerSpec
    {
        return PlayerSpec::native([['src' => $this->streamUrl($video), 'type' => (string) ($video->data['mimeType'] ?? 'video/mp4')]]);
    }

    public function mp4(VideoRef $video, int $maxHeight): Mp4Result
    {
        try {
            return Mp4Result::ok($this->streamUrl($video));
        } catch (VideoProviderException) {
            return Mp4Result::reason('provider_error');
        }
    }

    public function test(TestContext $context): TestResult
    {
        if (!$this->business()) {
            return TestResult::ok('Personal OneDrive links need nothing. Business and SharePoint links need the Entra app settings.');
        }
        try {
            $this->graphToken();
            if ($this->canUpload()) {
                $r = Http::request('GET', $this->driveBase() . '?$select=id,name', ['Authorization' => 'Bearer ' . $this->graphToken()]);
                if (!$r->ok()) {
                    return TestResult::fail('The app signed in but can’t open the upload drive: ' . (string) ($r->json()['error']['message'] ?? $r->status) . '. Grant Files.ReadWrite.All and check the drive ID.');
                }
            }
            if ($this->str('testLink') !== '') {
                $this->item($this->str('testLink'), true);
                return TestResult::ok('The app signed in and opened the test link, so the permission and the consent are both in place.');
            }
        } catch (VideoProviderException $e) {
            return TestResult::fail($e->getMessage());
        }
        return TestResult::ok('The app signed in. Paste a Business sharing link in the test field to prove it can open files too.');
    }
}

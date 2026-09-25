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
        ];
    }

    private function business(): bool
    {
        return $this->str('tenantId') !== '' && $this->str('clientId') !== '' && $this->str('clientSecret') !== '';
    }

    public function capabilities(): VideoCapabilities
    {
        return new VideoCapabilities(link: true, thumbnails: true, duration: true, mp4: true, progressEvents: true);
    }

    public function matchesLink(string $url): bool
    {
        return Links::onedrive($url) !== null;
    }

    private function graphToken(): string
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

    private function streamUrl(VideoRef $video): string
    {
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

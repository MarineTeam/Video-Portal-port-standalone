<?php

declare(strict_types=1);

namespace Tests\Unit\Video;

use App\Core\Cache;
use App\Core\Http;
use App\Core\HttpResponse;
use App\Core\Url;
use App\Services\TestContext;
use App\Services\Video\DropboxProvider;
use App\Services\Video\GoogleDriveProvider;
use App\Services\Video\OneDriveProvider;
use App\Services\Video\UploadHints;
use App\Services\Video\VideoProviderException;
use App\Services\Video\VideoRef;
use PHPUnit\Framework\TestCase;

/**
 * The optional uploads of the three shared-link providers, against the
 * answers Dropbox, Drive and Graph document: a ticket that carries no
 * long-lived credential, then a public link made once the file is there.
 */
final class CloudUploadsTest extends TestCase
{
    /** @var list<array{0: string, 1: string, 2: array<string, string>, 3: ?string}> */
    private array $requests = [];

    protected function setUp(): void
    {
        Cache::configure(null);
        Cache::forgetMemo();
        Url::configure('https://church.example.org');
    }

    protected function tearDown(): void
    {
        Http::fake(null);
        Url::configure('http://localhost');
    }

    /** @param array<string, HttpResponse|callable> $routes "METHOD url-prefix" => response */
    private function fake(array $routes): void
    {
        $this->requests = [];
        Http::fake(function (string $method, string $url, array $headers, ?string $body) use ($routes): HttpResponse {
            $this->requests[] = [$method, $url, $headers, $body];
            foreach ($routes as $key => $response) {
                [$m, $prefix] = explode(' ', $key, 2);
                if ($m === $method && str_starts_with($url, $prefix)) {
                    return is_callable($response) ? $response($url, $headers, $body) : $response;
                }
            }
            return new HttpResponse(599, [], 'no fixture for ' . $method . ' ' . $url);
        });
    }

    private static function json(mixed $data, int $status = 200, array $headers = []): HttpResponse
    {
        return new HttpResponse($status, $headers + ['content-type' => 'application/json'], (string) json_encode($data));
    }

    /** @return list<string> "METHOD url" of each request */
    private function sent(): array
    {
        return array_map(fn ($r) => $r[0] . ' ' . $r[1], $this->requests);
    }

    // Dropbox ------------------------------------------------------------------------------------

    private static function dropbox(): DropboxProvider
    {
        return new DropboxProvider(['appKey' => 'k', 'appSecret' => 's', 'refreshToken' => 'rt']);
    }

    public function test_dropbox_without_app_settings_stays_link_only(): void
    {
        $p = new DropboxProvider([]);
        self::assertFalse($p->capabilities()->upload);
        self::assertTrue($p->test(new TestContext('a@example.org', true, 'https://church.example.org'))->ok);
        $this->expectException(VideoProviderException::class);
        $p->createUpload('Sermon', new UploadHints('sermon.mp4', 10));
    }

    public function test_dropbox_hands_the_browser_a_short_token_and_a_path_of_its_own(): void
    {
        $this->fake(['POST https://api.dropboxapi.com/oauth2/token' => self::json(['access_token' => 'sl.short', 'expires_in' => 14400])]);
        $ticket = self::dropbox()->createUpload('Easter Sunday: “He is risen”', new UploadHints('IMG_0001.MOV', 1000, 'video/quicktime'));
        self::assertSame('dropbox', $ticket->kind);
        self::assertSame('sl.short', $ticket->client['accessToken']);
        self::assertMatchesRegularExpression('#^/easter-sunday-he-is-risen-[0-9a-f]{8}\.mov$#', $ticket->client['path']);
        self::assertArrayNotHasKey('refreshToken', $ticket->client);
        self::assertSame(['uploaded' => true, 'path' => $ticket->client['path']], $ticket->data);
        self::assertSame(40, strlen($ticket->id));
        self::assertStringContainsString('grant_type=refresh_token', (string) $this->requests[0][3]);
    }

    public function test_dropbox_completes_with_a_public_direct_link(): void
    {
        $this->fake([
            'POST https://api.dropboxapi.com/oauth2/token' => self::json(['access_token' => 'sl.short']),
            'POST https://api.dropboxapi.com/2/files/get_metadata' => self::json(['.tag' => 'file', 'path_lower' => '/sermon-1a2b3c4d.mp4', 'media_info' => ['.tag' => 'metadata', 'metadata' => ['.tag' => 'video', 'duration' => 1805400]]]),
            'POST https://api.dropboxapi.com/2/sharing/create_shared_link_with_settings' => self::json(['error_summary' => 'shared_link_already_exists/metadata/..', 'error' => ['.tag' => 'shared_link_already_exists', 'shared_link_already_exists' => ['.tag' => 'metadata', 'metadata' => ['url' => 'https://www.dropbox.com/scl/fi/abc123/sermon-1a2b3c4d.mp4?rlkey=xyz&dl=0']]]], 409),
        ]);
        $info = self::dropbox()->completeUpload(new VideoRef('h', ['uploaded' => true, 'path' => '/sermon-1a2b3c4d.mp4']));
        self::assertSame('READY', $info->status);
        self::assertSame(1805, $info->durationSeconds);
        self::assertStringStartsWith('https://', (string) $info->data['url']);
        self::assertStringNotContainsString('dl=0', (string) $info->data['url']);
        self::assertSame('{"path":"/sermon-1a2b3c4d.mp4","include_media_info":true}', $this->requests[1][3]);
    }

    public function test_dropbox_refuses_to_complete_an_upload_that_isn_t_there(): void
    {
        $this->fake([
            'POST https://api.dropboxapi.com/oauth2/token' => self::json(['access_token' => 'sl.short']),
            'POST https://api.dropboxapi.com/2/files/get_metadata' => self::json(['error_summary' => 'path/not_found/..'], 409),
        ]);
        $this->expectException(VideoProviderException::class);
        self::dropbox()->completeUpload(new VideoRef('h', ['uploaded' => true, 'path' => '/nothing.mp4']));
    }

    public function test_dropbox_deletes_only_what_it_uploaded(): void
    {
        $this->fake([
            'POST https://api.dropboxapi.com/oauth2/token' => self::json(['access_token' => 'sl.short']),
            'POST https://api.dropboxapi.com/2/files/delete_v2' => self::json(['error_summary' => 'path_lookup/not_found/'], 409),
        ]);
        self::dropbox()->delete(new VideoRef('h', ['url' => 'https://dl.dropboxusercontent.com/s/x/y.mp4']));
        self::assertSame([], $this->requests, 'a pasted link is left alone');
        self::dropbox()->delete(new VideoRef('h', ['uploaded' => true, 'path' => '/gone.mp4']));
        self::assertContains('POST https://api.dropboxapi.com/2/files/delete_v2', $this->sent());
    }

    public function test_dropbox_test_reads_the_account(): void
    {
        $this->fake([
            'POST https://api.dropboxapi.com/oauth2/token' => self::json(['access_token' => 'sl.short']),
            'POST https://api.dropboxapi.com/2/users/get_current_account' => self::json(['name' => ['display_name' => 'Grace Media']]),
        ]);
        $r = self::dropbox()->test(new TestContext('a@example.org', true, 'https://church.example.org'));
        self::assertTrue($r->ok);
        self::assertStringContainsString('Grace Media', $r->message);

        $this->fake(['POST https://api.dropboxapi.com/oauth2/token' => self::json(['error' => 'invalid_grant'], 400)]);
        self::assertFalse(self::dropbox()->test(new TestContext('a@example.org', true, 'https://church.example.org'))->ok);
    }

    // Google Drive -------------------------------------------------------------------------------

    private static function drive(array $extra = []): GoogleDriveProvider
    {
        return new GoogleDriveProvider($extra + ['clientId' => 'c', 'clientSecret' => 's', 'refreshToken' => 'rt']);
    }

    public function test_drive_opens_a_resumable_session_from_this_origin(): void
    {
        $this->fake([
            'POST https://oauth2.googleapis.com/token' => self::json(['access_token' => 'ya29.x']),
            'POST https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable' => self::json([], 200, ['location' => 'https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable&upload_id=SESSION']),
        ]);
        $ticket = self::drive(['folderId' => 'folder1'])->createUpload('Sermon', new UploadHints('sermon.mp4', 12345));
        self::assertSame('resumable', $ticket->kind);
        self::assertStringContainsString('upload_id=SESSION', $ticket->client['url']);
        self::assertSame('', $ticket->id);
        [, , $headers, $body] = $this->requests[1];
        self::assertSame('https://church.example.org', $headers['Origin']);
        self::assertSame('12345', $headers['X-Upload-Content-Length']);
        self::assertSame(['name' => 'sermon.mp4', 'mimeType' => 'video/mp4', 'parents' => ['folder1']], json_decode((string) $body, true));
    }

    public function test_drive_confirms_the_file_is_the_apps_then_shares_it(): void
    {
        $this->fake([
            'POST https://oauth2.googleapis.com/token' => self::json(['access_token' => 'ya29.x']),
            'GET https://www.googleapis.com/drive/v3/files/1AbCdEfGhIjK' => self::json(['id' => '1AbCdEfGhIjK', 'mimeType' => 'video/mp4', 'videoMediaMetadata' => ['durationMillis' => '61500']]),
            'POST https://www.googleapis.com/drive/v3/files/1AbCdEfGhIjK/permissions' => self::json(['id' => 'anyoneWithLink']),
        ]);
        $info = self::drive(['apiKey' => 'AIza'])->completeUpload(new VideoRef('1AbCdEfGhIjK', ['uploaded' => true]));
        self::assertSame(['READY', 62, 'api'], [$info->status, $info->durationSeconds, $info->data['mode']]);
        self::assertSame('{"role":"reader","type":"anyone"}', $this->requests[2][3]);
    }

    public function test_drive_says_so_when_the_domain_forbids_public_sharing(): void
    {
        $this->fake([
            'POST https://oauth2.googleapis.com/token' => self::json(['access_token' => 'ya29.x']),
            'GET https://www.googleapis.com/drive/v3/files/1AbCdEfGhIjK' => self::json(['id' => '1AbCdEfGhIjK', 'mimeType' => 'video/mp4']),
            'POST https://www.googleapis.com/drive/v3/files/1AbCdEfGhIjK/permissions' => self::json(['error' => ['message' => 'The user does not have sufficient permissions']], 403),
        ]);
        $this->expectExceptionMessage('Workspace');
        self::drive()->completeUpload(new VideoRef('1AbCdEfGhIjK', ['uploaded' => true]));
    }

    public function test_drive_won_t_complete_without_the_file_id(): void
    {
        $this->fake([]);
        $this->expectException(VideoProviderException::class);
        self::drive()->completeUpload(new VideoRef('pending-abc', ['uploaded' => true]));
    }

    // OneDrive / SharePoint ----------------------------------------------------------------------

    private static function onedrive(): OneDriveProvider
    {
        return new OneDriveProvider(['tenantId' => 't', 'clientId' => 'c', 'clientSecret' => 's', 'uploadDriveId' => 'b!drive-123', 'uploadFolder' => 'Media/Sermons']);
    }

    public function test_onedrive_uploads_need_the_app_and_a_drive(): void
    {
        self::assertFalse((new OneDriveProvider([]))->capabilities()->upload);
        self::assertFalse((new OneDriveProvider(['tenantId' => 't', 'clientId' => 'c', 'clientSecret' => 's']))->capabilities()->upload);
        self::assertTrue(self::onedrive()->capabilities()->upload);
    }

    public function test_onedrive_opens_an_upload_session_at_a_path_of_its_own(): void
    {
        $this->fake([
            'POST https://login.microsoftonline.com/t/oauth2/v2.0/token' => self::json(['access_token' => 'eyJ.app']),
            'POST https://graph.microsoft.com/v1.0/drives/b%21drive-123/root:/' => self::json(['uploadUrl' => 'https://contoso-my.sharepoint.com/personal/x/_api/v2.0/drive/items/01/uploadSession?guid=abc', 'expirationDateTime' => '2026-09-26T00:00:00Z']),
        ]);
        $ticket = self::onedrive()->createUpload('Sermon', new UploadHints('sermon.mp4', 5));
        self::assertSame('resumable', $ticket->kind);
        self::assertStringStartsWith('https://contoso-my.sharepoint.com/', $ticket->client['url']);
        self::assertMatchesRegularExpression('#^Media/Sermons/sermon-[0-9a-f]{8}\.mp4$#', $ticket->data['path']);
        self::assertMatchesRegularExpression('#/root:/Media/Sermons/sermon-[0-9a-f]{8}\.mp4:/createUploadSession$#', $this->requests[1][1]);
        self::assertSame('{"item":{"@microsoft.graph.conflictBehavior":"fail"}}', $this->requests[1][3]);
    }

    public function test_onedrive_completes_with_an_anonymous_link_and_streams_its_own_item(): void
    {
        $this->fake([
            'POST https://login.microsoftonline.com/t/oauth2/v2.0/token' => self::json(['access_token' => 'eyJ.app']),
            'GET https://graph.microsoft.com/v1.0/drives/b%21drive-123/root:/' => self::json(['id' => '01ITEM', 'file' => ['mimeType' => 'video/mp4'], 'video' => ['duration' => 120000]]),
            'POST https://graph.microsoft.com/v1.0/drives/b%21drive-123/items/01ITEM/createLink' => self::json(['link' => ['type' => 'view', 'scope' => 'anonymous', 'webUrl' => 'https://contoso-my.sharepoint.com/:v:/g/personal/x/EabcDEF']], 201),
            'GET https://graph.microsoft.com/v1.0/drives/b%21drive-123/items/01ITEM' => self::json(['id' => '01ITEM', '@microsoft.graph.downloadUrl' => 'https://contoso-my.sharepoint.com/download.aspx?tempauth=short']),
        ]);
        $p = self::onedrive();
        $info = $p->completeUpload(new VideoRef('h', ['uploaded' => true, 'path' => 'Media/Sermons/sermon-1a2b3c4d.mp4']));
        self::assertSame(['READY', 120, '01ITEM', true], [$info->status, $info->durationSeconds, $info->data['itemId'], $info->data['business']]);
        self::assertSame('{"type":"view","scope":"anonymous"}', $this->requests[2][3]);

        $ref = new VideoRef('h', $info->data + ['uploaded' => true]);
        self::assertTrue($p->owns($ref));
        self::assertSame('https://contoso-my.sharepoint.com/download.aspx?tempauth=short', $p->mp4($ref, 1080)->url);
        // One app token for the whole request.
        self::assertCount(1, array_filter($this->sent(), fn ($s) => str_contains($s, 'oauth2/v2.0/token')));
    }

    public function test_onedrive_says_so_when_the_tenant_forbids_anonymous_links(): void
    {
        $this->fake([
            'POST https://login.microsoftonline.com/t/oauth2/v2.0/token' => self::json(['access_token' => 'eyJ.app']),
            'GET https://graph.microsoft.com/v1.0/drives/b%21drive-123/root:/' => self::json(['id' => '01ITEM', 'file' => ['mimeType' => 'video/mp4']]),
            'POST https://graph.microsoft.com/v1.0/drives/b%21drive-123/items/01ITEM/createLink' => self::json(['error' => ['code' => 'accessDenied', 'message' => 'Anonymous links are disabled']], 403),
        ]);
        $this->expectExceptionMessage('forbids “anyone with the link”');
        self::onedrive()->completeUpload(new VideoRef('h', ['uploaded' => true, 'path' => 'Media/Sermons/x.mp4']));
    }
}

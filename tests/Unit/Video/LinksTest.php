<?php

declare(strict_types=1);

namespace Tests\Unit\Video;

use App\Services\Video\ArchiveProvider;
use App\Services\Video\Links;
use App\Support\SigV4;
use PHPUnit\Framework\TestCase;

/** Every shape of pasted link a volunteer might bring, and the S3 signer. */
final class LinksTest extends TestCase
{
    public function test_youtube_links(): void
    {
        foreach ([
            'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'https://youtube.com/watch?v=dQw4w9WgXcQ&t=42s&list=PL1',
            'https://m.youtube.com/watch?v=dQw4w9WgXcQ',
            'https://youtu.be/dQw4w9WgXcQ?si=abc',
            'youtu.be/dQw4w9WgXcQ',
            'https://www.youtube.com/shorts/dQw4w9WgXcQ',
            'https://www.youtube.com/embed/dQw4w9WgXcQ?start=3',
            'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ',
            'https://www.youtube.com/live/dQw4w9WgXcQ',
        ] as $url) {
            $this->assertSame('dQw4w9WgXcQ', Links::youtubeId($url), $url);
        }
        foreach (['https://www.youtube.com/watch?v=short', 'https://evil.example/watch?v=dQw4w9WgXcQ', 'https://www.youtube.com/channel/UC123', 'not a url at all'] as $url) {
            $this->assertNull(Links::youtubeId($url), $url);
        }
    }

    public function test_vimeo_links(): void
    {
        $this->assertSame(['id' => '76979871', 'hash' => null], Links::vimeo('https://vimeo.com/76979871'));
        $this->assertSame(['id' => '76979871', 'hash' => 'abcdef1234'], Links::vimeo('https://vimeo.com/76979871/abcdef1234'));
        $this->assertSame(['id' => '76979871', 'hash' => 'abcdef1234'], Links::vimeo('https://player.vimeo.com/video/76979871?h=abcdef1234'));
        $this->assertSame(['id' => '76979871', 'hash' => null], Links::vimeo('https://vimeo.com/channels/staffpicks/76979871'));
        $this->assertNull(Links::vimeo('https://vimeo.com/about'));
        $this->assertNull(Links::vimeo('https://notvimeo.com/76979871'));
    }

    public function test_dropbox_links_become_direct(): void
    {
        $this->assertSame('https://www.dropbox.com/s/abc123/sermon.mp4?dl=1', Links::dropboxDirect('https://www.dropbox.com/s/abc123/sermon.mp4?dl=0'));
        $this->assertSame('https://www.dropbox.com/scl/fi/xyz/sermon.mp4?rlkey=k1&dl=1', Links::dropboxDirect('https://www.dropbox.com/scl/fi/xyz/sermon.mp4?rlkey=k1&e=1&dl=0'));
        $this->assertSame('https://dl.dropboxusercontent.com/s/abc/v.mp4?dl=1', Links::dropboxDirect('https://dl.dropboxusercontent.com/s/abc/v.mp4'));
        $this->assertNull(Links::dropboxDirect('https://www.dropbox.com/home/Videos'));
    }

    public function test_drive_links(): void
    {
        $id = '1AbCdEfGhIjKlMnOpQrStUvWxYz012345';
        foreach (["https://drive.google.com/file/d/$id/view?usp=sharing", "https://drive.google.com/open?id=$id", "https://drive.google.com/uc?id=$id&export=download"] as $url) {
            $this->assertSame($id, Links::driveId($url), $url);
        }
        $this->assertNull(Links::driveId('https://drive.google.com/drive/folders/abc'));
    }

    public function test_onedrive_links_and_shares_id(): void
    {
        $this->assertSame(['url' => 'https://1drv.ms/v/s!AbC', 'business' => false], Links::onedrive('https://1drv.ms/v/s!AbC'));
        $this->assertTrue(Links::onedrive('https://contoso-my.sharepoint.com/:v:/g/personal/x/EaBc')['business'] ?? false);
        $this->assertTrue(Links::onedrive('https://contoso.sharepoint.com/:v:/s/team/EaBc')['business'] ?? false);
        $this->assertNull(Links::onedrive('https://sharepoint.com.evil.example/x'));
        // Microsoft's own documented example.
        $this->assertSame('u!aHR0cHM6Ly9vbmVkcml2ZS5saXZlLmNvbS9yZWRpcj9yZXNpZD0xMjM0', Links::sharesId('https://onedrive.live.com/redir?resid=1234'));
    }

    public function test_archive_links_and_file_choice(): void
    {
        $this->assertSame('sermon_2024-01', Links::archiveIdentifier('https://archive.org/details/sermon_2024-01'));
        $this->assertSame('sermon_2024-01', Links::archiveIdentifier('https://archive.org/details/sermon_2024-01/part2.mp4'));
        $this->assertNull(Links::archiveIdentifier('https://archive.org/search?query=x'));
        $files = [
            ['name' => 'talk.ogv', 'format' => 'Ogg Video', 'size' => '900'],
            ['name' => 'talk.mp4', 'format' => 'MPEG4', 'size' => '5000'],
            ['name' => 'talk.ia.mp4', 'format' => 'h.264', 'size' => '3000'],
        ];
        $this->assertSame('talk.ia.mp4', ArchiveProvider::pickFile($files)['name'] ?? null);
        $this->assertNull(ArchiveProvider::pickFile([['name' => 'a.ogv', 'format' => 'Ogg Video']]));
    }

    public function test_iso_durations(): void
    {
        $this->assertSame(3723, Links::isoDuration('PT1H2M3S'));
        $this->assertSame(90, Links::isoDuration('PT1M30S'));
        $this->assertSame(86400 + 60, Links::isoDuration('P1DT1M'));
        $this->assertNull(Links::isoDuration('PT'));
        $this->assertNull(Links::isoDuration('garbage'));
    }

    /** AWS's documented example: "Example: Presigned URL" in the S3 SigV4 guide. */
    public function test_sigv4_presign_matches_the_aws_example(): void
    {
        $signer = new SigV4('AKIAIOSFODNN7EXAMPLE', 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY', 'us-east-1');
        $url = $signer->presign('GET', 'https://examplebucket.s3.amazonaws.com/test.txt', 86400, [], new \DateTimeImmutable('2013-05-24T00:00:00Z'));
        $this->assertStringContainsString('X-Amz-Signature=aeeed9bbccd4d02ee5c0109b86d86835f995330da4c265957d157751f604d404', $url);
    }

    /** AWS's documented example: "GET Object" with a Range header. */
    public function test_sigv4_header_signing_matches_the_aws_example(): void
    {
        $signer = new SigV4('AKIAIOSFODNN7EXAMPLE', 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY', 'us-east-1');
        $headers = $signer->sign('GET', 'https://examplebucket.s3.amazonaws.com/test.txt', ['Range' => 'bytes=0-9'], '', new \DateTimeImmutable('2013-05-24T00:00:00Z'));
        $this->assertStringEndsWith('Signature=f0e8bdb87c964420e857bd35b5d6ed310bd44f0170aba48dd91039c6036bdb41', $headers['authorization']);
    }
}

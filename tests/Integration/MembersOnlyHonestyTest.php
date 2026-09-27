<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Id;

/**
 * Members-only is only as honest as the service holding the video. On YouTube
 * or a pasted link the page is gated and the media URL is not, so the admin
 * screens say so where the flag is set and badge it in the list; on the host's
 * own disk, where the bytes really are gated, they stay quiet.
 */
final class MembersOnlyHonestyTest extends ServerTestCase
{
    private static string $openId = '';
    private static string $keptId = '';
    private static string $publicId = '';

    protected static function prefix(): string
    {
        return 'honest_';
    }

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        if (self::$base === '') {
            return;
        }
        self::install();
        $db = self::connect(self::prefix());
        $video = static function (string $slug, string $provider, bool $memberOnly) use ($db): string {
            $id = Id::new();
            $db->insert('videos', [
                'id' => $id, 'title' => ucfirst($slug), 'slug' => $slug, 'provider' => $provider,
                'external_id' => $slug, 'status' => 'READY', 'published' => true, 'member_only' => $memberOnly,
                'scripture_refs' => [],
            ]);
            return $id;
        };
        self::$openId = $video('gated-page-open-link', 'youtube', true);
        self::$keptId = $video('really-gated', 'local', true);
        self::$publicId = $video('open-to-all', 'youtube', false);
        self::flushCache();
    }

    public function test_1_the_flag_admits_youtube_cannot_keep_the_video_itself_private(): void
    {
        $page = self::http('GET', '/admin/videos/' . self::$openId)['body'];

        self::assertStringContainsString('data-privacy-note', $page);
        self::assertStringContainsString('the page is gated; the video’s own URL is not', $page);
        // Shown, not waiting behind the hidden attribute: the box is already ticked.
        self::assertDoesNotMatchRegularExpression('/data-privacy-note\s+hidden/', $page);
    }

    public function test_2_the_host_s_own_disk_gets_no_warning_because_it_really_does_gate_the_bytes(): void
    {
        $page = self::http('GET', '/admin/videos/' . self::$keptId)['body'];

        self::assertStringContainsString('name="memberOnly"', $page);
        self::assertStringNotContainsString('data-privacy-note', $page);
    }

    public function test_3_on_a_public_video_the_warning_waits_for_the_box_to_be_ticked(): void
    {
        $page = self::http('GET', '/admin/videos/' . self::$publicId)['body'];

        self::assertMatchesRegularExpression('/data-privacy-note\s+hidden/', $page);
    }

    public function test_4_the_video_list_badges_the_ones_whose_link_is_open(): void
    {
        $page = self::http('GET', '/admin/videos')['body'];
        $row = static function (string $id) use ($page): string {
            foreach (explode('<tr>', $page) as $r) {
                if (str_contains($r, $id)) {
                    return $r;
                }
            }
            return '';
        };

        self::assertStringContainsString('Link is open', $row(self::$openId));
        self::assertStringNotContainsString('Link is open', $row(self::$keptId));
        self::assertStringNotContainsString('Link is open', $row(self::$publicId));
        // Not a row that simply went missing: all three are listed.
        self::assertNotSame('', $row(self::$keptId));
        self::assertNotSame('', $row(self::$publicId));
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Library\PodcastMirror;
use PHPUnit\Framework\TestCase;

/** lib/podcast-mirror.test.ts */
final class PodcastMirrorTest extends TestCase
{
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-09-25 12:00:00', new \DateTimeZone('UTC'));
    }

    /** @return array<string, mixed> */
    private static function file(array $extra = []): array
    {
        return $extra + ['id' => 'f1', 'podcast_published' => true, 'mime_type' => 'audio/mpeg', 'member_only' => false, 'published' => true, 'hidden' => false, 'deleted_at' => null, 'publish_at' => null, 'unpublish_at' => null, 'storage_path' => 'files/f1.mp3'];
    }

    /** @return array<string, mixed> */
    private static function series(array $extra = []): array
    {
        return $extra + ['id' => 's1', 'member_only' => false, 'published' => true, 'hidden' => false, 'deleted_at' => null, 'publish_at' => null, 'unpublish_at' => null];
    }

    private function eligible(array $file, ?array $series, bool $categoryMemberOnly = false): bool
    {
        return PodcastMirror::isMirrorEligible($file, $series, $categoryMemberOnly, $this->now);
    }

    public function test_accepts_a_published_public_audio_file_in_a_public_series(): void
    {
        self::assertTrue($this->eligible(self::file(), self::series()));
    }

    public function test_requires_the_admin_to_have_opted_in(): void
    {
        self::assertFalse($this->eligible(self::file(['podcast_published' => false]), self::series()));
    }

    public function test_refuses_a_members_only_file(): void
    {
        self::assertFalse($this->eligible(self::file(['member_only' => true]), self::series()));
    }

    public function test_refuses_a_file_whose_series_is_members_only(): void
    {
        self::assertFalse($this->eligible(self::file(), self::series(['member_only' => true])));
        self::assertFalse($this->eligible(self::file(), self::series(), categoryMemberOnly: true));
    }

    public function test_refuses_when_the_series_is_unpublished_hidden_or_trashed(): void
    {
        foreach ([['published' => false], ['hidden' => true], ['deleted_at' => '2026-09-01 00:00:00']] as $state) {
            self::assertFalse($this->eligible(self::file(), self::series($state)), json_encode($state));
        }
    }

    public function test_refuses_when_the_file_itself_is_unpublished_hidden_or_trashed(): void
    {
        foreach ([['published' => false], ['hidden' => true], ['deleted_at' => '2026-09-01 00:00:00']] as $state) {
            self::assertFalse($this->eligible(self::file($state), self::series()), json_encode($state));
        }
    }

    public function test_respects_a_publish_schedule_that_hasnt_started(): void
    {
        self::assertFalse($this->eligible(self::file(['publish_at' => '2026-10-01 00:00:00']), self::series()));
        self::assertFalse($this->eligible(self::file(), self::series(['publish_at' => '2026-10-01 00:00:00'])));
    }

    public function test_respects_an_expiry_that_has_passed(): void
    {
        self::assertFalse($this->eligible(self::file(['unpublish_at' => '2026-09-01 00:00:00']), self::series()));
    }

    public function test_only_mirrors_audio(): void
    {
        self::assertFalse($this->eligible(self::file(['mime_type' => 'application/pdf']), self::series()));
    }

    public function test_refuses_a_file_with_no_series_which_has_no_feed_to_appear_in(): void
    {
        self::assertFalse($this->eligible(self::file(), null));
    }

    public function test_namespaces_by_file_id_so_the_zones_contents_are_self_describing(): void
    {
        self::assertSame('podcast/f1/Sermon-1.mp3', PodcastMirror::publicPathFor(self::file(['storage_path' => 'imports/Sermon 1.mp3'])));
        self::assertSame('podcast/f1/f1.mp3', PodcastMirror::publicPathFor(self::file()));
    }

    public function test_falls_back_to_the_id_when_the_source_path_has_no_filename(): void
    {
        self::assertSame('podcast/f1/f1', PodcastMirror::publicPathFor(self::file(['storage_path' => ''])));
    }
}

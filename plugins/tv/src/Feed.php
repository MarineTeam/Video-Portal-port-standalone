<?php

declare(strict_types=1);

namespace MarineTeam\Plugins\Tv;

/**
 * The catalogue a television platform reads (lib/tv-feed.ts).
 *
 * `/api/tv/feed.json` is the shape Roku's Direct Publisher takes: point a
 * channel at it and Roku builds and ships a real television channel, with no
 * BrightScript and nothing to maintain. `/api/tv/feed.xml` is the same
 * catalogue as MRSS, which most other platforms take.
 *
 * Only public content goes in either. A feed is fetched by somebody else's
 * server with no session, cached by them, and republished to every
 * television that installs the channel; there is no login to put in front of
 * it, so anything members-only is excluded — including a public video inside
 * a members-only series. That filtering happens in the query; what is here
 * is the shaping, and its rule is that a row it cannot describe properly is
 * left out rather than sent malformed for somebody else's validator to
 * reject the whole feed over.
 */
final class Feed
{
    /** Direct Publisher's own limits, which it enforces by rejecting a feed. */
    public const MAX_TITLE = 255;
    public const MAX_DESCRIPTION = 200;
    public const MAX_LONG_DESCRIPTION = 500;

    /** What it takes when a video has no description of its own. */
    public const FALLBACK_DESCRIPTION = 'A video from this church.';

    /** How long a platform may keep a copy. */
    public const TTL = 3600;

    /**
     * Whether this row can be described to a television at all.
     *
     * A video with no duration is left out rather than having the whole feed
     * rejected over it — Direct Publisher requires one — and an imported
     * video with nowhere to play it is left out for the plainer reason that
     * there would be nothing behind the tile.
     *
     * @param array<string, mixed> $video
     */
    public static function isFeedable(array $video): bool
    {
        return self::feedDuration($video) > 0
            && trim((string) ($video['title'] ?? '')) !== ''
            && self::playUrl($video) !== null;
    }

    /**
     * Where a television is sent. A stream it can play itself when there is
     * one, and otherwise the source's own page for an imported video, which
     * is the honest answer for something this site never held.
     *
     * @param array<string, mixed> $video
     */
    public static function playUrl(array $video): ?string
    {
        foreach ([$video['streamUrl'] ?? null, $video['externalUrl'] ?? null] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }
        return null;
    }

    /** @param array<string, mixed> $video */
    public static function streamKind(array $video): string
    {
        $url = (string) (self::playUrl($video) ?? '');
        return str_contains($url, '.m3u8') ? 'HLS' : 'MP4';
    }

    /** A whole number of seconds, because half a second is not a duration. */
    public static function feedDuration(array $video): int
    {
        $seconds = $video['durationSeconds'] ?? $video['duration_seconds'] ?? 0;
        return is_numeric($seconds) ? max(0, (int) round((float) $seconds)) : 0;
    }

    /**
     * When it went out, not when the row happened to be made: an import that
     * back-fills last year's sermons should not arrive as this week's.
     *
     * @param array<string, mixed> $video
     */
    public static function feedDate(array $video): string
    {
        foreach (['publishAt', 'publish_at', 'createdAt', 'created_at'] as $key) {
            $value = $video[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
            }
        }
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
    }

    /**
     * Trimmed at a word, not mid-word. Their validators cut to the limit
     * themselves, which leaves a sentence ending "in the middle of a wo".
     */
    public static function trim(string $text, int $limit): string
    {
        // Whitespace only: a title may legally contain "<for all>", and
        // stripping tags from it would quietly eat half the title. What a
        // description's HTML needs is plain(), below.
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        if (mb_strlen($text) <= $limit) {
            return $text;
        }
        $cut = mb_substr($text, 0, $limit - 1);
        $space = mb_strrpos($cut, ' ');
        return rtrim($space !== false && $space > $limit / 2 ? mb_substr($cut, 0, $space) : $cut, " ,.;:") . '…';
    }

    /** A description as text: the markup out, the entities read back. */
    public static function plain(string $html): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    /**
     * The catalogue as Direct Publisher expects it.
     *
     * @param list<array<string, mixed>> $videos
     * @param array{title: string, language: string, url: string} $site
     * @return array<string, mixed>
     */
    public static function rokuFeed(array $videos, array $site, ?string $now = null): array
    {
        $items = [];
        foreach ($videos as $video) {
            if (!self::isFeedable($video)) {
                continue;
            }
            $description = self::trim(self::plain((string) ($video['description'] ?? '')), self::MAX_DESCRIPTION);
            $long = self::trim(self::plain((string) ($video['description'] ?? '')), self::MAX_LONG_DESCRIPTION);
            $item = [
                'id' => (string) $video['id'],
                'title' => self::trim((string) $video['title'], self::MAX_TITLE),
                // Never empty: an empty description fails their validation.
                'shortDescription' => $description !== '' ? $description : self::FALLBACK_DESCRIPTION,
                'longDescription' => $long !== '' ? $long : self::FALLBACK_DESCRIPTION,
                'thumbnail' => (string) ($video['thumbnailUrl'] ?? ''),
                'releaseDate' => substr(self::feedDate($video), 0, 10),
                'content' => [
                    'dateAdded' => self::feedDate($video),
                    'duration' => self::feedDuration($video),
                    'language' => (string) ($video['language'] ?? $site['language']),
                    'videos' => [[
                        'url' => (string) self::playUrl($video),
                        'quality' => 'HD',
                        'videoType' => self::streamKind($video),
                    ]],
                ],
                'genres' => ['religion'],
                'tags' => array_values(array_filter([
                    isset($video['seriesTitle']) ? (string) $video['seriesTitle'] : null,
                    isset($video['speakerName']) ? (string) $video['speakerName'] : null,
                ], fn (?string $tag) => $tag !== null && trim($tag) !== '')),
            ];
            if ($item['thumbnail'] === '') {
                unset($item['thumbnail']);
            }
            $items[] = $item;
        }
        return [
            'providerName' => $site['title'],
            'lastUpdated' => (new \DateTimeImmutable($now ?? 'now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'),
            'language' => $site['language'],
            'shortFormVideos' => $items,
        ];
    }

    /**
     * The same catalogue as MRSS.
     *
     * @param list<array<string, mixed>> $videos
     * @param array{title: string, language: string, url: string} $site
     */
    public static function mrssFeed(array $videos, array $site, ?string $now = null): string
    {
        $x = static fn (string $text): string => htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $out = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<rss version="2.0" xmlns:media="http://search.yahoo.com/mrss/" xmlns:atom="http://www.w3.org/2005/Atom">' . "\n"
            . "<channel>\n"
            . '  <title>' . $x($site['title']) . "</title>\n"
            . '  <link>' . $x($site['url']) . "</link>\n"
            . '  <description>' . $x($site['title']) . "</description>\n"
            . '  <language>' . $x($site['language']) . "</language>\n"
            . '  <lastBuildDate>' . $x(gmdate('D, d M Y H:i:s \G\M\T', strtotime($now ?? 'now'))) . "</lastBuildDate>\n";
        foreach ($videos as $video) {
            // Exactly what the JSON leaves out.
            if (!self::isFeedable($video)) {
                continue;
            }
            $description = self::trim(self::plain((string) ($video['description'] ?? '')), self::MAX_LONG_DESCRIPTION);
            $url = (string) self::playUrl($video);
            $out .= "  <item>\n"
                . '    <title>' . $x(self::trim((string) $video['title'], self::MAX_TITLE)) . "</title>\n"
                . '    <link>' . $x((string) ($video['pageUrl'] ?? $site['url'])) . "</link>\n"
                . '    <guid isPermaLink="false">' . $x((string) $video['id']) . "</guid>\n"
                . '    <description>' . $x($description !== '' ? $description : self::FALLBACK_DESCRIPTION) . "</description>\n"
                . '    <pubDate>' . $x(gmdate('D, d M Y H:i:s \G\M\T', strtotime(self::feedDate($video)))) . "</pubDate>\n"
                . '    <media:content url="' . $x($url) . '" medium="video" duration="' . self::feedDuration($video) . '" type="'
                . $x(self::streamKind($video) === 'HLS' ? 'application/x-mpegURL' : 'video/mp4') . "\"/>\n";
            $thumbnail = (string) ($video['thumbnailUrl'] ?? '');
            if ($thumbnail !== '') {
                $out .= '    <media:thumbnail url="' . $x($thumbnail) . "\"/>\n";
            }
            foreach (['seriesTitle', 'speakerName'] as $key) {
                if (isset($video[$key]) && trim((string) $video[$key]) !== '') {
                    $out .= '    <media:category>' . $x((string) $video[$key]) . "</media:category>\n";
                }
            }
            $out .= "  </item>\n";
        }
        return $out . "</channel>\n</rss>\n";
    }
}

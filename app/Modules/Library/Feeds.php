<?php

declare(strict_types=1);

namespace App\Modules\Library;

use App\Core\App;
use App\Core\Json;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\Url;

/**
 * What machines read: /feed.xml (recently added series), a series' podcast
 * feed, /sitemap.xml and /robots.txt. Each is built as a visitor would see
 * the site, whoever asks — an administrator's feed reader must not publish
 * members-only titles, and podcast apps can't sign in, so a members-only
 * series has no podcast feed at all.
 */
final class Feeds
{
    public function __construct(private readonly App $app)
    {
    }

    public static function register(Router $r, App $app): void
    {
        $self = new self($app);
        $r->get('/feed.xml', [$self, 'rss']);
        $r->get('/series/[slug]/podcast.xml', [$self, 'podcast']);
        $r->get('/sitemap.xml', [$self, 'sitemap']);
        $r->get('/robots.txt', [$self, 'robots']);
    }

    private function guest(): Browse
    {
        return new Browse($this->app, new ContentAccess($this->app, Viewer::guest()));
    }

    private static function x(?string $text): string
    {
        // XML 1.0 forbids most control characters even when escaped.
        $text = (string) preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', (string) $text);
        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private static function rfc822(?string $datetime): string
    {
        $t = $datetime !== null ? strtotime($datetime . ' UTC') : false;
        return gmdate('D, d M Y H:i:s', $t === false ? time() : $t) . ' GMT';
    }

    private static function absolute(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }
        return str_starts_with($url, 'http') ? $url : Url::absolute($url);
    }

    private function xml(string $body, string $type = 'application/rss+xml; charset=utf-8'): Response
    {
        return Response::text($body, 200, $type)->header('Cache-Control', 'public, max-age=900');
    }

    private function siteName(): string
    {
        return (string) \App\Modules\Themes\Appearance::forPage($this->app)['branding']['name'];
    }

    public function rss(Request $req): Response
    {
        $name = $this->siteName();
        $items = '';
        foreach ($this->guest()->seriesWhere('1 = 1', [], 's.created_at DESC', 50) as $s) {
            $link = Url::absolute('/series/' . $s['slug']);
            $items .= "  <item>\n"
                . '    <title>' . self::x((string) $s['title']) . "</title>\n"
                . '    <link>' . self::x($link) . "</link>\n"
                . '    <guid isPermaLink="false">' . self::x('series:' . $s['id']) . "</guid>\n"
                . '    <pubDate>' . self::rfc822((string) ($s['publish_at'] ?? $s['created_at'])) . "</pubDate>\n"
                . (!empty($s['description']) ? '    <description>' . self::x((string) $s['description']) . "</description>\n" : '')
                . "  </item>\n";
        }
        return $this->xml("<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
            . "<rss version=\"2.0\" xmlns:atom=\"http://www.w3.org/2005/Atom\">\n<channel>\n"
            . '  <title>' . self::x($name) . "</title>\n"
            . '  <link>' . self::x(Url::absolute('/')) . "</link>\n"
            . '  <atom:link href="' . self::x(Url::absolute('/feed.xml')) . "\" rel=\"self\" type=\"application/rss+xml\"/>\n"
            . '  <description>' . self::x(t('library.recentlyAdded') . ' — ' . $name) . "</description>\n"
            . $items
            . "</channel>\n</rss>\n");
    }

    /** @param array<string, string> $p */
    public function podcast(Request $req, array $p): Response
    {
        $browse = $this->guest();
        $aliased = false;
        $series = $browse->seriesBySlug($p['slug'], $aliased);
        if ($series === null || $browse->access()->series($series) !== ContentAccess::OK) {
            return Response::text('Not found', 404);
        }
        $name = $this->siteName();
        $cover = self::absolute($series['cover_image_url'] ?? null);
        $items = '';
        foreach ($browse->files((string) $series['id']) as $f) {
            if (!(bool) $f['podcast_published'] || !str_starts_with((string) $f['mime_type'], 'audio/')) {
                continue;
            }
            $url = Url::absolute('/api/files/' . $f['id'] . '/content');
            $items .= "  <item>\n"
                . '    <title>' . self::x((string) $f['title']) . "</title>\n"
                . '    <guid isPermaLink="false">' . self::x((string) $f['id']) . "</guid>\n"
                . '    <pubDate>' . self::rfc822((string) ($f['publish_at'] ?? $f['created_at'])) . "</pubDate>\n"
                . '    <enclosure url="' . self::x($url) . '" length="' . (int) ($f['size_bytes'] ?? 0) . '" type="' . self::x((string) $f['mime_type']) . "\"/>\n"
                . "    <itunes:explicit>false</itunes:explicit>\n"
                . "  </item>\n";
        }
        return $this->xml("<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
            . "<rss version=\"2.0\" xmlns:itunes=\"http://www.itunes.com/dtds/podcast-1.0.dtd\" xmlns:atom=\"http://www.w3.org/2005/Atom\">\n<channel>\n"
            . '  <title>' . self::x((string) $series['title']) . "</title>\n"
            . '  <link>' . self::x(Url::absolute('/series/' . $series['slug'])) . "</link>\n"
            . '  <atom:link href="' . self::x(Url::absolute('/series/' . $series['slug'] . '/podcast.xml')) . "\" rel=\"self\" type=\"application/rss+xml\"/>\n"
            . '  <description>' . self::x((string) ($series['description'] ?? $series['title'])) . "</description>\n"
            . '  <language>' . self::x((string) ($series['language'] ?? 'en')) . "</language>\n"
            . '  <itunes:author>' . self::x($name) . "</itunes:author>\n"
            . '  <itunes:summary>' . self::x((string) ($series['description'] ?? $series['title'])) . "</itunes:summary>\n"
            . "  <itunes:explicit>false</itunes:explicit>\n"
            . "  <itunes:category text=\"Religion &amp; Spirituality\"><itunes:category text=\"Christianity\"/></itunes:category>\n"
            . ($cover !== null ? '  <itunes:image href="' . self::x($cover) . "\"/>\n  <image><url>" . self::x($cover) . '</url><title>' . self::x((string) $series['title']) . '</title><link>' . self::x(Url::absolute('/series/' . $series['slug'])) . "</link></image>\n" : '')
            . $items
            . "</channel>\n</rss>\n", 'application/rss+xml; charset=utf-8');
    }

    public function sitemap(Request $req): Response
    {
        $browse = $this->guest();
        $urls = [['/', null]];
        $walk = function (?string $parent) use (&$walk, &$urls, $browse): void {
            foreach ($browse->categories($parent) as $c) {
                $urls[] = ['/categories/' . $c['slug'], null];
                $walk((string) $c['id']);
            }
        };
        $walk(null);
        $tags = [];
        foreach ($browse->seriesWhere('1 = 1', [], 's.created_at DESC', 50000) as $s) {
            $urls[] = ['/series/' . $s['slug'], $s['updated_at'] ?? $s['created_at']];
            foreach ((array) json_decode((string) ($s['tags'] ?? '[]'), true) as $tag) {
                if (is_string($tag) && $tag !== '') {
                    $tags[mb_strtolower($tag)] = true;
                }
            }
        }
        foreach ($browse->videosWhere('1 = 1', [], 'v.created_at DESC', 50000) as $v) {
            $urls[] = ['/videos/' . $v['slug'], $v['updated_at'] ?? $v['created_at']];
        }
        foreach (array_keys($tags) as $tag) {
            $urls[] = ['/tags/' . rawurlencode((string) $tag), null];
        }
        foreach ($this->app->db()->column('SELECT slug FROM {{speakers}} ORDER BY name') as $slug) {
            $urls[] = ['/speakers/' . $slug, null];
        }
        [$where, $params] = $browse->access()->videoListSql('v', 's');
        foreach ($this->app->db()->column(
            "SELECT DISTINCT b.book FROM {{video_scripture_books}} b JOIN {{videos}} v ON v.id = b.video_id LEFT JOIN {{series}} s ON s.id = v.series_id WHERE $where",
            $params,
        ) as $book) {
            $urls[] = ['/scripture/' . rawurlencode((string) $book), null];
        }
        // Plugins add theirs (/live, events): each a path, optionally with a last-modified time.
        foreach ((array) $this->app->hooks->apply('sitemap.urls', [], $this->app) as $extra) {
            if (is_string($extra)) {
                $urls[] = [$extra, null];
            } elseif (is_array($extra) && is_string($extra[0] ?? null)) {
                $urls[] = [$extra[0], $extra[1] ?? null];
            }
        }
        $body = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";
        foreach ($urls as [$path, $modified]) {
            $body .= '  <url><loc>' . self::x(Url::absolute($path)) . '</loc>'
                . ($modified !== null ? '<lastmod>' . self::x(substr((string) Json::instant((string) $modified), 0, 10)) . '</lastmod>' : '')
                . "</url>\n";
        }
        return $this->xml($body . "</urlset>\n", 'application/xml; charset=utf-8');
    }

    public function robots(Request $req): Response
    {
        $base = rtrim(Url::to('/'), '/');
        return Response::text("User-agent: *\nDisallow: $base/admin\nDisallow: $base/api/\nDisallow: $base/auth/\nDisallow: $base/profile\n\nSitemap: " . Url::absolute('/sitemap.xml') . "\n")
            ->header('Cache-Control', 'public, max-age=3600');
    }
}

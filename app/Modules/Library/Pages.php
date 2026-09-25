<?php

declare(strict_types=1);

namespace App\Modules\Library;

use App\Core\App;
use App\Core\ErrorPage;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\Url;
use App\Support\Timestamp;

/**
 * The library's browse pages: the home page, a category, a series, a video.
 * Each asks ContentAccess first. A page the reader may not open gets a
 * generic title and no image (its name mustn't leak through a link
 * preview): missing is a plain 404, login asks them to sign in, denied says
 * it is for other people. An old slug redirects permanently to the new one.
 */
final class Pages
{
    public function __construct(private readonly App $app)
    {
    }

    public static function register(Router $r, App $app): void
    {
        $self = new self($app);
        $r->get('/', [$self, 'home']);
        $r->get('/categories/[slug]', [$self, 'category']);
        $r->get('/series/[slug]', [$self, 'series']);
        $r->get('/videos/[slug]', [$self, 'video']);
    }

    private function browse(): Browse
    {
        return Browse::for($this->app);
    }

    /** The page for a decision other than ok. */
    private function refuse(string $decision, Request $req): Response
    {
        return match ($decision) {
            ContentAccess::LOGIN => $this->app->page('library/sign-in', [
                'title' => t('library.signInTitle'),
                'noindex' => true,
                'returnTo' => $req->path . ($req->query !== [] ? '?' . http_build_query($req->query) : ''),
            ], 401),
            ContentAccess::DENIED => ErrorPage::render(403, t('library.restricted')),
            default => ErrorPage::render(404),
        };
    }

    private function redirectTo(string $path, Request $req): Response
    {
        return Response::redirect(Url::to($path, $req->query), 301);
    }

    public function home(Request $req): Response
    {
        $browse = $this->browse();
        $branding = \App\Modules\Themes\Appearance::forPage($this->app)['branding'];
        $featured = $browse->seriesWhere('s.featured = 1', [], 's.position, s.created_at DESC', 1);
        $recent = $browse->seriesWhere('1 = 1', [], 's.created_at DESC', 12);
        return $this->app->page('library/home', [
            'title' => $branding['name'],
            'branding' => $branding,
            'hero' => $featured[0] ?? $recent[0] ?? null,
            'continue' => $browse->continueWatching(),
            'categories' => $browse->categories(null),
            'series' => $browse->series(null),
            'videos' => $browse->videos(null, null),
            'recent' => $recent,
            'meta' => ['og:title' => $branding['name'], 'og:type' => 'website', 'og:url' => Url::absolute('/')],
        ]);
    }

    /** @param array<string, string> $p */
    public function category(Request $req, array $p): Response
    {
        $browse = $this->browse();
        $category = $browse->categoryBySlug($p['slug']);
        if ($category === null) {
            return ErrorPage::render(404);
        }
        $decision = $browse->access()->category($category);
        if ($decision !== ContentAccess::OK) {
            return $this->refuse($decision, $req);
        }
        $id = (string) $category['id'];
        $trail = $browse->trail($id);
        return $this->app->page('library/category', [
            'title' => $category['name'],
            'description' => self::excerpt($category['description'] ?? null),
            'category' => $category,
            'trail' => array_slice($trail, 0, -1),
            'preview' => !Visibility::isVisible($category, $browse->access()->now()),
            'children' => $browse->categories($id),
            'series' => $browse->series($id),
            'videos' => $browse->videos(null, $id),
            'files' => $browse->files(null, $id),
            'meta' => self::og((string) $category['name'], '/categories/' . $category['slug'], $category['cover_image_url'] ?? null),
            'jsonLd' => [self::breadcrumbs([...array_map(fn ($c) => [$c['name'], '/categories/' . $c['slug']], $trail)])],
        ]);
    }

    /** @param array<string, string> $p */
    public function series(Request $req, array $p): Response
    {
        $browse = $this->browse();
        $aliased = false;
        $series = $browse->seriesBySlug($p['slug'], $aliased);
        if ($series === null) {
            return ErrorPage::render(404);
        }
        if ($aliased) {
            return $this->redirectTo('/series/' . $series['slug'], $req);
        }
        $decision = $browse->access()->series($series);
        if ($decision !== ContentAccess::OK) {
            return $this->refuse($decision, $req);
        }
        $videos = $browse->videos((string) $series['id']);
        $trail = $browse->trail($series['category_id'] !== null ? (string) $series['category_id'] : null);
        $crumbs = [...array_map(fn ($c) => [$c['name'], '/categories/' . $c['slug']], $trail), [$series['title'], '/series/' . $series['slug']]];
        return $this->app->page('library/series', [
            'title' => $series['title'],
            'description' => self::excerpt($series['description'] ?? null),
            'series' => $series,
            'trail' => $trail,
            'preview' => !Visibility::isVisible($series, $browse->access()->now()),
            'videos' => $videos,
            'locked' => array_flip($this->lockedIds($series, $videos)),
            'files' => $browse->files((string) $series['id']),
            'tags' => array_values(array_filter((array) json_decode((string) ($series['tags'] ?? '[]'), true), 'is_string')),
            'signedIn' => $browse->access()->viewer()->signedIn(),
            'share' => (new Sharing($this->app))->panel('series', (string) $series['id']),
            'meta' => self::og((string) $series['title'], '/series/' . $series['slug'], $series['cover_image_url'] ?? ($videos[0]['thumbnail'] ?? null)),
            'jsonLd' => [self::breadcrumbs($crumbs)],
        ]);
    }

    /**
     * @param array<string, mixed> $series
     * @param list<array<string, mixed>> $videos
     * @return list<string>
     */
    private function lockedIds(array $series, array $videos): array
    {
        $category = $series['category_id'] !== null ? ($this->browse()->access()->categories()[(string) $series['category_id']] ?? null) : null;
        if (!(bool) $series['require_sequential'] && !(bool) ($category['require_sequential'] ?? false)) {
            return [];
        }
        return $this->browse()->lockedIds(['require_sequential' => true] + $series, $videos);
    }

    /** @param array<string, string> $p */
    public function video(Request $req, array $p): Response
    {
        $browse = $this->browse();
        $aliased = false;
        $video = $browse->videoBySlug($p['slug'], $aliased);
        if ($video === null) {
            return ErrorPage::render(404);
        }
        if ($aliased) {
            return $this->redirectTo('/videos/' . $video['slug'], $req);
        }
        $series = $video['series_id'] !== null ? $this->app->db()->one('SELECT * FROM {{series}} WHERE id = ?', [$video['series_id']]) : null;
        $access = $browse->access();
        $decision = $access->video($video, $series);
        if ($decision !== ContentAccess::OK) {
            return $this->refuse($decision, $req);
        }
        $now = $access->now();
        $siblings = $series !== null ? $browse->videos((string) $series['id']) : [];
        $index = array_search((string) $video['id'], array_map(fn ($v) => (string) $v['id'], $siblings), true);
        $locked = $series !== null && in_array((string) $video['id'], $this->lockedIds($series, $siblings), true);
        [$decorated] = $browse->decorate([$video]);
        $start = Timestamp::parse($req->query('t'));
        if ($start === null && !$decorated['watched'] && ($decorated['progress_seconds'] ?? 0) > 5) {
            $start = (int) $decorated['progress_seconds'];
        }
        $premiere = Visibility::isPremiere($video, $now);
        $categoryId = $video['category_id'] ?? ($series['category_id'] ?? null);
        $trail = $browse->trail($categoryId !== null ? (string) $categoryId : null);
        $crumbs = array_map(fn ($c) => [$c['name'], '/categories/' . $c['slug']], $trail);
        if ($series !== null) {
            $crumbs[] = [$series['title'], '/series/' . $series['slug']];
        }
        $crumbs[] = [$video['title'], '/videos/' . $video['slug']];
        $speaker = $video['speaker_id'] !== null ? $this->app->db()->one('SELECT id, name, slug FROM {{speakers}} WHERE id = ?', [$video['speaker_id']]) : null;
        $player = ($locked || $premiere || $video['status'] !== 'READY') ? null : Player::spec($this->app, $video, $start ?? 0);
        return $this->app->page('library/video', [
            'title' => $video['title'],
            'description' => self::excerpt($video['description'] ?? null),
            'video' => $decorated,
            'series' => $series,
            'trail' => $trail,
            'preview' => !Visibility::isVisible($video, $now) && !$premiere,
            'premiere' => $premiere,
            'locked' => $locked,
            'waitingFor' => $locked && $index !== false ? $this->firstUnwatched($siblings) : null,
            'player' => $player,
            'previous' => $index !== false && $index > 0 ? $siblings[$index - 1] : null,
            'next' => $index !== false && isset($siblings[$index + 1]) ? $siblings[$index + 1] : null,
            'speaker' => $speaker,
            'scripture' => array_values(array_filter((array) json_decode((string) ($video['scripture_refs'] ?? '[]'), true), 'is_string')),
            'signedIn' => $access->viewer()->signedIn(),
            'share' => $locked ? null : (new Sharing($this->app))->panel('video', (string) $video['id']),
            'meta' => self::og((string) $video['title'], '/videos/' . $video['slug'], $decorated['thumbnail'], 'video.other'),
            'jsonLd' => [self::videoObject($video, $decorated['thumbnail']), self::breadcrumbs($crumbs)],
        ]);
    }

    /**
     * @param list<array<string, mixed>> $videos
     * @return array<string, mixed>|null
     */
    private function firstUnwatched(array $videos): ?array
    {
        foreach ($videos as $v) {
            if (!$v['watched']) {
                return $v;
            }
        }
        return null;
    }

    // Metadata ------------------------------------------------------------------------------

    public static function excerpt(?string $text, int $length = 200): ?string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) $text)));
        if ($text === '') {
            return null;
        }
        return mb_strlen($text) > $length ? rtrim(mb_substr($text, 0, $length - 1)) . '…' : $text;
    }

    /** @return array<string, string> */
    private static function og(string $title, string $path, ?string $image, string $type = 'website'): array
    {
        $out = ['og:title' => $title, 'og:type' => $type, 'og:url' => Url::absolute($path)];
        if ($image !== null && $image !== '') {
            $out['og:image'] = str_starts_with($image, 'http') ? $image : Url::absolute($image);
        }
        return $out;
    }

    /**
     * @param list<array{0: string, 1: string}> $crumbs name, path
     * @return array<string, mixed>
     */
    public static function breadcrumbs(array $crumbs): array
    {
        $items = [['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => Url::absolute('/')]];
        foreach ($crumbs as $i => [$name, $path]) {
            $items[] = ['@type' => 'ListItem', 'position' => $i + 2, 'name' => $name, 'item' => Url::absolute($path)];
        }
        return ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $items];
    }

    /**
     * @param array<string, mixed> $video
     * @return array<string, mixed>
     */
    public static function videoObject(array $video, ?string $thumbnail): array
    {
        $out = [
            '@context' => 'https://schema.org',
            '@type' => 'VideoObject',
            'name' => (string) $video['title'],
            'description' => self::excerpt($video['description'] ?? null, 500) ?? (string) $video['title'],
            'uploadDate' => \App\Core\Json::instant((string) ($video['publish_at'] ?? $video['created_at'])),
            'url' => Url::absolute('/videos/' . $video['slug']),
        ];
        if ($thumbnail !== null && $thumbnail !== '') {
            $out['thumbnailUrl'] = str_starts_with($thumbnail, 'http') ? $thumbnail : Url::absolute($thumbnail);
        }
        if ($video['duration_seconds'] !== null) {
            $s = (int) $video['duration_seconds'];
            $out['duration'] = sprintf('PT%dH%dM%dS', intdiv($s, 3600), intdiv($s % 3600, 60), $s % 60);
        }
        return $out;
    }
}

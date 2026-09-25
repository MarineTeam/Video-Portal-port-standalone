<?php

declare(strict_types=1);

namespace App\Modules\Library;

use App\Core\App;
use App\Core\Db;
use App\Modules\Plugins\PluginStates;

/**
 * One search across categories, series (title, description, tags), videos
 * (title, description, and transcripts when that plugin is on) and
 * speakers, ranked by where the words matched: an exact or prefix title
 * beats a word in the title beats a tag beats the description beats a
 * transcript. Only when that finds no series or no videos does a fuzzy pass
 * re-rank up to 500 candidate titles by similarity, so "chruch" still finds
 * "Church" and the common case pays nothing extra.
 *
 * Everything goes through Browse's listings, so a reader only finds what
 * they may open. Plugins add sources through content.search_sources.
 */
final class Search
{
    public const MAX_QUERY = 100;
    public const FUZZY_CANDIDATES = 500;
    public const FUZZY_THRESHOLD = 0.72;

    public function __construct(private readonly App $app, private readonly Browse $browse)
    {
    }

    public static function clean(?string $q): string
    {
        $q = trim((string) preg_replace('/\s+/u', ' ', (string) $q));
        return mb_substr($q, 0, self::MAX_QUERY);
    }

    /**
     * How well a title and its supporting text match: 0 for no match.
     */
    public static function score(string $q, string $title, string $tags = '', string $description = '', string $transcript = ''): int
    {
        $q = mb_strtolower($q);
        $title = mb_strtolower($title);
        if ($q === '') {
            return 0;
        }
        if ($title === $q) {
            return 100;
        }
        if (str_starts_with($title, $q)) {
            return 80;
        }
        if (preg_match('/(^|[^\p{L}\p{N}])' . preg_quote($q, '/') . '/u', $title)) {
            return 60;
        }
        if (str_contains($title, $q)) {
            return 45;
        }
        if ($tags !== '' && str_contains(mb_strtolower($tags), $q)) {
            return 30;
        }
        if ($description !== '' && str_contains(mb_strtolower($description), $q)) {
            return 20;
        }
        if ($transcript !== '' && str_contains(mb_strtolower($transcript), $q)) {
            return 10;
        }
        return 0;
    }

    /**
     * Similarity of a query to a title, 0..1: each query word against the
     * title's best-matching word (so word order and extra words don't count
     * against it), averaged.
     */
    public static function similarity(string $q, string $title): float
    {
        $qWords = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($q), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $tWords = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($title), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($qWords === [] || $tWords === []) {
            return 0.0;
        }
        $total = 0.0;
        foreach ($qWords as $qw) {
            $best = 0.0;
            foreach ($tWords as $tw) {
                $max = max(strlen($qw), strlen($tw));
                // Levenshtein counts a swapped pair as two edits; similar_text forgives it.
                $lev = 1 - levenshtein($qw, $tw) / $max;
                similar_text($qw, $tw, $pct);
                $best = max($best, $lev, $pct / 100);
            }
            $total += $best;
        }
        return $total / count($qWords);
    }

    /**
     * @param array{categoryId?: ?string, speakerId?: ?string, sort?: string} $filters
     * @return array{categories: list<array<string, mixed>>, series: list<array<string, mixed>>, videos: list<array<string, mixed>>, speakers: list<array<string, mixed>>, extra: list<array<string, mixed>>, fuzzy: bool}
     */
    public function run(string $q, array $filters = []): array
    {
        $q = self::clean($q);
        $out = ['categories' => [], 'series' => [], 'videos' => [], 'speakers' => [], 'extra' => [], 'fuzzy' => false];
        if (mb_strlen($q) < 2) {
            return $out;
        }
        $like = '%' . Db::likeEscape($q) . '%';
        $newest = ($filters['sort'] ?? 'relevance') === 'newest';
        $categoryId = $filters['categoryId'] ?? null;
        $speakerId = $filters['speakerId'] ?? null;
        $within = $categoryId !== null ? $this->browse->access()->tree()->descendants([$categoryId]) : null;
        $transcripts = PluginStates::enabled($this->app->db(), 'transcripts');

        // Categories.
        if ($speakerId === null) {
            foreach ($this->browse->access()->categories() as $c) {
                if ($within !== null && !in_array((string) $c['id'], $within, true)) {
                    continue;
                }
                $score = self::score($q, (string) $c['name']);
                if ($score > 0 && Visibility::isVisible($c, $this->browse->access()->now()) && $this->browse->access()->category($c) === ContentAccess::OK) {
                    $out['categories'][] = $c + ['score' => $score];
                }
            }
        }

        // Series.
        if ($speakerId === null) {
            [$scope, $scopeParams] = $this->scope('s.category_id', $within);
            $series = $this->browse->seriesWhere(
                "(s.title LIKE ? OR s.description LIKE ? OR MATCH (s.title, s.description) AGAINST (? IN NATURAL LANGUAGE MODE) OR EXISTS (SELECT 1 FROM {{series_tags}} t WHERE t.series_id = s.id AND t.tag LIKE ?))$scope",
                [$like, $like, $q, $like, ...$scopeParams],
                's.created_at DESC',
                200,
            );
            foreach ($series as $s) {
                // A match only the full-text index saw (words in another order) ranks with descriptions.
                $s['score'] = self::score($q, (string) $s['title'], implode(' ', (array) json_decode((string) ($s['tags'] ?? '[]'), true)), (string) ($s['description'] ?? '')) ?: 15;
                $out['series'][] = $s;
            }
        }

        // Videos.
        [$scope, $scopeParams] = $this->scope('COALESCE(v.category_id, s.category_id)', $within);
        $text = 'v.title LIKE ? OR v.description LIKE ? OR MATCH (v.title, v.description) AGAINST (? IN NATURAL LANGUAGE MODE)'
            . ($transcripts ? ' OR MATCH (v.transcript) AGAINST (? IN NATURAL LANGUAGE MODE)' : '');
        $params = $transcripts ? [$like, $like, $q, $q] : [$like, $like, $q];
        if ($speakerId !== null) {
            $scope .= ' AND v.speaker_id = ?';
            $scopeParams[] = $speakerId;
        }
        foreach ($this->browse->videosWhere("($text)$scope", [...$params, ...$scopeParams], 'v.created_at DESC', 200) as $v) {
            $v['score'] = self::score($q, (string) $v['title'], '', (string) ($v['description'] ?? ''), $transcripts ? (string) ($v['transcript'] ?? '') : '') ?: 15;
            unset($v['transcript']);
            $out['videos'][] = $v;
        }

        // Speakers.
        if ($categoryId === null && $speakerId === null) {
            foreach ($this->app->db()->all('SELECT id, name, slug, photo_url FROM {{speakers}} WHERE name LIKE ? ORDER BY name LIMIT 50', [$like]) as $sp) {
                $out['speakers'][] = $sp + ['score' => self::score($q, (string) $sp['name'])];
            }
        }

        // The fuzzy fallback, per kind that came back empty.
        if ($out['series'] === [] && $speakerId === null) {
            [$scope, $scopeParams] = $this->scope('s.category_id', $within);
            $out['series'] = $this->fuzzy($q, $this->browse->seriesWhere('1 = 1' . $scope, $scopeParams, 's.created_at DESC', self::FUZZY_CANDIDATES), 'title');
            $out['fuzzy'] = $out['series'] !== [];
        }
        if ($out['videos'] === []) {
            [$scope, $scopeParams] = $this->scope('COALESCE(v.category_id, s.category_id)', $within);
            if ($speakerId !== null) {
                $scope .= ' AND v.speaker_id = ?';
                $scopeParams[] = $speakerId;
            }
            $out['videos'] = $this->fuzzy($q, $this->browse->videosWhere('1 = 1' . $scope, $scopeParams, 'v.created_at DESC', self::FUZZY_CANDIDATES), 'title');
            $out['fuzzy'] = $out['fuzzy'] || $out['videos'] !== [];
        }

        $out['extra'] = array_values(array_filter((array) $this->app->hooks->apply('content.search_sources', [], $q, $filters, $this->app), 'is_array'));

        foreach (['categories', 'series', 'videos', 'speakers'] as $kind) {
            usort($out[$kind], $newest && $kind !== 'categories' && $kind !== 'speakers'
                ? fn ($a, $b) => strcmp((string) ($b['publish_at'] ?? $b['created_at']), (string) ($a['publish_at'] ?? $a['created_at']))
                : fn ($a, $b) => [$b['score'], (string) ($a['title'] ?? $a['name'])] <=> [$a['score'], (string) ($b['title'] ?? $b['name'])]);
            $out[$kind] = array_slice($out[$kind], 0, 50);
        }
        return $out;
    }

    /**
     * @param list<string>|null $within category ids
     * @return array{0: string, 1: list<string>}
     */
    private function scope(string $column, ?array $within): array
    {
        if ($within === null) {
            return ['', []];
        }
        return [" AND $column IN (" . implode(', ', array_fill(0, count($within), '?')) . ')', $within];
    }

    /**
     * @param list<array<string, mixed>> $candidates
     * @return list<array<string, mixed>>
     */
    private function fuzzy(string $q, array $candidates, string $field): array
    {
        $out = [];
        foreach ($candidates as $c) {
            $similarity = self::similarity($q, (string) $c[$field]);
            if ($similarity >= self::FUZZY_THRESHOLD) {
                $out[] = $c + ['score' => (int) round($similarity * 50)];
            }
        }
        return $out;
    }
}

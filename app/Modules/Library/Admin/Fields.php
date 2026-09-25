<?php

declare(strict_types=1);

namespace App\Modules\Library\Admin;

use App\Core\ApiError;
use App\Core\Validator;
use App\Modules\Branding\Branding;

/**
 * Each library type's editable fields: the JSON name the API takes (Prisma's),
 * the column it lands in, and its rule. Nothing outside these lists reaches
 * an INSERT or UPDATE.
 */
final class Fields
{
    private const PUBLISHING = [
        'published' => ['published', ['bool']],
        'publishAt' => ['publish_at', ['datetime', 'nullable']],
        'unpublishAt' => ['unpublish_at', ['datetime', 'nullable']],
        'hidden' => ['hidden', ['bool']],
        'memberOnly' => ['member_only', ['bool']],
        'downloadEnabled' => ['download_enabled', ['bool', 'nullable']],
    ];

    public const CATEGORY = self::PUBLISHING + [
        'name' => ['name', ['string', 'required', 'max' => 255]],
        'slug' => ['slug', ['string', 'max' => 80, 'pattern' => '/^[a-z0-9]+(?:-[a-z0-9]+)*$/']],
        'description' => ['description', ['text', 'nullable', 'max' => 10_000]],
        'coverImageUrl' => ['cover_image_url', ['string', 'nullable', 'max' => 2000]],
        'tags' => ['tags', ['array', 'max' => 30]],
        'featured' => ['featured', ['bool']],
        'pinned' => ['pinned', ['bool']],
        'requireSequential' => ['require_sequential', ['bool']],
        'hymnalStyle' => ['hymnal_style', ['bool']],
        'parentId' => ['parent_id', ['id', 'nullable']],
    ];

    public const SERIES = self::PUBLISHING + [
        'title' => ['title', ['string', 'required', 'max' => 255]],
        'slug' => ['slug', ['string', 'max' => 80, 'pattern' => '/^[a-z0-9]+(?:-[a-z0-9]+)*$/']],
        'description' => ['description', ['text', 'nullable', 'max' => 20_000]],
        'language' => ['language', ['string', 'nullable', 'max' => 35, 'pattern' => '/^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8})*$/']],
        'coverImageUrl' => ['cover_image_url', ['string', 'nullable', 'max' => 2000]],
        'abbreviation' => ['abbreviation', ['string', 'nullable', 'max' => 32]],
        'hymnPerFile' => ['hymn_per_file', ['bool']],
        'featured' => ['featured', ['bool']],
        'pinned' => ['pinned', ['bool']],
        'tags' => ['tags', ['array', 'max' => 30]],
        'requireSequential' => ['require_sequential', ['bool']],
        'categoryId' => ['category_id', ['id', 'nullable']],
    ];

    public const VIDEO = self::PUBLISHING + [
        'title' => ['title', ['string', 'required', 'max' => 255]],
        'slug' => ['slug', ['string', 'max' => 80, 'pattern' => '/^[a-z0-9]+(?:-[a-z0-9]+)*$/']],
        'description' => ['description', ['text', 'nullable', 'max' => 20_000]],
        'language' => ['language', ['string', 'nullable', 'max' => 35, 'pattern' => '/^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8})*$/']],
        'seriesId' => ['series_id', ['id', 'nullable']],
        'categoryId' => ['category_id', ['id', 'nullable']],
        'speakerId' => ['speaker_id', ['id', 'nullable']],
        'scriptureRefs' => ['scripture_refs', ['array', 'max' => 20, 'of' => 'string', 'each' => ['max' => 100]]],
        'isPremiere' => ['is_premiere', ['bool']],
        'noteOutline' => ['note_outline', ['text', 'nullable', 'max' => 50_000]],
        'transcript' => ['transcript', ['text', 'nullable', 'max' => 2_000_000]],
    ];

    /** Files have no download setting of their own (a file is its own download). */
    public const FILE = [
        'published' => ['published', ['bool']],
        'publishAt' => ['publish_at', ['datetime', 'nullable']],
        'unpublishAt' => ['unpublish_at', ['datetime', 'nullable']],
        'hidden' => ['hidden', ['bool']],
        'memberOnly' => ['member_only', ['bool']],
        'title' => ['title', ['string', 'required', 'max' => 255]],
        'seriesId' => ['series_id', ['id', 'nullable']],
        'categoryId' => ['category_id', ['id', 'nullable']],
        'pageNumber' => ['page_number', ['int', 'nullable', 'min' => 0, 'max' => 100_000]],
        'groupLabel' => ['group_label', ['string', 'nullable', 'max' => 255]],
        'ccliNumber' => ['ccli_number', ['string', 'nullable', 'max' => 64]],
        'songAuthor' => ['song_author', ['string', 'nullable', 'max' => 500]],
        'songCopyright' => ['song_copyright', ['string', 'nullable', 'max' => 500]],
        'musicalKey' => ['musical_key', ['string', 'nullable', 'max' => 16]],
        'tempoBpm' => ['tempo_bpm', ['int', 'nullable', 'min' => 1, 'max' => 400]],
        'pageOffset' => ['page_offset', ['int', 'min' => -10_000, 'max' => 10_000]],
        'podcastPublished' => ['podcast_published', ['bool']],
    ];

    /**
     * @param array<string, array{0: string, 1: array<int|string, mixed>}> $spec
     * @param array<string, mixed> $input
     * @return array<string, mixed> input key => cleaned value
     */
    public static function read(array $spec, array $input, bool $partial): array
    {
        $rules = [];
        foreach ($spec as $key => [, $rule]) {
            $rules[$key] = $rule;
        }
        $data = Validator::check($input, $rules, $partial);
        if (array_key_exists('coverImageUrl', $data) && $data['coverImageUrl'] !== null && !Branding::isAcceptableLogo((string) $data['coverImageUrl'])) {
            throw ApiError::invalid('The cover image must be an https:// address or an image uploaded here.');
        }
        if (array_key_exists('tags', $data)) {
            $data['tags'] = Catalog::cleanTags((array) $data['tags']);
        }
        if (($data['publishAt'] ?? null) instanceof \DateTimeImmutable && ($data['unpublishAt'] ?? null) instanceof \DateTimeImmutable && $data['unpublishAt'] <= $data['publishAt']) {
            throw ApiError::invalid('The expiry must come after the publish time.');
        }
        return $data;
    }

    /**
     * @param array<string, array{0: string, 1: array<int|string, mixed>}> $spec
     * @param array<string, mixed> $data
     * @return array<string, mixed> column => value
     */
    public static function columns(array $spec, array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            $out[$spec[$key][0]] = $value;
        }
        return $out;
    }
}

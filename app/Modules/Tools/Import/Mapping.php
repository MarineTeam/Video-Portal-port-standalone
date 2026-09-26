<?php

declare(strict_types=1);

namespace App\Modules\Tools\Import;

/**
 * What a row from the Next.js deployment is called here.
 *
 * The old database is Prisma's: PascalCase table names, camelCase columns,
 * Postgres arrays. This one is snake_case throughout, and a few columns were
 * deliberately renamed during the port. Nearly all of it converts by rule —
 * `displayName` is `display_name` and always was — so this class is the rule
 * plus the short list of places the rule is wrong.
 *
 * The list is short on purpose. Every entry is a decision somebody made in
 * the port and has to remember here, so the test asserts that each one names
 * a column that really exists: a rename made in the schema and forgotten
 * here fails the build rather than the import.
 */
final class Mapping
{
    /**
     * Model name in the old schema => table here.
     *
     * @var array<string, string>
     */
    public const TABLES = [
        'User' => 'users',
        'UserIdentity' => 'user_identities',
        'CategoryEditor' => 'category_editors',
        'SeriesEditor' => 'series_editors',
        'Category' => 'categories',
        'Series' => 'series',
        'Video' => 'videos',
        'Chapter' => 'chapters',
        'Speaker' => 'speakers',
        'SeriesFavorite' => 'series_favorites',
        'VideoFavorite' => 'video_favorites',
        'BookHymn' => 'book_hymns',
        'BookPage' => 'book_pages',
        'BookHymnDetail' => 'book_hymn_details',
        'FileFavorite' => 'file_favorites',
        'ServicePlan' => 'service_plans',
        'ServiceTeam' => 'service_teams',
        'ServiceTeamMember' => 'service_team_members',
        'ServiceAssignment' => 'service_assignments',
        'ServiceBlockout' => 'service_blockouts',
        'ServicePlanItem' => 'service_plan_items',
        'Comment' => 'comments',
        'CommentReport' => 'comment_reports',
        'WatchProgress' => 'watch_progresses',
        'FileAsset' => 'file_assets',
        'ReadingProgress' => 'reading_progresses',
        'ReadingMark' => 'reading_marks',
        'ApiKey' => 'api_keys',
        'AuditLog' => 'audit_logs',
        'Plugin' => 'plugins',
        'PluginCategoryOverride' => 'plugin_category_overrides',
        'PermissionGroup' => 'permission_groups',
        'GroupAssignment' => 'group_assignments',
        'Rating' => 'ratings',
        'SeriesWatchLater' => 'series_watch_laters',
        'CategoryWatchLater' => 'category_watch_laters',
        'VideoWatchLater' => 'video_watch_laters',
        'PushSubscription' => 'push_subscriptions',
        'DraftRevision' => 'draft_revisions',
        'Webhook' => 'webhooks',
        'Announcement' => 'announcements',
        'LiveStream' => 'live_streams',
        'HomeRow' => 'home_rows',
        'Subscription' => 'subscriptions',
        'PendingNotification' => 'pending_notifications',
        'Playlist' => 'playlists',
        'PlaylistItem' => 'playlist_items',
        'Reaction' => 'reactions',
        'ViewEvent' => 'view_events',
        'HymnLookup' => 'hymn_lookups',
        'SeriesViewerGroup' => 'series_viewer_groups',
        'SeriesViewer' => 'series_viewers',
        'VideoViewerGroup' => 'video_viewer_groups',
        'VideoViewer' => 'video_viewers',
        'SermonOutlineAnswer' => 'sermon_outline_answers',
        'SermonNote' => 'sermon_notes',
        'SlugAlias' => 'slug_aliases',
        'ShareLink' => 'share_links',
        'ShareLinkRecipient' => 'share_link_recipients',
        'DownloadPolicy' => 'download_policies',
        'DownloadPolicyGroup' => 'download_policy_groups',
        'DownloadPolicyUser' => 'download_policy_users',
        'AuthSettings' => 'auth_settings',
        'AuthorizedEmail' => 'authorized_emails',
        'UnauthorizedAccessAttempt' => 'unauthorized_access_attempts',
        'Notification' => 'notifications',
        'BrandSettings' => 'brand_settings',
        'Schedule' => 'schedules',
        'ScheduleSource' => 'schedule_sources',
        'Person' => 'people',
        'PersonAlias' => 'person_aliases',
        'CalendarEvent' => 'calendar_events',
        'CalendarEventPerson' => 'calendar_event_people',
        'Event' => 'events',
        'EventSeries' => 'event_series',
        'EventRegistration' => 'event_registrations',
        'Form' => 'forms',
        'FormField' => 'form_fields',
        'FormSubmission' => 'form_submissions',
        'FormAnswer' => 'form_answers',
        'PrayerRequest' => 'prayer_requests',
        'PrayerIntercession' => 'prayer_intercessions',
        'SmallGroup' => 'small_groups',
        'SmallGroupMember' => 'small_group_members',
        'GroupMessage' => 'group_messages',
        'DiscussionGuide' => 'discussion_guides',
        'DiscussionGuideItem' => 'discussion_guide_items',
        'SmallGroupMeeting' => 'small_group_meetings',
        'GroupAttendance' => 'group_attendances',
        'Broadcast' => 'broadcasts',
        'BroadcastRecipient' => 'broadcast_recipients',
        'VideoFeed' => 'video_feeds',
        'LiveChatMessage' => 'live_chat_messages',
        'LiveChatMute' => 'live_chat_mutes',
        'TvDevice' => 'tv_devices',
    ];

    /**
     * Models that are not carried over at all, and the line the screen shows
     * for each. A credential that only means something to the old
     * deployment is not data: copying it would move a live secret into a
     * second place without making anything work.
     *
     * @var array<string, string>
     */
    public const SKIPPED = [
        'PushSubscription' => 'Push subscriptions are tied to the old site’s VAPID keys. Members turn notifications back on once, per device.',
        'TvDevice' => 'Television devices hold a login token each. Pair the screens again — it takes a minute per screen.',
    ];

    /**
     * Where the rule is wrong: model => old column => column here.
     *
     * Nothing needs to be listed here to be left out. A column with no
     * counterpart in this schema is reported as dropped by the importer
     * without being named anywhere, which is the honest way round: the
     * screen says what it could not place rather than a list here quietly
     * excusing it.
     *
     * @var array<string, array<string, string>>
     */
    public const COLUMNS = [
        // `source` said which player fills the frame and was always one of a
        // fixed few; `provider` says the same and is open to plugins.
        'Video' => [
            'source' => 'provider',
            // Bunny's guid is this video's id at its provider, which is what
            // the (provider, external_id) index is for. Rows from YouTube and
            // Vimeo already carry theirs in `externalId`; `extras()` below is
            // what settles a row that has both.
            'bunnyVideoId' => 'external_id',
        ],
        // Files gained a backend, so the path is no longer Bunny's by
        // definition.
        'FileAsset' => ['bunnyPath' => 'storage_path'],
    ];

    /**
     * Enum values that were lower-cased in the port. VideoSource had exactly
     * three values in the old schema — the other providers here did not
     * exist yet — so three is the whole list, not a shortened one. A value
     * that is not listed is kept verbatim: one invented by a plugin has no
     * business being rewritten.
     *
     * @var array<string, array<string, array<string, string>>>
     */
    public const VALUES = [
        'Video' => ['provider' => ['BUNNY' => 'bunny', 'YOUTUBE' => 'youtube', 'VIMEO' => 'vimeo']],
    ];

    /**
     * Columns this schema has and the old one did not, worked out from the
     * row rather than left to the column default.
     *
     * @param array<string, mixed> $old the row as it was exported
     * @return array<string, mixed> column here => value
     */
    public static function extras(string $model, array $old): array
    {
        return match ($model) {
            'Video' => [
                'external_id' => self::videoExternalId($old),
                'provider_data' => self::videoProviderData($old),
            ],
            // Every file in the old deployment that had a path had it in
            // Bunny Storage. One with no path is a link-by-URL, which
            // belongs to no provider and keeps the column's own default.
            'FileAsset' => self::text($old['bunnyPath'] ?? null) === null
                ? ['backend' => 'local', 'storage_path' => '']
                : ['backend' => 'bunny'],
            default => [],
        };
    }

    /**
     * Postgres arrays that become rows in a second table here, as well as
     * the JSON column that keeps the array itself.
     *
     * Model => old column => [table, the column naming the parent, the
     * column holding one value, whether to lower-case it].
     *
     * @var array<string, array<string, array{0: string, 1: string, 2: string, 3: bool}>>
     */
    public const FANOUT = [
        'Series' => ['tags' => ['series_tags', 'series_id', 'tag', true]],
        'Video' => ['scriptureRefs' => ['video_scripture_books', 'video_id', 'book', false]],
    ];

    /** The table a model's rows go into. */
    public static function tableFor(string $model): ?string
    {
        return self::TABLES[$model] ?? null;
    }

    /** camelCase => snake_case, the rule the overrides above are exceptions to. */
    public static function snake(string $field): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $field));
    }

    /** The column one of a model's old columns writes to. */
    public static function column(string $model, string $field): string
    {
        return self::COLUMNS[$model][$field] ?? self::snake($field);
    }

    /**
     * A video's id at its provider. Bunny rows carry it as the guid, every
     * other provider as `externalId`; a row with both is a Bunny row that
     * was once imported from somewhere else, and the guid is what plays.
     *
     * @param array<string, mixed> $old the row as it was exported
     */
    public static function videoExternalId(array $old): ?string
    {
        $bunny = self::text($old['bunnyVideoId'] ?? null);
        $external = self::text($old['externalId'] ?? null);
        return ($old['source'] ?? 'BUNNY') === 'BUNNY' ? ($bunny ?? $external) : ($external ?? $bunny);
    }

    /**
     * Everything about where a video lives that has no column of its own.
     * Empty for the ordinary case, which keeps the column honest: something
     * in here means something unusual about this row.
     *
     * @param array<string, mixed> $old
     */
    public static function videoProviderData(array $old): string
    {
        $data = [];
        $bunny = self::text($old['bunnyVideoId'] ?? null);
        $external = self::text($old['externalId'] ?? null);
        // Both, and they differ: keep the one external_id did not take, or
        // the fact that this row came from a feed is lost.
        if ($bunny !== null && $external !== null && $bunny !== $external) {
            $data[($old['source'] ?? 'BUNNY') === 'BUNNY' ? 'importedFrom' : 'bunnyVideoId'] = ($old['source'] ?? 'BUNNY') === 'BUNNY' ? $external : $bunny;
        }
        // An object even when empty: everything that reads this column reads
        // keys off it, and an empty PHP array encodes as [].
        return (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_FORCE_OBJECT);
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}

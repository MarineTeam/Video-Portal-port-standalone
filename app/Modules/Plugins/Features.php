<?php

declare(strict_types=1);

namespace App\Modules\Plugins;

/**
 * The 31 bundled features (Appendix E.1), each a bundled plugin in plugins/.
 * The query-monitor row is not one of them: it is an ops switch stored in the
 * same table and deliberately left out of every plugin list.
 */
final class Features
{
    public const QUERY_MONITOR = 'query-monitor';

    /** @var list<array{slug: string, name: string, description: string}> */
    public const META = [
        ['slug' => 'favorites', 'name' => 'Favorites', 'description' => 'Lets members bookmark series and videos to a My Favorites page.'],
        ['slug' => 'comments', 'name' => 'Comments', 'description' => 'Lets members discuss a series or video underneath it.'],
        ['slug' => 'related-content', 'name' => 'Related content', 'description' => 'Shows "More like this" / "You might also like" rows.'],
        ['slug' => 'ratings', 'name' => 'Ratings', 'description' => 'Lets members leave a 1-5 star rating on a series or video.'],
        ['slug' => 'watch-later', 'name' => 'Watch later', 'description' => 'Lets members queue a series or video to a Watch Later page, separate from Favorites.'],
        ['slug' => 'notifications', 'name' => 'Notifications', 'description' => 'Sends a web push notification to subscribed members when new content is published.'],
        ['slug' => 'view-counts', 'name' => 'View counts', 'description' => 'Shows a play/view counter on series and video pages.'],
        ['slug' => 'social-share', 'name' => 'Social share', 'description' => 'Shows copy-link and share-to buttons on series and video pages.'],
        ['slug' => 'announcements', 'name' => 'Announcements', 'description' => 'Shows a dismissible site-wide banner message.'],
        ['slug' => 'subscriptions', 'name' => 'Subscriptions', 'description' => 'Lets members follow a series or category and get notified when it publishes new content.'],
        ['slug' => 'playlists', 'name' => 'Playlists', 'description' => 'Lets members build their own ordered video playlists.'],
        ['slug' => 'likes-dislikes', 'name' => 'Likes / dislikes', 'description' => 'Lets members like or dislike a series or video.'],
        ['slug' => 'up-next', 'name' => 'Up next', 'description' => 'Shows an "Up next" panel with the next video in a series, with an autoplay option.'],
        ['slug' => 'watch-history', 'name' => 'Watch history', 'description' => 'Shows a "Recently Played" page and nav tab of everything a member has watched.'],
        ['slug' => 'profiles', 'name' => 'Profiles', 'description' => 'Lets members set a display name shown instead of their sign-in name in comments and the navbar.'],
        ['slug' => 'chapters', 'name' => 'Chapters', 'description' => 'Shows a jump-to-section chapter list under a video, admin-managed per video.'],
        ['slug' => 'transcripts', 'name' => 'Transcripts', 'description' => 'Shows a collapsible full-text transcript under a video and includes it in search results.'],
        ['slug' => 'recommendations', 'name' => 'Recommendations', 'description' => 'Shows a personalized "Because you watched" row on the homepage, based on the member\'s most recent watch.'],
        ['slug' => 'webhooks', 'name' => 'Webhooks', 'description' => 'Posts a JSON payload to admin-configured URLs whenever a series or video is published.'],
        ['slug' => 'live-streaming', 'name' => 'Live streaming', 'description' => 'Shows a "Live now" banner and /live page for admin-scheduled live streams, with a push notification when one goes live.'],
        ['slug' => 'sermon-notes', 'name' => 'Sermon notes', 'description' => 'Lets members keep their own timestamped notes on a video, exportable as a text file.'],
        ['slug' => 'share-links', 'name' => 'Share links', 'description' => 'Lets members create revocable share links to a series or video, public or emailed to specific people.'],
        ['slug' => 'downloads', 'name' => 'Downloads', 'description' => 'Lets members download videos to their device for offline viewing, with per-category/series/video control at /admin/downloads.'],
        ['slug' => 'service-plans', 'name' => 'Service plans', 'description' => 'Lets staff publish the running order of hymns for a service, which members open as one list at /services.'],
        ['slug' => 'book-reader', 'name' => 'Book reader', 'description' => 'Opens PDF and EPUB files in an in-app reader with contents, search, highlights and read-aloud, instead of only offering them as downloads.'],
        ['slug' => 'schedules', 'name' => 'Schedules', 'description' => 'Rotas anyone can read at /calendar — fed from a Google Sheet or managed here. Names rather than accounts, for the people who never log in.'],
        ['slug' => 'events', 'name' => 'Events', 'description' => 'Published events at /events with sign-up: capacity, a waiting list that moves when somebody drops out, and guests.'],
        ['slug' => 'tv', 'name' => 'Television', 'description' => 'A remote-friendly /tv screen, a catalogue feed a Roku channel can be built from, and sign-in by a code on the screen so nobody types a password with a remote.'],
        ['slug' => 'groups', 'name' => 'Small groups', 'description' => 'A directory of home groups at /groups, with join requests a leader answers. The address is given only to people in the group.'],
        ['slug' => 'prayer', 'name' => 'Prayer wall', 'description' => 'A moderated wall of prayer requests at /prayer, with an anonymous option and an "I prayed for this" count. Nothing appears until it is let through.'],
        ['slug' => 'forms', 'name' => 'Forms', 'description' => 'Connect cards and sign-up forms built here rather than in code, filled in at /forms, with the responses kept and exportable.'],
    ];

    /** @return list<string> */
    public static function slugs(): array
    {
        return array_column(self::META, 'slug');
    }
}

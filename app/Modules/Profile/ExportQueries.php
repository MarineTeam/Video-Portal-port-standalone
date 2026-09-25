<?php

declare(strict_types=1);

namespace App\Modules\Profile;

/**
 * Every read behind "Download my data", in one place so a test can read this
 * file as text and check each one: scoped to one member (:user, or :email for
 * the rows kept against an address), and naming every column it selects —
 * no SELECT *, so a column added to a table never leaks into the file by
 * itself. Whatever needs deciding per row (an address, a push endpoint) is
 * decided in DataExport, not here.
 *
 * Section => [table whose column types apply, SQL].
 */
final class ExportQueries
{
    public const QUERIES = [
        'account' => ['users', 'SELECT id, email, name, display_name, picture, role, authorized, notification_frequency, email_notifications, phone, sms_opt_in, broadcast_emails, directory_listed, directory_show_email, directory_show_phone, directory_note, (password_hash IS NOT NULL) AS has_password, email_verified_at, created_at, updated_at FROM {{users}} WHERE id = :user'],
        'signInMethods' => ['user_identities', 'SELECT provider, email, email_verified, last_login_at, created_at FROM {{user_identities}} WHERE user_id = :user ORDER BY created_at'],
        'signedInBrowsers' => ['sessions', 'SELECT created_at, last_seen_at, ip, user_agent FROM {{sessions}} WHERE user_id = :user ORDER BY created_at'],

        'favoriteSeries' => ['series_favorites', 'SELECT f.series_id, s.title AS series_title, f.created_at FROM {{series_favorites}} f LEFT JOIN {{series}} s ON s.id = f.series_id WHERE f.user_id = :user ORDER BY f.created_at'],
        'favoriteVideos' => ['video_favorites', 'SELECT f.video_id, v.title AS video_title, f.created_at FROM {{video_favorites}} f LEFT JOIN {{videos}} v ON v.id = f.video_id WHERE f.user_id = :user ORDER BY f.created_at'],
        'favoriteFiles' => ['file_favorites', 'SELECT f.file_id, a.title AS file_title, f.created_at FROM {{file_favorites}} f LEFT JOIN {{file_assets}} a ON a.id = f.file_id WHERE f.user_id = :user ORDER BY f.created_at'],
        'watchLaterSeries' => ['series_watch_laters', 'SELECT w.series_id, s.title AS series_title, w.created_at FROM {{series_watch_laters}} w LEFT JOIN {{series}} s ON s.id = w.series_id WHERE w.user_id = :user ORDER BY w.created_at'],
        'watchLaterCategories' => ['category_watch_laters', 'SELECT w.category_id, c.name AS category_name, w.created_at FROM {{category_watch_laters}} w LEFT JOIN {{categories}} c ON c.id = w.category_id WHERE w.user_id = :user ORDER BY w.created_at'],
        'watchLaterVideos' => ['video_watch_laters', 'SELECT w.video_id, v.title AS video_title, w.created_at FROM {{video_watch_laters}} w LEFT JOIN {{videos}} v ON v.id = w.video_id WHERE w.user_id = :user ORDER BY w.created_at'],
        'follows' => ['subscriptions', 'SELECT series_id, category_id, muted, created_at FROM {{subscriptions}} WHERE user_id = :user ORDER BY created_at'],
        'playlists' => ['playlists', 'SELECT id, title, public, created_at, updated_at FROM {{playlists}} WHERE user_id = :user ORDER BY created_at'],
        'playlistItems' => ['playlist_items', 'SELECT i.playlist_id, i.video_id, v.title AS video_title, i.position, i.created_at FROM {{playlist_items}} i JOIN {{playlists}} p ON p.id = i.playlist_id LEFT JOIN {{videos}} v ON v.id = i.video_id WHERE p.user_id = :user ORDER BY i.playlist_id, i.position'],
        'ratings' => ['ratings', 'SELECT series_id, video_id, value, created_at, updated_at FROM {{ratings}} WHERE user_id = :user ORDER BY created_at'],
        'reactions' => ['reactions', 'SELECT series_id, video_id, type, created_at, updated_at FROM {{reactions}} WHERE user_id = :user ORDER BY created_at'],
        'watchHistory' => ['watch_progresses', 'SELECT w.video_id, v.title AS video_title, w.position_seconds, w.completed, w.updated_at FROM {{watch_progresses}} w LEFT JOIN {{videos}} v ON v.id = w.video_id WHERE w.user_id = :user ORDER BY w.updated_at'],
        'views' => ['view_events', 'SELECT series_id, video_id, created_at FROM {{view_events}} WHERE user_id = :user ORDER BY created_at'],

        'readingPositions' => ['reading_progresses', 'SELECT file_id, location, percent, updated_at FROM {{reading_progresses}} WHERE user_id = :user ORDER BY updated_at'],
        'readingMarks' => ['reading_marks', 'SELECT file_id, kind, location, end_location, excerpt, note, color, created_at, updated_at FROM {{reading_marks}} WHERE user_id = :user ORDER BY created_at'],
        'hymnLookups' => ['hymn_lookups', 'SELECT file_id, number, source, created_at FROM {{hymn_lookups}} WHERE user_id = :user ORDER BY created_at'],
        'sermonNotes' => ['sermon_notes', 'SELECT video_id, timestamp_seconds, body, created_at, updated_at FROM {{sermon_notes}} WHERE user_id = :user ORDER BY created_at'],
        'outlineAnswers' => ['sermon_outline_answers', 'SELECT video_id, answers, outline_version, updated_at FROM {{sermon_outline_answers}} WHERE user_id = :user ORDER BY updated_at'],

        // A reply says it is one; the comment above it is somebody else's.
        'comments' => ['comments', 'SELECT id, series_id, video_id, body, (parent_id IS NOT NULL) AS is_reply, hidden, created_at FROM {{comments}} WHERE user_id = :user ORDER BY created_at'],
        'commentReports' => ['comment_reports', 'SELECT comment_id, created_at FROM {{comment_reports}} WHERE user_id = :user ORDER BY created_at'],

        'teams' => ['service_team_members', 'SELECT m.team_id, t.name AS team_name, m.position, m.joined_at FROM {{service_team_members}} m LEFT JOIN {{service_teams}} t ON t.id = m.team_id WHERE m.user_id = :user ORDER BY m.joined_at'],
        'rotaAssignments' => ['service_assignments', 'SELECT a.plan_id, p.title AS plan_title, p.service_date, a.team_id, a.position, a.status, a.note, a.responded_at, a.cover_wanted, a.cover_note, a.cover_asked_at, (a.covered_for_id IS NOT NULL) AS covering_for_someone, a.covered_at, a.created_at FROM {{service_assignments}} a LEFT JOIN {{service_plans}} p ON p.id = a.plan_id WHERE a.user_id = :user ORDER BY a.created_at'],
        // Who stepped in for them is somebody else's row: the date, not the name.
        'rotaCovered' => ['service_assignments', 'SELECT a.plan_id, p.service_date, a.position, a.covered_at FROM {{service_assignments}} a LEFT JOIN {{service_plans}} p ON p.id = a.plan_id WHERE a.covered_for_id = :user ORDER BY a.covered_at'],
        'unavailableDates' => ['service_blockouts', 'SELECT start_date, end_date, reason, created_at FROM {{service_blockouts}} WHERE user_id = :user ORDER BY start_date'],
        'scheduleNames' => ['people', 'SELECT display_name, active, created_at FROM {{people}} WHERE user_id = :user ORDER BY created_at'],
        'scheduleDates' => ['calendar_events', 'SELECT e.date, e.end_date, e.all_day, e.start_time, e.end_time, e.title, e.location, ep.role, ep.position FROM {{calendar_event_people}} ep JOIN {{calendar_events}} e ON e.id = ep.event_id WHERE ep.person_id IN (SELECT id FROM {{people}} WHERE user_id = :user) ORDER BY e.date'],

        'eventSignUps' => ['event_registrations', 'SELECT r.event_id, e.title AS event_title, e.starts_at, r.name, r.email, r.phone, r.guests, r.note, r.status, r.promoted_at, r.cancelled_at, r.created_at FROM {{event_registrations}} r LEFT JOIN {{events}} e ON e.id = r.event_id WHERE r.user_id = :user ORDER BY r.created_at'],
        'formSubmissions' => ['form_submissions', 'SELECT s.id, s.form_id, f.title AS form_title, s.handled_at, s.created_at FROM {{form_submissions}} s LEFT JOIN {{forms}} f ON f.id = s.form_id WHERE s.user_id = :user ORDER BY s.created_at'],
        'formAnswers' => ['form_answers', 'SELECT a.submission_id, fl.label AS field_label, a.value FROM {{form_answers}} a JOIN {{form_submissions}} s ON s.id = a.submission_id LEFT JOIN {{form_fields}} fl ON fl.id = a.field_id WHERE s.user_id = :user ORDER BY a.submission_id, fl.position'],
        'prayerRequests' => ['prayer_requests', 'SELECT id, name, body, anonymous, visibility, status, answered_note, answered_at, created_at, updated_at FROM {{prayer_requests}} WHERE user_id = :user ORDER BY created_at'],
        // A prayer they prayed for is somebody else's words: the id and the day.
        'prayedFor' => ['prayer_intercessions', 'SELECT request_id, created_at FROM {{prayer_intercessions}} WHERE user_id = :user ORDER BY created_at'],

        'smallGroups' => ['small_group_members', 'SELECT m.group_id, g.name AS group_name, g.meets_when, g.area, g.address, m.role, m.status, m.muted, m.note, m.responded_at, m.created_at FROM {{small_group_members}} m JOIN {{small_groups}} g ON g.id = m.group_id WHERE m.user_id = :user ORDER BY m.created_at'],
        'groupMessages' => ['group_messages', 'SELECT group_id, body, hidden, created_at FROM {{group_messages}} WHERE user_id = :user ORDER BY created_at'],
        'groupAttendance' => ['group_attendances', 'SELECT a.meeting_id, mt.group_id, mt.date, a.status, a.note, a.created_at FROM {{group_attendances}} a LEFT JOIN {{small_group_meetings}} mt ON mt.id = a.meeting_id WHERE a.user_id = :user ORDER BY a.created_at'],

        'notifications' => ['notifications', 'SELECT title, body, url, read_at, created_at FROM {{notifications}} WHERE user_id = :user ORDER BY created_at'],
        'queuedNotifications' => ['pending_notifications', 'SELECT title, body, url, created_at FROM {{pending_notifications}} WHERE user_id = :user ORDER BY created_at'],
        // The announcement, not the staff member who sent it.
        'announcementsReceived' => ['broadcast_recipients', 'SELECT b.subject, b.body, r.channel, r.address, r.status, r.sent_at, r.delivery_status, r.delivered_at FROM {{broadcast_recipients}} r JOIN {{broadcasts}} b ON b.id = r.broadcast_id WHERE r.user_id = :user ORDER BY r.sent_at'],
        'emailsSent' => ['email_log', 'SELECT subject, status, created_at FROM {{email_log}} WHERE to_address = :email ORDER BY created_at'],
        'liveChatMessages' => ['live_chat_messages', 'SELECT stream_id, body, hidden, created_at FROM {{live_chat_messages}} WHERE user_id = :user ORDER BY created_at'],
        'liveChatMutes' => ['live_chat_mutes', 'SELECT stream_id, created_at FROM {{live_chat_mutes}} WHERE user_id = :user ORDER BY created_at'],

        // The endpoint is the key to that phone: DataExport keeps only its service.
        'pushDevices' => ['push_subscriptions', 'SELECT endpoint, created_at FROM {{push_subscriptions}} WHERE user_id = :user ORDER BY created_at'],
        'televisions' => ['tv_devices', 'SELECT device_name, device_kind, status, approved_at, linked_at, last_seen_at, revoked_at, created_at FROM {{tv_devices}} WHERE user_id = :user ORDER BY created_at'],
        'shareLinks' => ['share_links', 'SELECT id, series_id, video_id, visibility, grants_access, note, (password_hash IS NOT NULL) AS has_password, expires_at, revoked_at, view_count, last_viewed_at, created_at FROM {{share_links}} WHERE created_by_id = :user ORDER BY created_at'],
        'shareLinkRecipients' => ['share_link_recipients', 'SELECT r.share_link_id, r.email, r.created_at FROM {{share_link_recipients}} r JOIN {{share_links}} l ON l.id = r.share_link_id WHERE l.created_by_id = :user ORDER BY r.created_at'],
        'sharedWithYou' => ['share_links', 'SELECT l.series_id, l.video_id, l.expires_at, l.revoked_at, r.created_at FROM {{share_link_recipients}} r JOIN {{share_links}} l ON l.id = r.share_link_id WHERE r.email = :email ORDER BY r.created_at'],

        'permissionGroups' => ['group_assignments', 'SELECT g.name AS group_name, g.capabilities, a.category_id, a.series_id, a.created_at FROM {{group_assignments}} a JOIN {{permission_groups}} g ON g.id = a.group_id WHERE a.user_id = :user ORDER BY a.created_at'],
        'categoryEditor' => ['category_editors', 'SELECT category_id, created_at FROM {{category_editors}} WHERE user_id = :user ORDER BY created_at'],
        'seriesEditor' => ['series_editors', 'SELECT series_id, created_at FROM {{series_editors}} WHERE user_id = :user ORDER BY created_at'],
        'seriesViewer' => ['series_viewers', 'SELECT series_id, created_at FROM {{series_viewers}} WHERE user_id = :user ORDER BY created_at'],
        'videoViewer' => ['video_viewers', 'SELECT video_id, created_at FROM {{video_viewers}} WHERE user_id = :user ORDER BY created_at'],
        'downloadPolicy' => ['download_policy_users', 'SELECT policy_id FROM {{download_policy_users}} WHERE user_id = :user'],
        'signInList' => ['authorized_emails', 'SELECT status, organization_exempt, created_at, updated_at FROM {{authorized_emails}} WHERE email = :email'],
        'refusedSignIns' => ['unauthorized_access_attempts', 'SELECT created_at, provider, attempt_type, reason, ip_address, user_agent FROM {{unauthorized_access_attempts}} WHERE email = :email ORDER BY created_at'],
        'yourActions' => ['audit_logs', 'SELECT action, entity_type, entity_id, created_at FROM {{audit_logs}} WHERE actor_email = :email ORDER BY created_at'],
    ];
}

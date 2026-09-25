# PORT_MAP — Marine Team to PHP + MySQL/MariaDB

The live status of the port described in `PORT_PROMPT.md` (the brief, with
Appendices A–J). Every page, route, model, test file and area of the original
is listed here with one of:

- `todo` — not started
- `partial` — started; the notes say what is missing
- `done` — implemented and covered by tests
- `dropped: <reason>` — deliberately not ported, with a reason a maintainer would accept

A later session should read this file first, then the brief, and pick up from
the first `todo` in the Work plan order below. Keep it current in the same
commit as the code it describes.

## Work plan progress

| Step | What | Status |
|---|---|---|
| 1 | Read the brief; write this map | done |
| 2 | Foundation: core, schema, migrator, installer, local sign-in, users and capabilities, admin shell, branding, i18n, services registry (Files: local disk, Email: mail()), jobs, plugin/theme loaders, default theme | todo |
| 3 | Library: categories, series, videos and providers, player, files, search, trash, audit, permissions, share links, downloads, feeds, sitemap, metadata; remaining sign-in, email, files providers | todo |
| 4 | Bundled plugins, simplest first | todo |
| 5 | Books/hymnals, services/rota, schedules/sheets, events, forms, prayer, groups, broadcasts/SMS, live, television, read API, export/import | todo |
| 6 | Hardening and docs: smoke test, security walk, INSTALL/PLUGINS/THEMES/UPGRADING/SERVICES, migration guide | todo |

## Areas (Feature inventory)

| Area | Kind | Status | Notes |
|---|---|---|---|
| Core framework (Router, Db, View, Session, Csrf, Http, Hooks, Cache, Jobs, Migrator, Log, errors) | core | todo | |
| Installer (`/install`) and upgrader (`/admin/update`), backups (`/admin/tools`) | core | todo | |
| Services registry and Admin → Services | core | todo | |
| Library (categories, series, videos, files, speakers, scripture, tags, search, trash, feeds, sitemap, metadata) | core | todo | |
| Access (sign-in providers, allowlist, identities, permissions, capabilities, audit, API keys) | core | todo | |
| Site (branding, i18n, nav, device settings, standalone chrome, inbox, profile, data export, video feeds, query monitor) | core | todo | |
| Read API `/api/v1` | core | todo | |
| PWA and offline shell (sw.js, offline.html, manifest) | core | todo | |
| Plugin loader, auto-deactivation, per-category overrides | core | todo | |
| Theme loader, default theme, customizer | core | todo | |
| Member plugins (favorites … downloads, 21 of Appendix E) | plugins | todo | |
| Live streaming and chat | plugin | todo | |
| Book reader, hymnals, service plans, rota | plugins | todo | |
| Schedules and Google Sheets | plugin | todo | |
| Events and event series, forms, prayer, small groups (attendance, guides, thread), directory, broadcasts and SMS | plugins | todo | |
| Television | plugin | todo | |
| Data import from the Next.js deployment (`tools/export-from-nextjs`, `/admin/tools/import`) | core | todo | |

### Service providers

| Slot | Provider | Status | Notes |
|---|---|---|---|
| auth | Local accounts (password, magic link) | todo | |
| auth | Auth0 | todo | |
| auth | OpenID Connect + presets (Google, Entra ID, Apple, Okta, Keycloak, Authentik, Zitadel, Logto, Kinde, Clerk-OIDC) | todo | |
| auth | Clerk (native) | todo | |
| auth | Supabase Auth | todo | |
| auth | Firebase Authentication | todo | |
| video | bunny.net Stream | todo | |
| video | YouTube | todo | |
| video | Vimeo | todo | |
| video | Dropbox | todo | |
| video | Google Drive | todo | |
| video | OneDrive / SharePoint | todo | |
| video | Internet Archive | todo | |
| video | S3-compatible | todo | |
| video | Direct link | todo | |
| video | Host disk | todo | |
| email | SMTP (PHPMailer, presets) | todo | |
| email | PHP mail() | todo | |
| email | Resend, Mailgun, SendGrid, Postmark, Amazon SES, Brevo, Microsoft Graph | todo | |
| files | Local disk | todo | |
| files | Bunny Storage | todo | |
| sms | Twilio, Vonage, MessageBird, Plivo, Sinch, Telnyx, Amazon SNS, ClickSend, Textlocal, BulkSMS, JSON webhook | todo | |

## Pages (Appendix C.1) — 91

| Path | Status | Notes |
|---|---|---|
| `/` | todo | |
| `/access-denied` | todo | |
| `/admin` | todo | |
| `/admin/access-attempts` | todo | |
| `/admin/analytics` | todo | |
| `/admin/announcements` | todo | |
| `/admin/api-keys` | todo | |
| `/admin/audit` | todo | |
| `/admin/authorized-emails` | todo | |
| `/admin/branding` | todo | |
| `/admin/broadcasts` | todo | |
| `/admin/categories` | todo | |
| `/admin/categories/[id]` | todo | |
| `/admin/comments` | todo | |
| `/admin/downloads` | todo | |
| `/admin/events` | todo | |
| `/admin/events/[id]` | todo | |
| `/admin/files` | todo | |
| `/admin/forms` | todo | |
| `/admin/forms/[id]` | todo | |
| `/admin/groups` | todo | |
| `/admin/groups/[id]` | todo | |
| `/admin/home-rows` | todo | |
| `/admin/live` | todo | |
| `/admin/media-check` | todo | |
| `/admin/people` | todo | |
| `/admin/permissions` | todo | |
| `/admin/plugins` | todo | |
| `/admin/prayer` | todo | |
| `/admin/query-monitor` | todo | |
| `/admin/schedules` | todo | |
| `/admin/schedules/[id]` | todo | |
| `/admin/series` | todo | |
| `/admin/series/[id]` | todo | |
| `/admin/services` | todo | |
| `/admin/services/report` | todo | |
| `/admin/share-links` | todo | |
| `/admin/speakers` | todo | |
| `/admin/teams` | todo | |
| `/admin/trash` | todo | |
| `/admin/users` | todo | |
| `/admin/video-feeds` | todo | |
| `/admin/videos` | todo | |
| `/admin/webhooks` | todo | |
| `/books/[fileId]` | todo | |
| `/calendar` | todo | |
| `/categories/[slug]` | todo | |
| `/directory` | todo | |
| `/events` | todo | |
| `/events/[slug]` | todo | |
| `/favorites` | todo | |
| `/forms` | todo | |
| `/forms/[slug]` | todo | |
| `/groups` | todo | |
| `/groups/[slug]` | todo | |
| `/guides` | todo | |
| `/guides/[slug]` | todo | |
| `/hymns/[fileId]` | todo | |
| `/link` | todo | |
| `/live` | todo | |
| `/playlists` | todo | |
| `/playlists/[id]` | todo | |
| `/prayer` | todo | |
| `/present/[fileId]` | todo | |
| `/profile` | todo | |
| `/profile/devices` | todo | |
| `/profile/downloads` | todo | |
| `/profile/events` | todo | |
| `/profile/groups` | todo | |
| `/profile/inbox` | todo | |
| `/profile/rota` | todo | |
| `/profile/settings` | todo | |
| `/profile/shared-links` | todo | |
| `/read/[fileId]` | todo | |
| `/recently-added` | todo | |
| `/recently-played` | todo | |
| `/scripture` | todo | |
| `/scripture/[book]` | todo | |
| `/search` | todo | |
| `/series/[slug]` | todo | |
| `/services` | todo | |
| `/services/[id]` | todo | |
| `/share/unavailable` | todo | |
| `/share/unlock/[token]` | todo | |
| `/speakers` | todo | |
| `/speakers/[slug]` | todo | |
| `/subscriptions` | todo | |
| `/tags/[tag]` | todo | |
| `/tv` | todo | |
| `/videos/[slug]` | todo | |
| `/watch-later` | todo | |

## Routes (Appendix C.2) — 218

| Path | Methods | Status | Notes |
|---|---|---|---|
| `/api/admin/access-attempts` | GET POST | todo | |
| `/api/admin/analytics/export` | GET | todo | |
| `/api/admin/announcements/[id]` | PATCH DELETE | todo | |
| `/api/admin/announcements` | GET POST | todo | |
| `/api/admin/api-keys/[id]` | DELETE | todo | |
| `/api/admin/api-keys` | GET POST | todo | |
| `/api/admin/assignments` | POST DELETE | todo | |
| `/api/admin/audit/export` | GET | todo | |
| `/api/admin/audit` | GET | todo | |
| `/api/admin/authorized-emails/[id]` | PATCH DELETE | todo | |
| `/api/admin/authorized-emails` | GET POST | todo | |
| `/api/admin/branding` | GET PUT DELETE | todo | |
| `/api/admin/broadcasts/[id]` | GET DELETE | todo | |
| `/api/admin/broadcasts/[id]/send` | POST | todo | |
| `/api/admin/broadcasts/[id]/test` | POST | todo | |
| `/api/admin/broadcasts` | GET POST | todo | |
| `/api/admin/bunny-audit` | GET | todo | |
| `/api/admin/calendar-events/[id]` | GET PATCH DELETE | todo | |
| `/api/admin/categories/[id]` | PATCH DELETE | todo | |
| `/api/admin/categories` | GET POST | todo | |
| `/api/admin/comments/[id]` | PATCH | todo | |
| `/api/admin/comments` | GET | todo | |
| `/api/admin/downloads` | GET PATCH | todo | |
| `/api/admin/editors/category/[id]` | DELETE | todo | |
| `/api/admin/editors/category` | POST | todo | |
| `/api/admin/editors` | GET | todo | |
| `/api/admin/editors/series/[id]` | DELETE | todo | |
| `/api/admin/editors/series` | POST | todo | |
| `/api/admin/events/[id]/registrations/[registrationId]` | DELETE | todo | |
| `/api/admin/events/[id]/registrations` | GET | todo | |
| `/api/admin/events/[id]` | PATCH DELETE | todo | |
| `/api/admin/events` | GET POST | todo | |
| `/api/admin/events/series/[id]` | PATCH DELETE | todo | |
| `/api/admin/events/series` | GET POST | todo | |
| `/api/admin/files/[id]/contents` | GET PUT | todo | |
| `/api/admin/files/[id]/lyrics` | GET PUT | todo | |
| `/api/admin/files/[id]/replace` | POST | todo | |
| `/api/admin/files/[id]` | PATCH DELETE | todo | |
| `/api/admin/files/[id]/text` | GET POST DELETE | todo | |
| `/api/admin/files/bulk` | POST | todo | |
| `/api/admin/files/bunny-storage` | GET | todo | |
| `/api/admin/files/import` | POST | todo | |
| `/api/admin/files` | GET POST | todo | |
| `/api/admin/forms/[id]/fields/[fieldId]` | PATCH DELETE | todo | |
| `/api/admin/forms/[id]/fields` | POST | todo | |
| `/api/admin/forms/[id]` | GET PATCH DELETE | todo | |
| `/api/admin/forms/[id]/submissions/[submissionId]` | PATCH DELETE | todo | |
| `/api/admin/forms/[id]/submissions` | GET | todo | |
| `/api/admin/forms` | GET POST | todo | |
| `/api/admin/group-assignments/[id]` | DELETE | todo | |
| `/api/admin/group-assignments` | GET POST | todo | |
| `/api/admin/groups/[id]/members/[memberId]` | DELETE | todo | |
| `/api/admin/groups/[id]/members` | POST | todo | |
| `/api/admin/groups/[id]` | GET PATCH DELETE | todo | |
| `/api/admin/groups` | GET POST | todo | |
| `/api/admin/guest-login` | GET PATCH | todo | |
| `/api/admin/guides/[id]` | GET PATCH DELETE | todo | |
| `/api/admin/guides` | GET POST | todo | |
| `/api/admin/home-rows/[id]` | PATCH DELETE | todo | |
| `/api/admin/home-rows` | GET POST | todo | |
| `/api/admin/live/[id]` | PATCH DELETE | todo | |
| `/api/admin/live` | GET POST | todo | |
| `/api/admin/people/[id]` | PATCH DELETE | todo | |
| `/api/admin/people/merge` | POST | todo | |
| `/api/admin/people` | GET POST | todo | |
| `/api/admin/permission-groups/[id]` | PATCH DELETE | todo | |
| `/api/admin/permission-groups` | GET POST | todo | |
| `/api/admin/plugins/[slug]/overrides` | POST | todo | |
| `/api/admin/plugins/[slug]` | PATCH | todo | |
| `/api/admin/plugins/overrides/[id]` | DELETE | todo | |
| `/api/admin/plugins` | GET | todo | |
| `/api/admin/prayer/[id]` | PATCH DELETE | todo | |
| `/api/admin/prayer` | GET | todo | |
| `/api/admin/query-monitor` | PATCH | todo | |
| `/api/admin/schedules/[id]/events` | GET POST | todo | |
| `/api/admin/schedules/[id]` | GET PATCH DELETE | todo | |
| `/api/admin/schedules/[id]/sync` | POST | todo | |
| `/api/admin/schedules/[id]/validate` | POST | todo | |
| `/api/admin/schedules/reorder` | POST | todo | |
| `/api/admin/schedules` | GET POST | todo | |
| `/api/admin/series/[id]/draft` | GET PUT DELETE | todo | |
| `/api/admin/series/[id]` | GET PATCH DELETE | todo | |
| `/api/admin/series/[id]/viewer-groups` | GET POST | todo | |
| `/api/admin/series/[id]/viewers` | GET POST | todo | |
| `/api/admin/series` | GET POST | todo | |
| `/api/admin/series/viewer-groups/[id]` | DELETE | todo | |
| `/api/admin/series/viewers/[id]` | DELETE | todo | |
| `/api/admin/services/[id]/rota` | GET | todo | |
| `/api/admin/services/[id]` | PATCH DELETE | todo | |
| `/api/admin/services/report` | GET | todo | |
| `/api/admin/services` | GET POST | todo | |
| `/api/admin/share-links/[id]` | PATCH DELETE | todo | |
| `/api/admin/share-links` | GET POST | todo | |
| `/api/admin/sheets/tabs` | GET | todo | |
| `/api/admin/speakers/[id]` | PATCH DELETE | todo | |
| `/api/admin/speakers` | GET POST | todo | |
| `/api/admin/teams/[id]` | PATCH DELETE | todo | |
| `/api/admin/teams` | GET POST | todo | |
| `/api/admin/trash/[type]/[id]` | POST DELETE | todo | |
| `/api/admin/trash` | GET | todo | |
| `/api/admin/users/[id]` | PATCH DELETE | todo | |
| `/api/admin/users` | GET POST | todo | |
| `/api/admin/video-feeds/[id]` | PATCH DELETE | todo | |
| `/api/admin/video-feeds/[id]/sync` | POST | todo | |
| `/api/admin/video-feeds` | GET POST | todo | |
| `/api/admin/videos/[id]/captions` | GET POST DELETE | todo | |
| `/api/admin/videos/[id]/chapters` | GET POST | todo | |
| `/api/admin/videos/[id]` | PATCH DELETE | todo | |
| `/api/admin/videos/[id]/sync-status` | POST | todo | |
| `/api/admin/videos/[id]/thumbnail` | POST | todo | |
| `/api/admin/videos/[id]/transcribe` | POST | todo | |
| `/api/admin/videos/[id]/viewer-groups` | GET POST | todo | |
| `/api/admin/videos/[id]/viewers` | GET POST | todo | |
| `/api/admin/videos/bulk` | POST | todo | |
| `/api/admin/videos/bunny-library` | GET | todo | |
| `/api/admin/videos/chapters/[id]` | PATCH DELETE | todo | |
| `/api/admin/videos/import` | POST | todo | |
| `/api/admin/videos` | GET POST | todo | |
| `/api/admin/videos/viewer-groups/[id]` | DELETE | todo | |
| `/api/admin/videos/viewers/[id]` | DELETE | todo | |
| `/api/admin/webhooks/[id]` | PATCH DELETE | todo | |
| `/api/admin/webhooks` | GET POST | todo | |
| `/api/auth/registration-check` | POST | todo | |
| `/api/calendar-events` | GET | todo | |
| `/api/calendar/[token]/marine-team.ics` | GET | todo | |
| `/api/comments/[id]/report` | POST | todo | |
| `/api/comments/[id]` | DELETE | todo | |
| `/api/comments` | GET POST | todo | |
| `/api/cron/broadcasts` | GET | todo | |
| `/api/cron/extend-events` | GET | todo | |
| `/api/cron/notification-digest` | GET | todo | |
| `/api/cron/schedule-reminders` | GET | todo | |
| `/api/cron/sync-schedules` | GET POST | todo | |
| `/api/cron/sync-video-feeds` | GET | todo | |
| `/api/cron/sync-video-status` | GET | todo | |
| `/api/cron/transcribe` | GET | todo | |
| `/api/downloads/[videoId]` | GET | todo | |
| `/api/events/[slug]/register` | POST DELETE | todo | |
| `/api/favorites` | POST | todo | |
| `/api/files/[id]/content` | GET | todo | |
| `/api/files/[id]/search` | GET | todo | |
| `/api/forms/[slug]` | POST | todo | |
| `/api/groups/[slug]/join` | POST DELETE | todo | |
| `/api/groups/[slug]/meetings` | GET POST | todo | |
| `/api/groups/[slug]/messages/[messageId]` | DELETE | todo | |
| `/api/groups/[slug]/messages` | GET POST PATCH | todo | |
| `/api/groups/[slug]/requests/[memberId]` | PATCH | todo | |
| `/api/groups/[slug]/requests` | GET | todo | |
| `/api/groups` | GET | todo | |
| `/api/hymnals/search` | GET | todo | |
| `/api/hymns/lookup` | POST | todo | |
| `/api/inbox` | GET PATCH DELETE | todo | |
| `/api/live/[id]/chat/[messageId]` | DELETE | todo | |
| `/api/live/[id]/chat/mute` | POST | todo | |
| `/api/live/[id]/chat` | GET POST | todo | |
| `/api/locale` | POST | todo | |
| `/api/manifest` | GET | todo | |
| `/api/notes/[id]` | PATCH DELETE | todo | |
| `/api/notes` | GET POST | todo | |
| `/api/offline/hymnal/[seriesId]` | GET | todo | |
| `/api/offline/service/[id]` | GET | todo | |
| `/api/people` | GET | todo | |
| `/api/playlists/[id]/items` | POST PATCH DELETE | todo | |
| `/api/playlists/[id]` | GET PATCH DELETE | todo | |
| `/api/playlists/for-video` | GET | todo | |
| `/api/playlists` | GET POST | todo | |
| `/api/prayer/[id]/pray` | POST | todo | |
| `/api/prayer/[id]` | DELETE | todo | |
| `/api/prayer` | GET POST | todo | |
| `/api/profile/calendar` | POST DELETE | todo | |
| `/api/profile/devices/[id]` | DELETE | todo | |
| `/api/profile/devices` | GET | todo | |
| `/api/profile/export` | GET | todo | |
| `/api/profile` | PATCH DELETE | todo | |
| `/api/push/subscribe` | POST | todo | |
| `/api/push/unsubscribe` | POST | todo | |
| `/api/ratings` | GET POST | todo | |
| `/api/reactions` | GET POST | todo | |
| `/api/reading/marks/[id]` | PATCH DELETE | todo | |
| `/api/reading/marks` | GET POST | todo | |
| `/api/reading/progress` | POST | todo | |
| `/api/rota` | POST DELETE | todo | |
| `/api/schedules/[id]/events` | GET | todo | |
| `/api/schedules` | GET | todo | |
| `/api/share-links/[id]` | PATCH DELETE | todo | |
| `/api/share-links` | GET POST | todo | |
| `/api/share-links/unlock` | POST | todo | |
| `/api/subscriptions` | POST PATCH | todo | |
| `/api/sync/snapshot` | GET | todo | |
| `/api/tv/approve` | POST | todo | |
| `/api/tv/feed.json` | GET | todo | |
| `/api/tv/feed.xml` | GET | todo | |
| `/api/tv/lookup` | POST | todo | |
| `/api/tv/pair` | POST | todo | |
| `/api/tv/poll` | POST | todo | |
| `/api/v1/analytics` | GET | todo | |
| `/api/v1/calendar-events` | GET | todo | |
| `/api/v1/categories` | GET | todo | |
| `/api/v1/events/[id]/registrations` | GET | todo | |
| `/api/v1/events` | GET | todo | |
| `/api/v1/files` | GET | todo | |
| `/api/v1/groups` | GET | todo | |
| `/api/v1/me` | GET | todo | |
| `/api/v1` | GET | todo | |
| `/api/v1/schedules` | GET | todo | |
| `/api/v1/series` | GET | todo | |
| `/api/v1/videos` | GET | todo | |
| `/api/videos/outline` | PUT | todo | |
| `/api/view-events` | POST | todo | |
| `/api/watch-later` | POST | todo | |
| `/api/watch-progress/mark-watched` | POST | todo | |
| `/api/watch-progress` | POST | todo | |
| `/auth/guest` | GET | todo | |
| `/events/[slug]/event.ics` | GET | todo | |
| `/events/calendar.ics` | GET | todo | |
| `/feed.xml` | GET | todo | |
| `/s/[token]` | GET | todo | |
| `/series/[slug]/podcast.xml` | GET | todo | |

## Models (Appendix B) — 95

Table names are `<prefix>` + the snake_case plural shown. Status covers the table in `0001_init.sql` and the module that owns its reads/writes.

| Model | Table | Status | Notes |
|---|---|---|---|
| User | `users` | todo | |
| UserIdentity | `user_identities` | todo | |
| CategoryEditor | `category_editors` | todo | |
| SeriesEditor | `series_editors` | todo | |
| Category | `categories` | todo | |
| Series | `series` | todo | |
| Video | `videos` | todo | |
| Chapter | `chapters` | todo | |
| Speaker | `speakers` | todo | |
| SeriesFavorite | `series_favorites` | todo | |
| VideoFavorite | `video_favorites` | todo | |
| BookHymn | `book_hymns` | todo | |
| BookPage | `book_pages` | todo | |
| BookHymnDetail | `book_hymn_details` | todo | |
| FileFavorite | `file_favorites` | todo | |
| ServicePlan | `service_plans` | todo | |
| ServiceTeam | `service_teams` | todo | |
| ServiceTeamMember | `service_team_members` | todo | |
| ServiceAssignment | `service_assignments` | todo | |
| ServiceBlockout | `service_blockouts` | todo | |
| ServicePlanItem | `service_plan_items` | todo | |
| Comment | `comments` | todo | |
| CommentReport | `comment_reports` | todo | |
| WatchProgress | `watch_progresses` | todo | |
| FileAsset | `file_assets` | todo | |
| ReadingProgress | `reading_progresses` | todo | |
| ReadingMark | `reading_marks` | todo | |
| ApiKey | `api_keys` | todo | |
| AuditLog | `audit_logs` | todo | |
| Plugin | `plugins` | todo | |
| PluginCategoryOverride | `plugin_category_overrides` | todo | |
| PermissionGroup | `permission_groups` | todo | |
| GroupAssignment | `group_assignments` | todo | |
| Rating | `ratings` | todo | |
| SeriesWatchLater | `series_watch_laters` | todo | |
| CategoryWatchLater | `category_watch_laters` | todo | |
| VideoWatchLater | `video_watch_laters` | todo | |
| PushSubscription | `push_subscriptions` | todo | |
| DraftRevision | `draft_revisions` | todo | |
| Webhook | `webhooks` | todo | |
| Announcement | `announcements` | todo | |
| LiveStream | `live_streams` | todo | |
| HomeRow | `home_rows` | todo | |
| Subscription | `subscriptions` | todo | |
| PendingNotification | `pending_notifications` | todo | |
| Playlist | `playlists` | todo | |
| PlaylistItem | `playlist_items` | todo | |
| Reaction | `reactions` | todo | |
| ViewEvent | `view_events` | todo | |
| HymnLookup | `hymn_lookups` | todo | |
| SeriesViewerGroup | `series_viewer_groups` | todo | |
| SeriesViewer | `series_viewers` | todo | |
| VideoViewerGroup | `video_viewer_groups` | todo | |
| VideoViewer | `video_viewers` | todo | |
| SermonOutlineAnswer | `sermon_outline_answers` | todo | |
| SermonNote | `sermon_notes` | todo | |
| SlugAlias | `slug_aliases` | todo | |
| ShareLink | `share_links` | todo | |
| ShareLinkRecipient | `share_link_recipients` | todo | |
| DownloadPolicy | `download_policies` | todo | |
| DownloadPolicyGroup | `download_policy_groups` | todo | |
| DownloadPolicyUser | `download_policy_users` | todo | |
| AuthSettings | `auth_settings` | todo | |
| AuthorizedEmail | `authorized_emails` | todo | |
| UnauthorizedAccessAttempt | `unauthorized_access_attempts` | todo | |
| Notification | `notifications` | todo | |
| BrandSettings | `brand_settings` | todo | |
| Schedule | `schedules` | todo | |
| ScheduleSource | `schedule_sources` | todo | |
| Person | `people` | todo | |
| PersonAlias | `person_aliases` | todo | |
| CalendarEvent | `calendar_events` | todo | |
| CalendarEventPerson | `calendar_event_people` | todo | |
| Event | `events` | todo | |
| EventSeries | `event_series` | todo | |
| EventRegistration | `event_registrations` | todo | |
| Form | `forms` | todo | |
| FormField | `form_fields` | todo | |
| FormSubmission | `form_submissions` | todo | |
| FormAnswer | `form_answers` | todo | |
| PrayerRequest | `prayer_requests` | todo | |
| PrayerIntercession | `prayer_intercessions` | todo | |
| SmallGroup | `small_groups` | todo | |
| SmallGroupMember | `small_group_members` | todo | |
| GroupMessage | `group_messages` | todo | |
| DiscussionGuide | `discussion_guides` | todo | |
| DiscussionGuideItem | `discussion_guide_items` | todo | |
| SmallGroupMeeting | `small_group_meetings` | todo | |
| GroupAttendance | `group_attendances` | todo | |
| Broadcast | `broadcasts` | todo | |
| BroadcastRecipient | `broadcast_recipients` | todo | |
| VideoFeed | `video_feeds` | todo | |
| LiveChatMessage | `live_chat_messages` | todo | |
| LiveChatMute | `live_chat_mutes` | todo | |
| TvDevice | `tv_devices` | todo | |

## Test files (Appendix D) — 73

Each becomes a PHPUnit test class with the original case names.

| Original | Status | Notes |
|---|---|---|
| `lib/active-path.test.ts` | todo | |
| `lib/admin-nav.test.ts` | todo | |
| `lib/api-keys.test.ts` | todo | |
| `lib/api-v1.test.ts` | todo | |
| `lib/attendance.test.ts` | todo | |
| `lib/authorization.test.ts` | todo | |
| `lib/book-contents.test.ts` | todo | |
| `lib/branding.test.ts` | todo | |
| `lib/broadcast.test.ts` | todo | |
| `lib/bunny.test.ts` | todo | |
| `lib/client-bundle.test.ts` | todo | |
| `lib/content-language.test.ts` | todo | |
| `lib/content.test.ts` | todo | |
| `lib/cover.test.ts` | todo | |
| `lib/cron-guard.test.ts` | todo | |
| `lib/cron.test.ts` | todo | |
| `lib/cross-site.test.ts` | todo | |
| `lib/data-export.test.ts` | todo | |
| `lib/device-settings.test.ts` | todo | |
| `lib/directory.test.ts` | todo | |
| `lib/download-source.test.ts` | todo | |
| `lib/downloads.test.ts` | todo | |
| `lib/event-series.test.ts` | todo | |
| `lib/events.test.ts` | todo | |
| `lib/filename.test.ts` | todo | |
| `lib/forms.test.ts` | todo | |
| `lib/group-messages.test.ts` | todo | |
| `lib/groups.test.ts` | todo | |
| `lib/guides.test.ts` | todo | |
| `lib/hymnal.test.ts` | todo | |
| `lib/i18n/i18n.test.ts` | todo | |
| `lib/ics.test.ts` | todo | |
| `lib/identity-linking.test.ts` | todo | |
| `lib/live-chat.test.ts` | todo | |
| `lib/names.test.ts` | todo | |
| `lib/nav-tabs.test.ts` | todo | |
| `lib/offline-calendar.test.ts` | todo | |
| `lib/offline-shell.test.ts` | todo | |
| `lib/outline.test.ts` | todo | |
| `lib/page-offset.test.ts` | todo | |
| `lib/permissions.test.ts` | todo | |
| `lib/plugins.test.ts` | todo | |
| `lib/podcast-mirror.test.ts` | todo | |
| `lib/prayer.test.ts` | todo | |
| `lib/public-url.test.ts` | todo | |
| `lib/push-endpoint.test.ts` | todo | |
| `lib/reader-cache.test.ts` | todo | |
| `lib/reader.test.ts` | todo | |
| `lib/recurrence.test.ts` | todo | |
| `lib/reorder.test.ts` | todo | |
| `lib/rota.test.ts` | todo | |
| `lib/schedules/duplicates.test.ts` | todo | |
| `lib/schedules/logic.test.ts` | todo | |
| `lib/schedules/visibility.test.ts` | todo | |
| `lib/services.test.ts` | todo | |
| `lib/share-links.test.ts` | todo | |
| `lib/share-password.test.ts` | todo | |
| `lib/sheets/dates.test.ts` | todo | |
| `lib/sheets/parse.test.ts` | todo | |
| `lib/slug.test.ts` | todo | |
| `lib/sms.test.ts` | todo | |
| `lib/toc-nav.test.ts` | todo | |
| `lib/transcribe-worker.test.ts` | todo | |
| `lib/transcribe.test.ts` | todo | |
| `lib/tv-feed.test.ts` | todo | |
| `lib/tv-nav.test.ts` | todo | |
| `lib/tv-pairing.test.ts` | todo | |
| `lib/upload-types.test.ts` | todo | |
| `lib/validation/schemas.test.ts` | todo | |
| `lib/verses.test.ts` | todo | |
| `lib/video-feed-sync.test.ts` | todo | |
| `lib/video-source.test.ts` | todo | |
| `lib/view-key.test.ts` | todo | |

## Security review (route by route)

Filled in during step 6: each route above against the Security requirements
of the brief (CSRF, validation allowlist, capability, rate limit, headers,
output escaping, SSRF). No rows yet.

| Route | Result | Notes |
|---|---|---|

## Deviations

Decisions in the brief that turned out wrong or impossible against what was
met, with the reason.

- *(none yet)*

## Session log

- 2026-09-25 — step 1: this map. The brief itself arrived as the session
  prompt rather than as a committed file; it should be committed as
  `PORT_PROMPT.md` at the repository root so later sessions have the
  appendices.

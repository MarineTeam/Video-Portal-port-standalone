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
| 2 | Foundation: core, schema, migrator, installer, local sign-in, users and capabilities, admin shell, branding, i18n, services registry (Files: local disk, Email: mail()), jobs, plugin/theme loaders, default theme | done (the Next.js import is tracked under Areas) |
| 3 | Library: categories, series, videos and providers, player, files, search, trash, audit, permissions, share links, downloads, feeds, sitemap, metadata; remaining sign-in, email, files providers | done (3.1–3.6 and 3.2b: content core, admin CMS, providers and player, public pages/search/feeds/sitemap, share links/downloads/video feeds, the remaining providers; home rows, chapters, transcription, media check) |
| 4 | Bundled plugins, simplest first | in progress (favorites, watch-later, view-counts, social-share, ratings, likes-dislikes, related-content, up-next, watch-history, profiles, chapters, transcripts, recommendations, announcements, webhooks, notifications, subscriptions, playlists, sermon-notes) |
| 5 | Books/hymnals, services/rota, schedules/sheets, events, forms, prayer, groups, broadcasts/SMS, live, television, read API, export/import | todo |
| 6 | Hardening and docs: smoke test, security walk, INSTALL/PLUGINS/THEMES/UPGRADING/SERVICES, migration guide | todo |

## Areas (Feature inventory)

| Area | Kind | Status | Notes |
|---|---|---|---|
| Core framework (Router, Db, View, Session, Csrf, Http, Hooks, Cache, Jobs, Migrator, Log, errors) | core | done | app/Core; PHPStan level 6 clean |
| Installer (`/install`) and upgrader (`/admin/update`), backups (`/admin/tools`) | core | partial | Installer done and covered by the smoke test; /admin/update done (maintenance, resumable migrations, signed release zips with rollback; verified end to end against a scratch install); /admin/tools backup (.sql.gz a step per request, restores exactly — BackupTest) and files in 100 MB parts done; the Next.js import pending |
| Services registry and Admin → Services | core | partial | Registry, generated forms, signed test-then-switch at /admin/providers; auth trial-mode switch arrives with external providers |
| Library (categories, series, videos, files, speakers, scripture, tags, search, trash, feeds, sitemap, metadata) | core | done | Admin, providers, player, public pages, search, feeds, sitemap, JSON-LD, share links, downloads, video feeds, home rows, chapters, transcription, media check; the library's plugins (comments, related, up next…) come with step 4 |
| Access (sign-in providers, allowlist, identities, permissions, capabilities, audit, API keys) | core | todo | |
| Site (branding, i18n, nav, device settings, standalone chrome, inbox, profile, data export, video feeds, query monitor) | core | partial | Branding, i18n, nav, device settings, per-device bottom bar, inbox, profile shell, data export, query monitor done; video feeds with the Library (step 3) |
| Read API `/api/v1` | core | todo | |
| PWA and offline shell (sw.js, offline.html, manifest) | core | partial | Static files shipped (base-path aware); the saving side (offline-books etc.) arrives with its modules |
| Plugin loader, auto-deactivation, per-category overrides | core | done | All three load-failure paths plus the hook breaker, proven by tests/Integration/SmokeTest.php |
| Theme loader, default theme, customizer | core | done | Loader with child → parent → core, fallback with notice, /admin/appearance (install, activate, delete, customizer merged over branding) |
| Member plugins (favorites … downloads, 21 of Appendix E) | plugins | partial | favorites, watch-later, view-counts, social-share, ratings, likes-dislikes, related-content, up-next, watch-history, profiles, chapters, transcripts, recommendations, announcements, webhooks, notifications, subscriptions, playlists, sermon-notes in plugins/; page hooks page.category/series/video.panels; tests/Integration/MemberListsTest.php through a real server |
| Live streaming and chat | plugin | todo | |
| Book reader, hymnals, service plans, rota | plugins | todo | |
| Schedules and Google Sheets | plugin | todo | |
| Events and event series, forms, prayer, small groups (attendance, guides, thread), directory, broadcasts and SMS | plugins | todo | |
| Television | plugin | todo | |
| Data import from the Next.js deployment (`tools/export-from-nextjs`, `/admin/tools/import`) | core | todo | |

### Service providers

| Slot | Provider | Status | Notes |
|---|---|---|---|
| auth | Local accounts (password, magic link) | done | |
| auth | Auth0 | done | Redirect flow with PKCE; organization parameter rules; org_id from the verified ID token; /auth/guest; subs verbatim |
| auth | OpenID Connect + presets (Google, Entra ID, Apple, Okta, Keycloak, Authentik, Zitadel, Logto, Kinde, Clerk-OIDC) | done | One RedirectProvider: discovery, PKCE, JWKS (RS256/ES256, kid rotation), Entra's per-tenant issuer, Apple's ES256 client secret and form_post |
| auth | Clerk (native) | done | Token flow: ClerkJS from the Frontend API, session JWT verified against its JWKS, azp must be this site; address from a JWT template or the Backend API |
| auth | Supabase Auth | done | Form flow over GoTrue's REST API (no SDK): password grant, magic link and social providers through PKCE, /auth/supabase/verify for token_hash links; access token verified with the JWT secret (HS256) or the project JWKS, else vouched for by /auth/v1/user; email_confirmed_at is email_verified; no accounts created from the magic-link form |
| auth | Firebase Authentication | done | Token flow: Firebase JS SDK (Google popup, email and password); ID token checked against Google's certificates, issuer and project |
| video | bunny.net Stream | done | tus upload, signed embeds, CDN token, MP4 renditions, captions, library import |
| video | YouTube | done | Links (oEmbed or Data API); upload through a resumable session the server opens with OAuth |
| video | Vimeo | done | Links; with a token: tus upload, captions, MP4 files |
| video | Dropbox | done | Links (direct raw URLs); optional upload through an app-folder app: a four-hour token and a server-chosen path for the browser's upload session, then a public shared link |
| video | Google Drive | done | Links, preview or API mode; optional upload (OAuth, drive.file): resumable session opened from this origin, file confirmed as the app's, then shared with anyone with the link |
| video | OneDrive / SharePoint | done | Links (personal and Business via Graph); optional upload to a Business/SharePoint drive through a Graph upload session, then an anonymous view link (or the tenant's refusal, said plainly); personal uploads: see Deviations |
| video | Internet Archive | done | Links; picks the best MP4 |
| video | S3-compatible | done | Presigned PUT, multipart over 100 MB, SigV4 (AWS test vectors), CORS rule shown |
| video | Direct link | done | HEAD through the untrusted-URL fetcher |
| video | Host disk | done | Chunked upload; private files stream via /api/videos/local/[name], public ones move to public/media/videos |
| email | SMTP (PHPMailer, presets) | done | Native client (see Deviations) |
| email | PHP mail() | done | |
| email | Resend, Mailgun, SendGrid, Postmark, Amazon SES, Brevo, Microsoft Graph | done | One HttpEmailProvider base: the key is checked before a test message goes out; SES signed with the shared App\Support\SigV4 |
| files | Local disk | done | |
| files | Bunny Storage | done | Signed 10-minute redirects (optionally address-bound) with token auth; otherwise a streamed proxy with Range; storage listing and import; public podcast zone |
| sms | Twilio, Vonage, MessageBird, Plivo, Sinch, Telnyx, Amazon SNS, ClickSend, Textlocal, BulkSMS, JSON webhook | todo | |

## Pages (Appendix C.1) — 91

| Path | Status | Notes |
|---|---|---|
| `/` | done | Library\Pages: hero (featured, else newest series), Continue watching above the browse tiles, then the rows from /admin/home-rows (Because you watched, Trending this week, Recently added, category and tag rows) |
| `/access-denied` | done | One plain sentence; guest link only while the switch is open |
| `/admin` | partial | Dashboard with counts and setup warnings; library cards pending |
| `/admin/access-attempts` | done | Filter by address, reason, date and unreviewed; mark reviewed; prune past 90 days |
| `/admin/analytics` | todo | |
| `/admin/announcements` | done | plugins/announcements |
| `/admin/api-keys` | todo | |
| `/admin/audit` | done | Paged, filterable; CSV/JSON export streamed, cells that start with = + - @ are quoted |
| `/admin/authorized-emails` | done | Allowlist with search and status filter; never suspends or removes the last active entry; organisation exemption per address; guest-login switch |
| `/admin/branding` | done | Name, short name, three colours with live preview, logo by URL or upload (re-encoded by GD, served from storage/media) |
| `/admin/broadcasts` | todo | |
| `/admin/categories` | done | Tree to any depth, ↑↓ among siblings, trash; administrators only (admin-nav) |
| `/admin/categories/[id]` | done | Every field incl. parent (cycle-guarded), cover upload, three-way downloads |
| `/admin/comments` | todo | |
| `/admin/downloads` | done | Who (any member, or roles and people) and where (web, app, both), suggested space |
| `/admin/events` | todo | |
| `/admin/events/[id]` | todo | |
| `/admin/files` | done | FilesAdmin: chunked upload stored with the Files slot, inline edit, replace, bulk (incl. podcast), kind filter |
| `/admin/forms` | todo | |
| `/admin/forms/[id]` | todo | |
| `/admin/groups` | todo | |
| `/admin/groups/[id]` | todo | |
| `/admin/home-rows` | done | Library\Admin\HomeRowsAdmin: toggle, rename, reorder the built-in rows; add category and tag rows; says when a row's plugin is off |
| `/admin/live` | todo | |
| `/admin/media-check` | done | Library\Admin\MediaCheckAdmin (see Deviations): videos whose service is gone, whose host-disk file is missing, stuck or failed, failed transcriptions; local files missing; pasted links checked on request; unused host-disk video files (administrators may delete) |
| `/admin/people` | todo | |
| `/admin/permissions` | done | Groups (capabilities sanitised to the known list), assignments site-wide or scoped to a category/series, category and series editors |
| `/admin/plugins` | done | Activate, per-category overrides, zip install/delete, auto-deactivation notices; never loads third-party plugins |
| `/admin/prayer` | todo | |
| `/admin/query-monitor` | done | Reports the storage/config.php flag, toggles the bar (plugins row "query-monitor", fail-open) |
| `/admin/schedules` | todo | |
| `/admin/schedules/[id]` | todo | |
| `/admin/series` | done | Scoped to the editor’s part of the library; filter, bulk publish/unpublish/move/delete, ↑↓ |
| `/admin/series/[id]` | done | Publish now / Save as draft / Load draft, tags, slug rename leaves an alias, restricted viewing |
| `/admin/services` | todo | |
| `/admin/services/report` | todo | |
| `/admin/share-links` | done | Filter active/revoked, revoke (audited), create for any series or video |
| `/admin/speakers` | done |  |
| `/admin/teams` | todo | |
| `/admin/trash` | done | Restore; delete for good removes the provider asset first |
| `/admin/users` | done | Roles (ADMIN only, never the last admin), pre-authorise by email, revoke; changing a role signs the person out |
| `/admin/video-feeds` | done | Administrators only (a feed can file videos anywhere) |
| `/admin/videos` | done | VideosAdmin: add by link or upload (tus, presigned PUT/multipart, resumable, chunked), Bunny import, bulk; edit page at /admin/videos/[id] (the port's) with thumbnail, captions, restricted viewing |
| `/admin/webhooks` | done | plugins/webhooks |
| `/books/[fileId]` | todo | |
| `/calendar` | todo | |
| `/categories/[slug]` | done | Children, series, standalone videos and files; generic title + sign-in page (401) for a members-only one |
| `/directory` | done | plugins/profiles: name only by default, each contact detail its own yes, search by name and note only, noindex |
| `/events` | todo | |
| `/events/[slug]` | todo | |
| `/favorites` | done | plugins/favorites |
| `/forms` | todo | |
| `/forms/[slug]` | todo | |
| `/groups` | todo | |
| `/groups/[slug]` | todo | |
| `/guides` | todo | |
| `/guides/[slug]` | todo | |
| `/hymns/[fileId]` | todo | |
| `/link` | todo | |
| `/live` | todo | |
| `/playlists` | done | plugins/playlists |
| `/playlists/[id]` | done | the owner's, or read-only for anyone once shareable (noindex); each reader sees only the videos they may watch |
| `/prayer` | todo | |
| `/present/[fileId]` | todo | |
| `/profile` | done | Overview: unread count and plugin cards (profile.overview) |
| `/profile/devices` | todo | |
| `/profile/downloads` | done | This device’s saved videos (self-healing), Wi-Fi-only choice, space used and the browser quota |
| `/profile/events` | todo | |
| `/profile/groups` | todo | |
| `/profile/inbox` | done | Mark one/all read, open, delete one/all; push toggle slot for the notifications plugin |
| `/profile/rota` | todo | |
| `/profile/settings` | done | This device (theme, language, autoplay, speed, reading, bottom bar), account fields by plugin, password and sign-out-elsewhere, download my data, delete account |
| `/profile/shared-links` | done | The member’s own links |
| `/read/[fileId]` | todo | |
| `/recently-added` | done | Newest series and videos |
| `/recently-played` | done | plugins/watch-history |
| `/scripture` | done | Books with a video the reader may open, in canonical order |
| `/scripture/[book]` | done |  |
| `/search` | done | Library\Search: ranked substring + FULLTEXT pass, fuzzy re-rank of ≤500 titles only on an empty result; category/speaker filters, newest sort; content.search_sources for plugins; 100-char cap |
| `/series/[slug]` | done | Slug aliases 301; sequential unlock (series or its category); tags; files; BreadcrumbList |
| `/services` | todo | |
| `/services/[id]` | todo | |
| `/share/unavailable` | done | Says revoked, expired or another account |
| `/share/unlock/[token]` | done | Password first; nothing granted or counted until it’s right |
| `/speakers` | done | With counts of videos the reader may open |
| `/speakers/[slug]` | done | Person JSON-LD |
| `/subscriptions` | done | plugins/subscriptions: follows with mute and unfollow |
| `/tags/[tag]` | done | Series carrying the tag (series_tags) |
| `/tv` | todo | |
| `/videos/[slug]` | done | Aliases 301 keeping ?t=; resume from progress unless ?t=; premiere and lock placeholders; mark watched; share-at; VideoObject + BreadcrumbList JSON-LD |
| `/watch-later` | done | plugins/watch-later (categories, series, videos) |

## Routes (Appendix C.2) — 218

| Path | Methods | Status | Notes |
|---|---|---|---|
| `/api/admin/access-attempts` | GET POST | done | Filters as the page; POST {action: review|prune} |
| `/api/admin/analytics/export` | GET | todo | |
| `/api/admin/announcements/[id]` | PATCH DELETE | done | plugins/announcements |
| `/api/admin/announcements` | GET POST | done | message, active, publishAt/expiresAt window, audience ALL/GUESTS/MEMBERS |
| `/api/admin/api-keys/[id]` | DELETE | todo | |
| `/api/admin/api-keys` | GET POST | todo | |
| `/api/admin/assignments` | POST DELETE | todo | |
| `/api/admin/audit/export` | GET | done | ?format=csv|json, streamed |
| `/api/admin/audit` | GET | done | Paged, filter by actor/action/entity/date |
| `/api/admin/authorized-emails/[id]` | PATCH DELETE | done | Last-active guard |
| `/api/admin/authorized-emails` | GET POST | done | 409 on a duplicate address |
| `/api/admin/branding` | GET PUT DELETE | done | javascript:/data: logos refused |
| `/api/admin/broadcasts/[id]` | GET DELETE | todo | |
| `/api/admin/broadcasts/[id]/send` | POST | todo | |
| `/api/admin/broadcasts/[id]/test` | POST | todo | |
| `/api/admin/broadcasts` | GET POST | todo | |
| `/api/admin/bunny-audit` | GET | todo | |
| `/api/admin/calendar-events/[id]` | GET PATCH DELETE | todo | |
| `/api/admin/categories/[id]` | PATCH DELETE | done | `{move: up|down|n}` reorders; DELETE trashes |
| `/api/admin/categories` | GET POST | done | Top-level creation for administrators only |
| `/api/admin/comments/[id]` | PATCH | todo | |
| `/api/admin/comments` | GET | todo | |
| `/api/admin/downloads` | GET PATCH | done |  |
| `/api/admin/editors/category/[id]` | DELETE | done |  |
| `/api/admin/editors/category` | POST | done | By email |
| `/api/admin/editors` | GET | done |  |
| `/api/admin/editors/series/[id]` | DELETE | done |  |
| `/api/admin/editors/series` | POST | done | By email |
| `/api/admin/events/[id]/registrations/[registrationId]` | DELETE | todo | |
| `/api/admin/events/[id]/registrations` | GET | todo | |
| `/api/admin/events/[id]` | PATCH DELETE | todo | |
| `/api/admin/events` | GET POST | todo | |
| `/api/admin/events/series/[id]` | PATCH DELETE | todo | |
| `/api/admin/events/series` | GET POST | todo | |
| `/api/admin/files/[id]/contents` | GET PUT | todo | |
| `/api/admin/files/[id]/lyrics` | GET PUT | todo | |
| `/api/admin/files/[id]/replace` | POST | done | Takes a chunked upload id; the Bunny Storage pick arrives with that provider |
| `/api/admin/files/[id]` | PATCH DELETE | done | PATCH also takes `move` |
| `/api/admin/files/[id]/text` | GET POST DELETE | todo | |
| `/api/admin/files/bulk` | POST | done | publish, unpublish, delete, move, podcast, unpodcast |
| `/api/admin/files/bunny-storage` | GET | done | ?dir=, marks what is already imported |
| `/api/admin/files/import` | POST | done | Objects stay where they are; each becomes a file row |
| `/api/admin/files` | GET POST | done | POST takes a chunked upload id (the port's uploader; the original posted the file) |
| `/api/admin/forms/[id]/fields/[fieldId]` | PATCH DELETE | todo | |
| `/api/admin/forms/[id]/fields` | POST | todo | |
| `/api/admin/forms/[id]` | GET PATCH DELETE | todo | |
| `/api/admin/forms/[id]/submissions/[submissionId]` | PATCH DELETE | todo | |
| `/api/admin/forms/[id]/submissions` | GET | todo | |
| `/api/admin/forms` | GET POST | todo | |
| `/api/admin/group-assignments/[id]` | DELETE | done |  |
| `/api/admin/group-assignments` | GET POST | done | By userId or email; category xor series scope |
| `/api/admin/groups/[id]/members/[memberId]` | DELETE | todo | |
| `/api/admin/groups/[id]/members` | POST | todo | |
| `/api/admin/groups/[id]` | GET PATCH DELETE | todo | |
| `/api/admin/groups` | GET POST | todo | |
| `/api/admin/guest-login` | GET PATCH | done |  |
| `/api/admin/guides/[id]` | GET PATCH DELETE | todo | |
| `/api/admin/guides` | GET POST | todo | |
| `/api/admin/home-rows/[id]` | PATCH DELETE | done | title, enabled, move up/down; only curated rows delete |
| `/api/admin/home-rows` | GET POST | done | POST creates CATEGORY/TAG rows only |
| `/api/admin/live/[id]` | PATCH DELETE | todo | |
| `/api/admin/live` | GET POST | todo | |
| `/api/admin/people/[id]` | PATCH DELETE | todo | |
| `/api/admin/people/merge` | POST | todo | |
| `/api/admin/people` | GET POST | todo | |
| `/api/admin/permission-groups/[id]` | PATCH DELETE | done | A group with scoped assignments can’t gain site-wide-only capabilities |
| `/api/admin/permission-groups` | GET POST | done | Returns the capability list with the groups |
| `/api/admin/plugins/[slug]/overrides` | POST | done |  |
| `/api/admin/plugins/[slug]` | PATCH | done |  |
| `/api/admin/plugins/overrides/[id]` | DELETE | done |  |
| `/api/admin/plugins` | GET | done | PLUGIN_META slugs plus installed packages; never the query-monitor row |
| `/api/admin/prayer/[id]` | PATCH DELETE | todo | |
| `/api/admin/prayer` | GET | todo | |
| `/api/admin/query-monitor` | PATCH | done | `{enabled}` → `{enabled, configured}` |
| `/api/admin/schedules/[id]/events` | GET POST | todo | |
| `/api/admin/schedules/[id]` | GET PATCH DELETE | todo | |
| `/api/admin/schedules/[id]/sync` | POST | todo | |
| `/api/admin/schedules/[id]/validate` | POST | todo | |
| `/api/admin/schedules/reorder` | POST | todo | |
| `/api/admin/schedules` | GET POST | todo | |
| `/api/admin/series/[id]/draft` | GET PUT DELETE | done | One staged DraftRevision; any publish clears it |
| `/api/admin/series/[id]` | GET PATCH DELETE | done | publish fields need publish_content; moving needs the capability in both places |
| `/api/admin/series/[id]/viewer-groups` | GET POST | done |  |
| `/api/admin/series/[id]/viewers` | GET POST | done | By email of an existing member |
| `/api/admin/series` | GET POST | done | ?q, ?categoryId, ?page; scoped |
| `/api/admin/series/viewer-groups/[id]` | DELETE | done |  |
| `/api/admin/series/viewers/[id]` | DELETE | done |  |
| `/api/admin/services/[id]/rota` | GET | todo | |
| `/api/admin/services/[id]` | PATCH DELETE | todo | |
| `/api/admin/services/report` | GET | todo | |
| `/api/admin/services` | GET POST | todo | |
| `/api/admin/share-links/[id]` | PATCH DELETE | done | DELETE revokes (keeps the row) |
| `/api/admin/share-links` | GET POST | done | GET ?state=active|revoked |
| `/api/admin/sheets/tabs` | GET | todo | |
| `/api/admin/speakers/[id]` | PATCH DELETE | done | Videos keep playing without a speaker |
| `/api/admin/speakers` | GET POST | done |  |
| `/api/admin/teams/[id]` | PATCH DELETE | todo | |
| `/api/admin/teams` | GET POST | todo | |
| `/api/admin/trash/[type]/[id]` | POST DELETE | done | POST restores; DELETE purges |
| `/api/admin/trash` | GET | done | Only the kinds the reader manages site-wide |
| `/api/admin/users/[id]` | PATCH DELETE | done | Last-admin guard; role change deletes sessions |
| `/api/admin/users` | GET POST | done |  |
| `/api/admin/video-feeds/[id]` | PATCH DELETE | done |  |
| `/api/admin/video-feeds/[id]/sync` | POST | done | Forces a full pass |
| `/api/admin/video-feeds` | GET POST | done |  |
| `/api/admin/videos/[id]/captions` | GET POST DELETE | done | Provider captions (Bunny, Vimeo) or a WebVTT sidecar in storage/media/captions; SRT converted |
| `/api/admin/videos/[id]/chapters` | GET POST | done | Library\Admin\ChaptersAdmin; time as timestampSeconds or "timestamp" (12:03, 1:02:03, 95) |
| `/api/admin/videos/[id]` | PATCH DELETE | done | PATCH also takes `move` |
| `/api/admin/videos/[id]/sync-status` | POST | done | Takes the browser's upload report (upload id, ETags, the service's id) |
| `/api/admin/videos/[id]/thumbnail` | POST | done | Bunny is told to fetch it; host disk, S3, links keep it as the poster |
| `/api/admin/videos/[id]/transcribe` | POST | done | Library\Transcription::queue: 202 with the video QUEUED; 409 with the reason when no service is set or the provider gives no file |
| `/api/admin/videos/[id]/viewer-groups` | GET POST | done |  |
| `/api/admin/videos/[id]/viewers` | GET POST | done | Same module as series |
| `/api/admin/videos/bulk` | POST | done | publish, unpublish, delete, move, schedule, expire |
| `/api/admin/videos/bunny-library` | GET | done | Only videos not already here |
| `/api/admin/videos/chapters/[id]` | PATCH DELETE | done | checked against the video's scope |
| `/api/admin/videos/import` | POST | done |  |
| `/api/admin/videos` | GET POST | done | POST `mode`: link or upload; upload answers the ticket |
| `/api/admin/videos/viewer-groups/[id]` | DELETE | done |  |
| `/api/admin/videos/viewers/[id]` | DELETE | done |  |
| `/api/admin/webhooks/[id]` | PATCH DELETE | done | plus POST `/api/admin/webhooks/[id]/test` (the port's: a test delivery, 502 with the reason when it fails) |
| `/api/admin/webhooks` | GET POST | done | public addresses only; the secret encrypted, shown only as `secretSet` |
| `/api/auth/registration-check` | POST | done | Bearer secret from settings, fails closed, {allowed} only, rate-limited, records SIGNUP refusals |
| `/api/calendar-events` | GET | todo | |
| `/api/calendar/[token]/marine-team.ics` | GET | todo | |
| `/api/comments/[id]/report` | POST | todo | |
| `/api/comments/[id]` | DELETE | todo | |
| `/api/comments` | GET POST | todo | |
| `/api/cron/broadcasts` | GET | todo | |
| `/api/cron/extend-events` | GET | todo | |
| `/api/cron/notification-digest` | GET | done | Job `notification-digest`, daily at 13:00 UTC (plugins/notifications) |
| `/api/cron/schedule-reminders` | GET | todo | |
| `/api/cron/sync-schedules` | GET POST | todo | |
| `/api/cron/sync-video-feeds` | GET | done | Job `sync-video-feeds`, daily at 07:15 UTC as before |
| `/api/cron/sync-video-status` | GET | done | Job `sync-video-status` every 15 min through /cron/run; abandoned upload placeholders marked FAILED after a day |
| `/api/cron/transcribe` | GET | done | Job `transcribe` every 10 minutes through /cron/run, 20 s budget for starting work; stale RUNNING (30 min) re-queued |
| `/api/downloads/[videoId]` | GET | done | Four gates after canViewVideo; an MP4 link or the specific reason there isn’t one |
| `/api/events/[slug]/register` | POST DELETE | todo | |
| `/api/favorites` | POST | done | `{seriesId}` or `{videoId}` toggles → `{favorited}`; 404 for what the member can't open; 403 `plugin_disabled` where a category switches it off |
| `/api/files/[id]/content` | GET | done | ContentAccess per request; Range, ETag/304, private no-cache, ?download=1; X-Sendfile family via RangeStreamer |
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
| `/api/inbox` | GET PATCH DELETE | done | `{notifications, hasMore, unreadCount}`; PATCH/DELETE take `{ids}` or `{all: true}` |
| `/api/live/[id]/chat/[messageId]` | DELETE | todo | |
| `/api/live/[id]/chat/mute` | POST | todo | |
| `/api/live/[id]/chat` | GET POST | todo | |
| `/api/locale` | POST | done | Sets marine-locale cookie |
| `/api/manifest` | GET | done | From branding, base-path aware |
| `/api/notes/[id]` | PATCH DELETE | done | the member's own |
| `/api/notes` | GET POST | done | `?videoId`; `&format=text` downloads the sheet and the notes as one text file |
| `/api/offline/hymnal/[seriesId]` | GET | todo | |
| `/api/offline/service/[id]` | GET | todo | |
| `/api/people` | GET | todo | |
| `/api/playlists/[id]/items` | POST PATCH DELETE | done | `{videoId}`; PATCH `{videoId, move}` or `{order}` |
| `/api/playlists/[id]` | GET PATCH DELETE | done | `{playlist, items}`; PATCH `{title, public}` |
| `/api/playlists/for-video` | GET | done | `[{id, title, contains}]` for the Add to playlist menu |
| `/api/playlists` | GET POST | done | POST `{title, videoId?}` |
| `/api/prayer/[id]/pray` | POST | todo | |
| `/api/prayer/[id]` | DELETE | todo | |
| `/api/prayer` | GET POST | todo | |
| `/api/profile/calendar` | POST DELETE | todo | |
| `/api/profile/devices/[id]` | DELETE | todo | |
| `/api/profile/devices` | GET | todo | |
| `/api/profile/export` | GET | done | Every member-keyed table, scoped queries, assertExportSafe, 2/min from the audit log |
| `/api/profile` | PATCH DELETE | done | PATCH: fields owned by active plugins only; DELETE `{confirm: email}`, never the last admin |
| `/api/push/subscribe` | POST | done | App\Modules\Push: push services only, eight per member, 409 until Web Push is set up |
| `/api/push/unsubscribe` | POST | done | |
| `/api/ratings` | GET POST | done | plugins/ratings: `?seriesId`/`?videoId` → `{average, count, mine}`; POST `{…, value: 1–5 or null}` (members) |
| `/api/reactions` | GET POST | done | plugins/likes-dislikes: → `{likes, dislikes, mine}`; POST `{…, type: LIKE, DISLIKE or null}` (members) |
| `/api/reading/marks/[id]` | PATCH DELETE | todo | |
| `/api/reading/marks` | GET POST | todo | |
| `/api/reading/progress` | POST | todo | |
| `/api/rota` | POST DELETE | todo | |
| `/api/schedules/[id]/events` | GET | todo | |
| `/api/schedules` | GET | todo | |
| `/api/share-links/[id]` | PATCH DELETE | done | PATCH note or revoked; DELETE revokes |
| `/api/share-links` | GET POST | done | 20 new links an hour; private links email and inbox their recipients |
| `/api/share-links/unlock` | POST | done | 10 wrong guesses in 15 minutes lock the link; also 30 tries per address per 15 minutes |
| `/api/subscriptions` | POST PATCH | done | POST `{seriesId|categoryId}` toggles → `{following}`; PATCH `{…, muted}` → `{muted}` |
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
| `/api/videos/outline` | PUT | done | plugins/sermon-notes: `{videoId, outlineVersion, answers}`; 409 `outline_changed` against an older sheet |
| `/api/view-events` | POST | done | 30-minute cookie (mt_views) plus the HMAC address throttle; counts only what the caller may open |
| `/api/watch-later` | POST | done | `{categoryId|seriesId|videoId}` toggles → `{saved}` |
| `/api/watch-progress/mark-watched` | POST | done | The one way to clear a completion |
| `/api/watch-progress` | POST | done | Only ever sets completed; never clears it |
| `/auth/guest` | GET | partial | 404s unless the switch is open and the primary provider can build a guest URL (Auth0, step 3) |
| `/events/[slug]/event.ics` | GET | todo | |
| `/events/calendar.ics` | GET | todo | |
| `/feed.xml` | GET | done | Built as a visitor sees the site, whoever asks |
| `/s/[token]` | GET | todo | |
| `/series/[slug]/podcast.xml` | GET | done | Opted-in audio only; 404 for a members-only series; enclosure is /api/files/[id]/content until the Bunny public zone arrives (3.6) |

## Models (Appendix B) — 95

Table names are `<prefix>` + the snake_case plural shown. **Every model's table exists in `app/Migrations/0001_init.sql`** (applied and re-applied cleanly on MariaDB 10.11 locally; MySQL 8.0 and MariaDB 10.6 in CI). A row's status is about the module that owns its reads and writes; `todo` means the table is there and nothing uses it yet.

| Model | Table | Status | Notes |
|---|---|---|---|
| User | `users` | partial | Table + local-account columns; sign-in, revocation, roles done; admin screens pending |
| UserIdentity | `user_identities` | done | Written by SignIn::complete; sub namespaced except Auth0 |
| CategoryEditor | `category_editors` | done | Honoured by Permissions; managed at /admin/permissions |
| SeriesEditor | `series_editors` | done | Honoured by Permissions; managed at /admin/permissions |
| Category | `categories` | partial | Admin CRUD, tree, trash; public pages with 3.4 |
| Series | `series` | partial | Admin CRUD, drafts, tags (series_tags), aliases, viewers; public pages with 3.4 |
| Video | `videos` | todo | |
| Chapter | `chapters` | done | listed in time order; editor on /admin/videos/[id]; the video page's list (Chapters plugin) seeks the player and copies a ?t= link |
| Speaker | `speakers` | partial | Admin CRUD; public pages with 3.4 |
| SeriesFavorite | `series_favorites` | done | plugins/favorites |
| VideoFavorite | `video_favorites` | done | plugins/favorites |
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
| AuditLog | `audit_logs` | done | Audit::log; /admin/audit with export |
| Plugin | `plugins` | done | Plus bundled, version, deactivation columns |
| PluginCategoryOverride | `plugin_category_overrides` | done |  |
| PermissionGroup | `permission_groups` | done | Honoured by Permissions; managed at /admin/permissions |
| GroupAssignment | `group_assignments` | done | Honoured by Permissions; managed at /admin/permissions |
| Rating | `ratings` | done | plugins/ratings |
| SeriesWatchLater | `series_watch_laters` | done | plugins/watch-later |
| CategoryWatchLater | `category_watch_laters` | done | plugins/watch-later |
| VideoWatchLater | `video_watch_laters` | done | plugins/watch-later |
| PushSubscription | `push_subscriptions` | done | forgotten when a push service answers 404/410 |
| DraftRevision | `draft_revisions` | done | Series drafts |
| Webhook | `webhooks` | done | series.published / video.published → JSON POST through fetchUntrusted, X-Webhook-Signature (hex HMAC-SHA256), sent after the response where the host allows |
| Announcement | `announcements` | done | the banner through render.page_top; cached a minute per audience, forgotten on every write; dismissed per browser session |
| LiveStream | `live_streams` | todo | |
| HomeRow | `home_rows` | done | Library\HomeRows (seeded once, built-in order when empty or unreadable) |
| Subscription | `subscriptions` | done | a new video reaches unmuted followers of its series and every category above it who may watch it (push + inbox) |
| PendingNotification | `pending_notifications` | done | plugins/notifications (DAILY members with a browser signed up) |
| Playlist | `playlists` | done | plugins/playlists |
| PlaylistItem | `playlist_items` | done | plugins/playlists |
| Reaction | `reactions` | done | plugins/likes-dislikes |
| ViewEvent | `view_events` | todo | |
| HymnLookup | `hymn_lookups` | todo | |
| SeriesViewerGroup | `series_viewer_groups` | done | Restricted viewing, checked by ContentAccess |
| SeriesViewer | `series_viewers` | done | Restricted viewing, checked by ContentAccess |
| VideoViewerGroup | `video_viewer_groups` | done | Restricted viewing, checked by ContentAccess |
| VideoViewer | `video_viewers` | done | Restricted viewing, checked by ContentAccess |
| SermonOutlineAnswer | `sermon_outline_answers` | done | kept with the sheet's fingerprint; an edited sheet is reported, earlier answers shown as text |
| SermonNote | `sermon_notes` | done | time prefilled from the player, then the member's to edit |
| SlugAlias | `slug_aliases` | partial | Written on rename; redirects with 3.4 |
| ShareLink | `share_links` | done | Library\Sharing |
| ShareLinkRecipient | `share_link_recipients` | done | |
| DownloadPolicy | `download_policies` | done | |
| DownloadPolicyGroup | `download_policy_groups` | done | |
| DownloadPolicyUser | `download_policy_users` | done | |
| AuthSettings | `auth_settings` | done | Guest-login switch (GuestLogin), read by /auth/guest and /access-denied |
| AuthorizedEmail | `authorized_emails` | done | Checked on every request, bootstrap adoption, /admin/authorized-emails |
| UnauthorizedAccessAttempt | `unauthorized_access_attempts` | done | Hourly alert dedupe, 90-day prune, /admin/access-attempts |
| Notification | `notifications` | done | Inbox::add for plugins; /profile/inbox and /api/inbox |
| BrandSettings | `brand_settings` | done | Painted as custom properties; /admin/branding |
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
| VideoFeed | `video_feeds` | done | |
| LiveChatMessage | `live_chat_messages` | todo | |
| LiveChatMute | `live_chat_mutes` | todo | |
| TvDevice | `tv_devices` | todo | |

## Test files (Appendix D) — 73

Each becomes a PHPUnit test class with the original case names.

| Original | Status | Notes |
|---|---|---|
| `lib/active-path.test.ts` | done | tests/Unit/Admin/ActivePathTest.php |
| `lib/admin-nav.test.ts` | done | tests/Unit/Admin/AdminNavTest.php |
| `lib/api-keys.test.ts` | todo | |
| `lib/api-v1.test.ts` | todo | |
| `lib/attendance.test.ts` | todo | |
| `lib/authorization.test.ts` | done | tests/Unit/Access/AuthorizationTest.php; the guest-login cases in tests/Integration/GuestLoginTest.php |
| `lib/book-contents.test.ts` | todo | |
| `lib/branding.test.ts` | done | tests/Unit/Branding/BrandingTest.php |
| `lib/broadcast.test.ts` | todo | |
| `lib/bunny.test.ts` | done | tests/Unit/Video/BunnyTest.php |
| `lib/client-bundle.test.ts` | todo | |
| `lib/content-language.test.ts` | todo | |
| `lib/content.test.ts` | done | tests/Unit/Library/ContentTest.php; the DB-backed checks in tests/Integration/ContentAccessTest.php |
| `lib/cover.test.ts` | todo | |
| `lib/cron-guard.test.ts` | done | tests/Unit/Jobs/CronGuardTest.php (no development exception: see Deviations) |
| `lib/cron.test.ts` | todo | |
| `lib/cross-site.test.ts` | todo | |
| `lib/data-export.test.ts` | done | tests/Unit/Profile/DataExportTest.php (+ a schema completeness check) and tests/Integration/DataExportTest.php |
| `lib/device-settings.test.ts` | done | tests/js/device-settings.test.mjs |
| `lib/directory.test.ts` | done | tests/Unit/Plugins/DirectoryTest.php (every case) and tests/Integration/DiscoveryPluginsTest.php |
| `lib/download-source.test.ts` | done | tests/Unit/Video/DownloadSourceTest.php |
| `lib/downloads.test.ts` | done | tests/Unit/DownloadsTest.php |
| `lib/event-series.test.ts` | todo | |
| `lib/events.test.ts` | todo | |
| `lib/filename.test.ts` | done | tests/Unit/Support/SupportTest.php |
| `lib/forms.test.ts` | todo | |
| `lib/group-messages.test.ts` | todo | |
| `lib/groups.test.ts` | todo | |
| `lib/guides.test.ts` | todo | |
| `lib/hymnal.test.ts` | todo | |
| `lib/i18n/i18n.test.ts` | done | tests/Unit/I18n/I18nTest.php |
| `lib/ics.test.ts` | todo | |
| `lib/identity-linking.test.ts` | done | tests/Unit/Access/IdentityLinkingTest.php |
| `lib/live-chat.test.ts` | todo | |
| `lib/names.test.ts` | todo | |
| `lib/nav-tabs.test.ts` | done | tests/js/nav-tabs.test.mjs |
| `lib/offline-calendar.test.ts` | todo | |
| `lib/offline-shell.test.ts` | todo | |
| `lib/outline.test.ts` | done | tests/Unit/Plugins/OutlineTest.php (every case) and tests/Integration/SermonNotesTest.php |
| `lib/page-offset.test.ts` | todo | |
| `lib/permissions.test.ts` | done | tests/Unit/Access/PermissionsTest.php |
| `lib/plugins.test.ts` | done | tests/Unit/Plugins/PluginStatesTest.php |
| `lib/podcast-mirror.test.ts` | done | tests/Unit/PodcastMirrorTest.php |
| `lib/prayer.test.ts` | todo | |
| `lib/public-url.test.ts` | done | tests/Unit/Core/PublicUrlTest.php |
| `lib/push-endpoint.test.ts` | done | tests/Unit/Push/PushEndpointTest.php |
| `lib/reader-cache.test.ts` | todo | |
| `lib/reader.test.ts` | todo | |
| `lib/recurrence.test.ts` | todo | |
| `lib/reorder.test.ts` | done | tests/Unit/Support/SupportTest.php |
| `lib/rota.test.ts` | todo | |
| `lib/schedules/duplicates.test.ts` | todo | |
| `lib/schedules/logic.test.ts` | todo | |
| `lib/schedules/visibility.test.ts` | todo | |
| `lib/services.test.ts` | todo | |
| `lib/share-links.test.ts` | done | tests/Unit/Library/ShareLinksTest.php |
| `lib/share-password.test.ts` | done | tests/Unit/SharePasswordTest.php, plus a hash made by Node |
| `lib/sheets/dates.test.ts` | todo | |
| `lib/sheets/parse.test.ts` | todo | |
| `lib/slug.test.ts` | done | tests/Unit/Support/SupportTest.php |
| `lib/sms.test.ts` | todo | |
| `lib/toc-nav.test.ts` | todo | |
| `lib/transcribe-worker.test.ts` | done | tests/Integration/TranscriptionTest.php (claim, DONE/FAILED, stale sweep, deadline) |
| `lib/transcribe.test.ts` | done | tests/Integration/TranscriptionTest.php (multipart shape, model/language fields, size limit, the settings test) |
| `lib/tv-feed.test.ts` | todo | |
| `lib/tv-nav.test.ts` | todo | |
| `lib/tv-pairing.test.ts` | todo | |
| `lib/upload-types.test.ts` | done | tests/Unit/Files/UploadTypesTest.php |
| `lib/validation/schemas.test.ts` | todo | |
| `lib/verses.test.ts` | todo | |
| `lib/video-feed-sync.test.ts` | done | tests/Unit/Video/VideoFeedSyncTest.php, plus the fetchers against recorded answers |
| `lib/video-source.test.ts` | done | tests/Unit/Library/VideoSourceTest.php |
| `lib/view-key.test.ts` | done | tests/Unit/Library/ViewKeyTest.php |

## Security review (route by route)

Filled in during step 6: each route above against the Security requirements
of the brief (CSRF, validation allowlist, capability, rate limit, headers,
output escaping, SSRF). No rows yet.

| Route | Result | Notes |
|---|---|---|

## Deviations

Decisions in the brief that turned out wrong or impossible against what was
met, with the reason.

- **Admin → Services lives at `/admin/providers`.** Appendix C gives `/admin/services` to service plans, and the compatibility contract wins; the menu still says "Services".
- **The cron guard has no development exception.** The original stayed open with no secret outside production; the brief says fail closed, so `CronGuardTest` replaces "stays open in development" with "fails closed everywhere".
- **SMTP is a native client, not PHPMailer.** PHPMailer is on the allowed list, not required; a ~250-line client with STARTTLS/TLS and AUTH PLAIN/LOGIN keeps the release free of vendored code for this slot. Swap in PHPMailer later without changing the provider's interface if a host needs something it lacks.
- **The installer's Services step explains the defaults rather than testing providers.** Local sign-in and local files are set; email, video and texting are "set up later" at Admin → Services, where every provider is tested before switching. Testing inside the wizard would duplicate that screen.
- **Bundled plugins default to active**, as the brief says of `ensurePluginsSeeded()`; FEATURES.md's "off until an admin turns it on" describes the original's UI, not its seeding.
- **Vendored viewers stay at `/pdfjs/`, `/epubjs/`, `/tesseract/`** (Appendix H) rather than under `public/vendor-js/`: the offline shell and saved caches name those paths.
- **`sw.js` and `offline.html` derive the base path** (from the service worker's own URL) and prefix their literal paths with it. At a domain root they behave byte-for-byte as before.
- **Schema additions:** `users.password_hash`, `email_verified_at`, `pending_email` (local accounts); `file_assets.backend`, `storage_path` (was `bunnyPath`), `upload_pending`; `push_subscriptions.endpoint_hash` (the unique index; a push URL can outrun an index prefix); `broadcast_recipients.provider`, `provider_message_id`, `delivery_status`, `delivered_at` (SMS receipts); plus `series_tags`, `video_scripture_books`, `sessions`, `services`, `settings`, `jobs`, `email_log`, `auth_tokens`, `rate_limits`, `uploads`. Tables are plural snake_case (`watch_progresses`, `people`).
- **`/auth/recover`** is new under `/auth/*`: with `storage/enable-local-login` present it sets an administrator's password against a code written to `storage/recovery.key` — the lockout path when email isn't set up.
- **Browser-module tests run under `node --test`** in CI (development only; nothing Node ships).
- **`MT_STORAGE_DIR`** overrides the storage directory for the test suite only.
- **A release is signed through its manifest.** The brief signs "the archive's checksum"; a zip can't carry its own checksum, so the build signs `MANIFEST.json` (Ed25519, `MANIFEST.sig` beside it) and the manifest lists every file's SHA-256. One upload is then self-contained, and the check is the same: nothing is unpacked until the signature verifies, and every unpacked file must match. The installed `MANIFEST.json` stays at the root so the next release can remove what it drops. The public key is `app/release-key.pub`, empty until the maintainers generate one (`tools/release/keygen.php`); without it the page says to upload by FTP.
- **The site closes itself when its files are newer than its database** (`app.version` differs from `App::VERSION`), not only while `storage/maintenance` exists, so an FTP upload never serves new code against the old schema to visitors. Administrators still get through, with a notice pointing to /admin/update.
- **The backup is a multi-member gzip**: each request appends its own member, which is valid gzip (RFC 1952) and what `gunzip`, `zcat` and phpMyAdmin (zlib's `gzread`) read as one stream — PHP's `gzdecode()` alone stops after the first. Rows of `sessions`, `rate_limits` and `uploads` are left out (their tables are kept): they are throwaway state.
- **Two profile routes the port adds for local accounts:** `POST /api/profile/password` (current + new; ends every other session) and `DELETE /api/profile/sessions` ("sign out everywhere else"), which the brief puts on /profile/settings without naming routes. The inbox API's JSON shape is the port's own (`{notifications, hasMore, unreadCount}`), since the brief names the route but not its body.
- **The query monitor's deploy-level switch is `'query_monitor' => true` in `storage/config.php`** (the port's stand-in for `QUERY_MONITOR_ENABLED`), since a host without a shell has no environment variables; like the original's, the admin page can only report it.
- **Videos store `provider` + `external_id`** (with `provider_data` for a provider's own bookkeeping) instead of `source` + `bunnyVideoId` + `externalId`, since the port has ten providers rather than three. `Library\Presenter::video()` sends the original fields back — `source` (BUNNY/YOUTUBE/VIMEO, or the port's provider name), `bunnyVideoId`, `externalId` — so every JSON shape is unchanged for the original three.
- **Members-only is inherited down the tree**: a series, video or file is members-only when it, its series, or any category above it says so (the original read the item's own flag, and its series' for files). A category marked members-only now means everything in it, which is what an admin ticking it expects. Viewer restrictions and share grants decide as before.
- **Member-only content stays out of the sitemap too**, following "a guest browsing the site never sees that the content exists" rather than the older README line that listed member-only categories and series there.
- **`POST /api/admin/media`** (the port's) stores a cover, speaker photo or thumbnail uploaded through the chunked uploader as a redrawn image under storage/media and answers its address; the original put these in Bunny Storage.
- **The video provider interface takes a `VideoRef` (id + the row's `provider_data`)** rather than a bare id, and adds `completeUpload()` (what the browser reports after an upload: part ETags, the service's id) and `owns()`. Ten providers each keep different bookkeeping; the brief's interface assumed one id suffices.
- **Videos on the host's disk that anybody may watch live in `public/media/videos/`**, the one place outside storage/ the port writes, so the web server streams them without PHP. Every save re-decides (a guest's access decision): anything else is moved back into `storage/videos/` and streams through `/api/videos/local/[name]` after the page's own access check. Releases never ship or remove `public/media/`, and the uploads backup includes both folders.
- **The admin video editor is a page, `/admin/videos/[id]`** (the port's), like the series editor, rather than a dialog on the list.
- **Captions for providers without caption APIs are WebVTT sidecars** in `storage/media/captions/<random>.vtt`, listed in `provider_data.tracks`; like every /media file their names are random, so members-only captions are as private as an unguessable address.
- **Vendored browser code for video:** `public/vendor-js/tus/` (tus-js-client 4.3.1) and `public/vendor-js/hls/` (hls.js light 1.6.15), each with its LICENSE and VERSION.
- **The player speaks each embed's postMessage protocol itself** (YouTube's widget messages, Vimeo's player API messages, Player.js for Bunny) instead of loading the YouTube IFrame API, the Vimeo Player SDK or player.js into the page: no third-party script runs in the site's origin, and the CSP needs only frame-src for them. The heartbeat is accurate wherever a protocol or a native `<video>` reports position, elapsed-time elsewhere (Google Drive preview).
- **Host-disk video is the one exception to "video bytes never pass through PHP"**: its upload is chunked through the site (there is nowhere else for it to go) and a video not everybody may watch streams through `/api/videos/local/[name]`, offloaded by X-Sendfile / X-Accel-Redirect / X-LiteSpeed-Location where detected. Videos anybody may watch sit in `public/media/videos/` for the web server; a `local-videos` job (every five minutes) and every change above a video (category, series, viewer restriction, restore — the `library.changed` hook the library's audit fires) move files between the two, so a take-down time or a category going members-only never leaves a public copy.
- **Share passwords**: new ones use `password_hash()`; imported `scrypt$salt$key` hashes are checked by a vendored pure-PHP scrypt (`app/Support/Scrypt.php`, RFC 7914 vectors and a Node-made hash in the tests, about 2.5 s and 22 MB at Node's defaults) and rehashed on the first right guess. Because that check is slow, unlocking is also limited to 30 tries per address per 15 minutes, beside the per-link lockout.
- **Video feeds read their keys from the YouTube and Vimeo provider settings** (Data API key, access token) rather than YOUTUBE_API_KEY / VIMEO_ACCESS_TOKEN, and the screen names the missing one. `/admin/downloads` needs manage_plugins, as its place in the menu says.
- **`Http` streams large transfers**: `bodyFile` uploads from disk and `sink`/`onHeaders` hand the body on in chunks, so a Bunny Storage upload, proxy or podcast copy never holds a file in memory (curl; the streams fallback can stream down but must read an upload into memory).
- **Replacing a file only deletes the old object when this site wrote it** (`files/…`); an object imported from the zone belongs to whoever put it there.
- **A sign-in switch needs a trial sign-in**, as the brief asks: after the test passes, the admin signs in through the new provider with the tested settings; the switch happens only if it comes back as that admin's own verified address and the current authorization rules would let them in (so a switch can't lock them out). The trial identity is linked to the admin.
- **OpenID Connect subs are prefixed with the preset** (`google|…`, `entra|…`, `oidc|…` for a custom issuer) rather than one "oidc" prefix, so two issuers' subjects can never meet; Auth0's stay verbatim.
- **Apple's form_post** arrives cross-site without the Lax session cookie, so /auth/callback answers such a POST with a same-origin page that re-posts it once; the spent-once state is that route's CSRF protection.
- **Callback failures from any provider are recorded with the reason `AUTH0_CALLBACK_ERROR`**, the original's name, so existing reports keep working.
- **Chapters seek the player in place** (native currentTime, or each embed's postMessage seek) instead of reloading the iframe with a new start time; each chapter's link is still the `?t=` address, so a shared link behaves as before.
- **Integrations are settings groups at `/admin/integrations/[group]`**, listed under Admin → Services beside the slots: the same generated fields and Test button as a provider, plus Save and Switch off, stored as one `integration.<group>` setting with its secrets encrypted. Transcription is the first; Web Push, Google Sheets and video import join it as their features arrive.
- **A transcription server must be at a public address.** The brief allows "a Whisper server on a machine in the office", but the transcription URL is typed by an admin, and the untrusted-URL rule (no loopback, private or link-local addresses, on any hop) wins over that convenience. Such a server needs a public address (a port forward or a tunnel). The request is pinned to the address the check resolved and follows no redirects.
- **Transcription takes one video per job run and may outlive the 20-second budget.** The budget decides whether to start another video, not how long one may take: a single speech-to-text request can't be split without an audio tool on the host. The job raises its own time limit where the host allows (`set_time_limit`), and a run the host kills leaves the video RUNNING until the half-hour sweep re-queues it. The file sent is the video's own MP4 (the smallest rendition where the provider has several), streamed through storage/tmp, and a file above the configured limit (25 MB by default, OpenAI's) is refused before anything is sent.
- **`/admin/media-check` is defined by the port**: the brief names the route and nothing else. It reports, within the reader's part of the library, videos whose service is no longer installed, host-disk videos whose file is missing, videos failed or processing for over a day, failed transcriptions, and local files missing from storage; pasted direct and Dropbox links are checked on request, a batch at a time, through the untrusted-URL fetcher. Administrators also see host-disk video files no video (trash included) names, and may delete one unless it changed within the hour. JSON at `GET /api/admin/media-check`.
- **The "Share at" box belongs to the Social share plugin**, as the brief describes it, so it leaves the video page when that plugin is off; the `?t=` link itself is the library's and always works.
- **The request bodies of `/api/favorites`, `/api/watch-later`, `/api/ratings`, `/api/reactions`, `/api/subscriptions`, `/api/playlists/*` and `/api/notes`** are the port's (`{seriesId}`, `{videoId}`, `{categoryId}` → `{favorited}` / `{saved}`), since the brief names the routes and methods only. Each is a toggle, so a stale page can't double-add.
- **Chapters, transcripts, trending and recommendations moved into `plugins/`** once the page hooks existed: the chapter and transcript panels are page.video.panels, and the two homepage rows are filled through the home.row filter by Recommendations and View counts. What stays in the library is the content (chapters edited on the video page, the transcript column) and the transcript's part in search, which the search asks of the plugin state. Share links and downloads, built in step 3, still live in the library gated by their plugin's state.
- **A display name counts only while the Profiles plugin is on**; switched off, members are shown by their sign-in name again and `/directory` is gone. The account fields stay stored either way.
- **Bundled plugins add no data-export sections of their own**: the core's export already holds every member table (favorites, ratings, reactions, watch history…), whether or not the plugin that writes them is on, since the data outlives the switch.
- **The webhook payload is the port's**: `{event, id, title, slug, url, memberOnly, publishedAt}` (the brief names the event and the signature, not the body). Secrets are stored encrypted under app_key rather than in plain text; a delivery that fails is logged, not retried, as before.
- **Web Push is native, not minishlink/web-push**: `App\Support\WebPush` does VAPID (ES256, through the JWT code the sign-in providers use) and RFC 8291 aes128gcm encryption with OpenSSL's P-256 key agreement, `hash_hkdf` and AES-128-GCM — so neither bcmath/gmp nor a vendored dependency tree is needed. A unit test decrypts as a browser would. Keys keep the `web-push generate-vapid-keys` format, so the old site's pair carries over. The sender and `/api/push/*` are core (Live streaming and Broadcasts push too); the Web Push keys are an integration under Admin → Services, where "Generate" saves a new pair at once rather than carrying a secret back through the form.
- **Publishing notifies after the response** (where the host has `fastcgi_finish_request`), checking each member's access to the video — a viewer restriction keeps a video out of the inboxes of people who can't watch it. A daily-digest notification for a member with no browser signed up is dropped rather than queued forever.
- **`/robots.txt`** is served by the app (base-path aware, pointing at the sitemap); the original had none.
- **The view beacon's cookie is `mt_views`**, one cookie listing recently viewed ids with their times, since Appendix H names no cookie for it.
- **Uploaded images (logo, artwork) are served at `/media/<kind>/<random>.<ext>` from `storage/media/`**, through the app, with a year-long immutable cache and a sandbox CSP. `storage/` is the only place the site writes, so nothing lands in `public/`.
- **OneDrive uploads go to a Business or SharePoint drive only.** The brief offers upload "for either"; personal OneDrive has no app-only access, and a delegated refresh token there rotates and lapses, which would need the site to rewrite its own saved settings from a background request. Personal OneDrive links still resolve and play; uploads use the same Entra app the Business links already need, with Files.ReadWrite.All and a chosen drive.
- **Supabase Auth is a form flow over GoTrue's REST API**, not supabase-js in the page: the password, magic-link and social forms post to `/auth/supabase/*`, so no third-party script runs in the site's origin and the access token is verified server-side (JWT secret or the project's JWKS). The magic-link form never creates accounts (`create_user: false`).

## Session log

- 2026-09-25 — step 1: this map. The brief arrived as the session prompt;
  it is now committed verbatim as `PORT_PROMPT.md` at the repository root, as
  it asks, so later sessions have the appendices.
- 2026-09-25 — step 2 largely done: core framework, full schema, installer
  (smoke-tested over HTTP), local sign-in with recovery, services registry
  with email (none, mail(), SMTP, Resend) and local files, plugin loader with
  every auto-deactivation path proven, theme loader, jobs and /cron/run, i18n,
  device settings, PWA static files, CI. 185 unit, 8 integration and 15
  browser-module tests. Next: the remaining step-2 admin pages (branding,
  appearance, update, tools, profile shell), then step 3 (the Library).
- 2026-09-25 — the Access admin area (users, allowlist, access attempts,
  permissions, audit with export) and branding with logo upload, all over the
  JSON API from Appendix C through one `data-api` form handler; `Json::row`
  presents rows with the original's field names and types, read from the
  schema. 195 unit, 11 integration, 15 browser-module tests.
- 2026-09-25 — the ten video providers (Bunny, YouTube, Vimeo, Dropbox,
  Google Drive, OneDrive, Internet Archive, S3-compatible, direct link, host
  disk) and /admin/videos: add by link or by upload through each service's
  own protocol (tus, presigned PUT/multipart, resumable session, chunked),
  status sync, thumbnails, captions, bulk actions, Bunny import. Verified in
  Chromium against the host-disk provider and a YouTube link.
- 2026-09-25 — /admin/files and /api/files/[id]/content; the player module
  (native with hls.js, iframe with the YouTube/Vimeo/Player.js protocols,
  verified against stub embeds since the sandbox can't load YouTube), the
  watch-progress heartbeat and mark-watched, and the sync-video-status and
  local-videos jobs.
- 2026-09-25 — public library: home, category, series and video pages
  (aliases, gates with generic titles, sequential unlock, premieres,
  resume, JSON-LD), search with the fuzzy fallback, tags, speakers,
  scripture, recently added, /feed.xml, podcast feeds, /sitemap.xml
  (the port's route; the original's sitemap.ts), /robots.txt and the view
  beacon. 359 unit, 20 integration, 25 browser-module tests.

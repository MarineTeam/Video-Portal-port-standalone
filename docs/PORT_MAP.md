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
| 4 | Bundled plugins, simplest first | done (the 21 member plugins and comments; the rest of Appendix E — live streaming, book reader, service plans, schedules, events, groups, prayer, forms, television — are step 5) |
| 5 | Books/hymnals, services/rota, schedules/sheets, events, forms, prayer, groups, broadcasts/SMS, live, television, read API, export/import | in progress (live streaming and chat, prayer wall, events, forms, small groups, service plans and the rota, schedules and Google Sheets, television, broadcasts and the SMS providers, the book and hymnal reader, the read API and API keys, the Next.js export and import) |
| 6 | Hardening and docs: smoke test, security walk, INSTALL/PLUGINS/THEMES/UPGRADING/SERVICES, migration guide | in progress (SERVICES.md rewritten with a row per provider and a CI check that it cannot drift; PLUGINS.md's hook table brought up to date; UPGRADING.md written; the security walk done as a test) |

## Areas (Feature inventory)

| Area | Kind | Status | Notes |
|---|---|---|---|
| Core framework (Router, Db, View, Session, Csrf, Http, Hooks, Cache, Jobs, Migrator, Log, errors) | core | done | app/Core; PHPStan level 6 clean |
| Installer (`/install`) and upgrader (`/admin/update`), backups (`/admin/tools`) | core | partial | Installer done and covered by the smoke test; /admin/update done (maintenance, resumable migrations, signed release zips with rollback; verified end to end against a scratch install); /admin/tools backup (.sql.gz a step per request, restores exactly — BackupTest) and files in 100 MB parts done; the Next.js import done (see the row below) |
| Services registry and Admin → Services | core | partial | Registry, generated forms, signed test-then-switch at /admin/providers; auth trial-mode switch arrives with external providers |
| Library (categories, series, videos, files, speakers, scripture, tags, search, trash, feeds, sitemap, metadata) | core | done | Admin, providers, player, public pages, search, feeds, sitemap, JSON-LD, share links, downloads, video feeds, home rows, chapters, transcription, media check; the library's plugins (comments, related, up next…) come with step 4 |
| Access (sign-in providers, allowlist, identities, permissions, capabilities, audit, API keys) | core | partial | API keys done (/admin/api-keys, hashed at rest, scopes, per-key rate limit); the rest with the auth work |
| Site (branding, i18n, nav, device settings, standalone chrome, inbox, profile, data export, video feeds, query monitor) | core | partial | Branding, i18n, nav, device settings, per-device bottom bar, inbox, profile shell, data export, query monitor done; video feeds with the Library (step 3) |
| Read API `/api/v1` | core | done | Eleven endpoints, bearer keys, six scopes, keyset paging, `assertExportSafe` over every payload |
| PWA and offline shell (sw.js, offline.html, manifest) | core | partial | Static files shipped (base-path aware); the saving side (offline-books etc.) arrives with its modules |
| Plugin loader, auto-deactivation, per-category overrides | core | done | All three load-failure paths plus the hook breaker, proven by tests/Integration/SmokeTest.php |
| Theme loader, default theme, customizer | core | done | Loader with child → parent → core, fallback with notice, /admin/appearance (install, activate, delete, customizer merged over branding) |
| Member plugins (favorites … downloads, 21 of Appendix E) | plugins | done | all 21 in plugins/, each against the page hooks (page.category/series/video.panels, home.row, render.page_top, related.items) and the library's classes; integration tests through a real server (tests/Integration/*Test.php extending ServerTestCase) |
| Live streaming and chat | plugin | done | plugins/live-streaming: /live, the "Live now" banner and nav entry, /admin/live, and a polling chat that opens half an hour early and closes an hour after |
| Book reader, hymnals, service plans, rota | plugins | todo | |
| Schedules and Google Sheets | plugin | todo | |
| Events and event series, forms, prayer, small groups (attendance, guides, thread), directory, broadcasts and SMS | plugins | in progress | Prayer wall, events with repeats and calendar feeds, forms, and small groups with attendance, guides and the thread (plugins/groups) done; broadcasts and SMS todo |
| Television | plugin | todo | |
| Data import from the Next.js deployment (`tools/export-from-nextjs`, `/admin/tools/import`) | core | done | export.mjs (pg, a server-side cursor, its own zip writer, no dependency but pg) and the three-phase resumable importer on /admin/tools; counts checked against the manifest; files still in Bunny Storage pulled across in batches |

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
| sms | Twilio, Vonage, MessageBird (Bird), Plivo, Sinch, Telnyx, Amazon SNS, ClickSend, Textlocal, BulkSMS, JSON webhook | done | Each one's test ends with a text to the administrator's own phone carrying a code they type back. Signed callbacks where the provider has a scheme (Twilio HMAC-SHA1, Vonage's JWT, MessageBird's timestamped hash, Plivo's nonce, Telnyx's Ed25519); the rest get a per-install secret in the callback address |

## Pages (Appendix C.1) — 91

| Path | Status | Notes |
|---|---|---|
| `/` | done | Library\Pages: hero (featured, else newest series), Continue watching above the browse tiles, then the rows from /admin/home-rows (Because you watched, Trending this week, Recently added, category and tag rows) |
| `/access-denied` | done | One plain sentence; guest link only while the switch is open |
| `/admin` | partial | Dashboard with counts and setup warnings; library cards pending |
| `/admin/access-attempts` | done | Filter by address, reason, date and unreviewed; mark reviewed; prune past 90 days |
| `/admin/analytics` | todo | |
| `/admin/announcements` | done | plugins/announcements |
| `/admin/api-keys` | done | The key is shown once at creation and never again; revoking keeps the row and its history |
| `/admin/audit` | done | Paged, filterable; CSV/JSON export streamed, cells that start with = + - @ are quoted |
| `/admin/authorized-emails` | done | Allowlist with search and status filter; never suspends or removes the last active entry; organisation exemption per address; guest-login switch |
| `/admin/branding` | done | Name, short name, three colours with live preview, logo by URL or upload (re-encoded by GD, served from storage/media) |
| `/admin/broadcasts` | done | The composer, with the count it will reach and the reason for the rest before it goes |
| `/admin/categories` | done | Tree to any depth, ↑↓ among siblings, trash; administrators only (admin-nav) |
| `/admin/categories/[id]` | done | Every field incl. parent (cycle-guarded), cover upload, three-way downloads |
| `/admin/comments` | done | plugins/comments: the reported-or-hidden queue, scoped to the moderator's part of the library |
| `/admin/downloads` | done | Who (any member, or roles and people) and where (web, app, both), suggested space |
| `/admin/events` | done | The diary, the add form, and the repeats (`manage_events`) |
| `/admin/events/[id]` | done | One event's fields and the list for the door, on screen and as a CSV with a column saying who is a member |
| `/admin/files` | done | FilesAdmin: chunked upload stored with the Files slot, inline edit, replace, bulk (incl. podcast), kind filter |
| `/admin/forms` | done | The list, and adding one (`manage_events`) |
| `/admin/forms/[id]` | done | Its settings, its questions (add, rename, reorder, retire) and every response, with the CSV beside them |
| `/admin/groups` | done | The list, with a group that has no leader flagged (`manage_events`) |
| `/admin/groups/[id]` | done | One group's fields and everybody against it, including who has only asked |
| `/admin/home-rows` | done | Library\Admin\HomeRowsAdmin: toggle, rename, reorder the built-in rows; add category and tag rows; says when a row's plugin is off |
| `/admin/live` | done | Schedule a stream (title, embed address, cover, start/end), publish it, switch its chat on and set slow mode (manage_plugins) |
| `/admin/media-check` | done | Library\Admin\MediaCheckAdmin (see Deviations): videos whose service is gone, whose host-disk file is missing, stuck or failed, failed transcriptions; local files missing; pasted links checked on request; unused host-disk video files (administrators may delete) |
| `/admin/people` | done | Names, their dates, the account a name signs in as, and near-duplicates offered for merging |
| `/admin/permissions` | done | Groups (capabilities sanitised to the known list), assignments site-wide or scoped to a category/series, category and series editors |
| `/admin/plugins` | done | Activate, per-category overrides, zip install/delete, auto-deactivation notices; never loads third-party plugins |
| `/admin/prayer` | done | The queue, waiting first: let through, take down, mark answered with a line saying what happened, change the audience, delete (`moderate_prayer`) |
| `/admin/query-monitor` | done | Reports the storage/config.php flag, toggles the bar (plugins row "query-monitor", fail-open) |
| `/admin/schedules` | done | The rotas in the order the calendar shows them, and the one Google service account key |
| `/admin/schedules/[id]` | done | Where it comes from, Test connection, and the dates typed in here |
| `/admin/series` | done | Scoped to the editor’s part of the library; filter, bulk publish/unpublish/move/delete, ↑↓ |
| `/admin/series/[id]` | done | Publish now / Save as draft / Load draft, tags, slug rename leaves an alias, restricted viewing |
| `/admin/services` | done | Service plans (`manage_files`), with What we sang beside them |
| `/admin/services/report` | done | Every song in a window, how many services it was sung in, its CCLI number, author and copyright, and a CSV |
| `/admin/share-links` | done | Filter active/revoked, revoke (audited), create for any series or video |
| `/admin/speakers` | done |  |
| `/admin/teams` | done | The pick-list a rota is built from |
| `/admin/trash` | done | Restore; delete for good removes the provider asset first |
| `/admin/users` | done | Roles (ADMIN only, never the last admin), pre-authorise by email, revoke; changing a role signs the person out |
| `/admin/video-feeds` | done | Administrators only (a feed can file videos anywhere) |
| `/admin/videos` | done | VideosAdmin: add by link or upload (tus, presigned PUT/multipart, resumable, chunked), Bunny import, bulk; edit page at /admin/videos/[id] (the port's) with thumbnail, captions, restricted viewing |
| `/admin/webhooks` | done | plugins/webhooks |
| `/books/[fileId]` | done | A book's contents, and where a `?hymn=` number lands, with the typed-out words when there are any (plugins/book-reader) |
| `/calendar` | done | Chip row, Only mine, keep-on-device; noindex, and no names without a sign-in |
| `/categories/[slug]` | done | Children, series, standalone videos and files; generic title + sign-in page (401) for a members-only one |
| `/directory` | done | plugins/profiles: name only by default, each contact detail its own yes, search by name and note only, noindex |
| `/events` | done | What's on; a members-only event is absent rather than refused |
| `/events/[slug]` | done | The event, sign-up (no account needed), "Add to my calendar", and the next few dates of its repeat |
| `/favorites` | done | plugins/favorites |
| `/forms` | done | The published forms this reader may open |
| `/forms/[slug]` | done | The form as its rows describe it; a members-only one is absent rather than refused |
| `/groups` | done | The list somebody is choosing between: the district, never the address |
| `/groups/[slug]` | done | The group, its requests for its leader, its conversation and its roll |
| `/guides` | done | Published guides, counted in questions |
| `/guides/[slug]` | done | The questions, the scripture and the notes; leader notes only for whoever leads a group |
| `/hymns/[fileId]` | done | One hymn that is its own file: its words and its credits |
| `/link` | done | Type the code from the screen, and be asked by name whether to sign that television in |
| `/live` | done | Whatever is on now, a countdown to the next one otherwise, "Coming up" underneath, and the chat beside it |
| `/playlists` | done | plugins/playlists |
| `/playlists/[id]` | done | the owner's, or read-only for anyone once shareable (noindex); each reader sees only the videos they may watch |
| `/prayer` | done | The wall as this reader may see it, the form to ask (honeypot, rate-limited), and "I prayed for this"; noindex |
| `/present/[fileId]` | done | The words, big, one verse at a time, with the copyright line up throughout; refuses a hymn nobody has typed |
| `/profile` | done | Overview: unread count and plugin cards (profile.overview) |
| `/profile/devices` | done | The televisions signed in to this account, and signing one out |
| `/profile/downloads` | done | This device’s saved videos (self-healing), Wi-Fi-only choice, space used and the browser quota |
| `/profile/events` | done | The member's own sign-ups, and cancelling from there |
| `/profile/groups` | done | The member's own groups and asks |
| `/profile/inbox` | done | Mark one/all read, open, delete one/all; push toggle slot for the notifications plugin |
| `/profile/rota` | done | What this member is on for, answering, asking for cover, taking somebody's slot, and when they are away |
| `/profile/settings` | done | This device (theme, language, autoplay, speed, reading, bottom bar), account fields by plugin, password and sign-out-elsewhere, download my data, delete account |
| `/profile/shared-links` | done | The member’s own links |
| `/read/[fileId]` | done | The in-app reader: pdf.js or epub.js behind one handle, contents, in-book search, marks, read-aloud, and a copy kept on the device. A browser too old to draw the pages gets the book in its own viewer, at the page it was on |
| `/recently-added` | done | Newest series and videos |
| `/recently-played` | done | plugins/watch-history |
| `/scripture` | done | Books with a video the reader may open, in canonical order |
| `/scripture/[book]` | done |  |
| `/search` | done | Library\Search: ranked substring + FULLTEXT pass, fuzzy re-rank of ≤500 titles only on an empty result; category/speaker filters, newest sort; content.search_sources for plugins; 100-char cap |
| `/series/[slug]` | done | Slug aliases 301; sequential unlock (series or its category); tags; files; BreadcrumbList |
| `/services` | done | Published running orders |
| `/services/[id]` | done | The order, each row resolved against the library as it stands now, and who is on — names for members, the shape for anybody |
| `/share/unavailable` | done | Says revoked, expired or another account |
| `/share/unlock/[token]` | done | Password first; nothing granted or counted until it’s right |
| `/speakers` | done | With counts of videos the reader may open |
| `/speakers/[slug]` | done | Person JSON-LD |
| `/subscriptions` | done | plugins/subscriptions: follows with mute and unfollow |
| `/tags/[tag]` | done | Series carrying the tag (series_tags) |
| `/tv` | done | The library at arm's length: rows of tiles walked with four arrows, over the app chrome rather than beside it. `/tv/[slug]` plays one |
| `/videos/[slug]` | done | Aliases 301 keeping ?t=; resume from progress unless ?t=; premiere and lock placeholders; mark watched; share-at; VideoObject + BreadcrumbList JSON-LD |
| `/watch-later` | done | plugins/watch-later (categories, series, videos) |

## Routes (Appendix C.2) — 218

| Path | Methods | Status | Notes |
|---|---|---|---|
| `/api/admin/access-attempts` | GET POST | done | Filters as the page; POST {action: review|prune} |
| `/api/admin/analytics/export` | GET | todo | |
| `/api/admin/announcements/[id]` | PATCH DELETE | done | plugins/announcements |
| `/api/admin/announcements` | GET POST | done | message, active, publishAt/expiresAt window, audience ALL/GUESTS/MEMBERS |
| `/api/admin/api-keys/[id]` | DELETE | done | Revokes (sets `revoked_at`); the row stays so audit and last-used survive |
| `/api/admin/api-keys` | GET POST | done | POST returns the one and only plaintext copy; GET lists prefixes, scopes, last use |
| `/api/admin/assignments` | POST DELETE | todo | |
| `/api/admin/audit/export` | GET | done | ?format=csv|json, streamed |
| `/api/admin/audit` | GET | done | Paged, filter by actor/action/entity/date |
| `/api/admin/authorized-emails/[id]` | PATCH DELETE | done | Last-active guard |
| `/api/admin/authorized-emails` | GET POST | done | 409 on a duplicate address |
| `/api/admin/branding` | GET PUT DELETE | done | javascript:/data: logos refused |
| `/api/admin/broadcasts/[id]` | GET DELETE | done | Also PATCH while it is still a draft; a message on its way is not editable |
| `/api/admin/broadcasts/[id]/send` | POST | done | Freezes the list on the first call, then works a batch at a time; the browser calls it until nothing is pending |
| `/api/admin/broadcasts/[id]/test` | POST | done | To whoever asked, whatever they have switched off — they are asking to see it |
| `/api/admin/broadcasts` | GET POST | done | Plus `/preview` for the count, and `/[id]/cancel` to stop one part-way |
| `/api/admin/bunny-audit` | GET | todo | |
| `/api/admin/calendar-events/[id]` | GET PATCH DELETE | done | |
| `/api/admin/categories/[id]` | PATCH DELETE | done | `{move: up|down|n}` reorders; DELETE trashes |
| `/api/admin/categories` | GET POST | done | Top-level creation for administrators only |
| `/api/admin/comments/[id]` | PATCH | done | `{hidden}`; showing again clears the reports |
| `/api/admin/comments` | GET | done | |
| `/api/admin/downloads` | GET PATCH | done |  |
| `/api/admin/editors/category/[id]` | DELETE | done |  |
| `/api/admin/editors/category` | POST | done | By email |
| `/api/admin/editors` | GET | done |  |
| `/api/admin/editors/series/[id]` | DELETE | done |  |
| `/api/admin/editors/series` | POST | done | By email |
| `/api/admin/events/[id]/registrations/[registrationId]` | DELETE | done | Cancels the place and moves the waiting list |
| `/api/admin/events/[id]/registrations` | GET | done | `?format=csv` for the door; no account id in either shape |
| `/api/admin/events/[id]` | PATCH DELETE | done | Raising the capacity moves the waiting list; deleting one date of a series adds it to the exclusion list in the same transaction |
| `/api/admin/events` | GET POST | done | `manage_events` |
| `/api/admin/events/series/[id]` | PATCH DELETE | done | Changing the timing clears empty future dates and lays them down again; DELETE stops the repeat without deleting anybody's place |
| `/api/admin/events/series` | GET POST | done | Takes the five shapes (`shape`, `days`, `interval`, `count`/`until`) or a raw `rule`; answers with the rule in words |
| `/api/admin/files/[id]/contents` | GET PUT | done | plugins/book-reader; the contents box typed by hand, or read from the book's own bookmarks |
| `/api/admin/files/[id]/lyrics` | GET PUT | todo | |
| `/api/admin/files/[id]/replace` | POST | done | Takes a chunked upload id; the Bunny Storage pick arrives with that provider |
| `/api/admin/files/[id]` | PATCH DELETE | done | PATCH also takes `move` |
| `/api/admin/files/[id]/text` | GET POST DELETE | todo | |
| `/api/admin/files/bulk` | POST | done | publish, unpublish, delete, move, podcast, unpodcast |
| `/api/admin/files/bunny-storage` | GET | done | ?dir=, marks what is already imported |
| `/api/admin/files/import` | POST | done | Objects stay where they are; each becomes a file row |
| `/api/admin/files` | GET POST | done | POST takes a chunked upload id (the port's uploader; the original posted the file) |
| `/api/admin/forms/[id]/fields/[fieldId]` | PATCH DELETE | done | Renaming keeps the answers; DELETE retires a question that has any and removes one that has none |
| `/api/admin/forms/[id]/fields` | POST | done | The ten kinds; a question offering a choice is refused without choices, before anything is written |
| `/api/admin/forms/[id]` | GET PATCH DELETE | done | Notify addresses are checked, so a typo is not stored as one |
| `/api/admin/forms/[id]/submissions/[submissionId]` | PATCH DELETE | done | `{handled}` records who dealt with it, by name |
| `/api/admin/forms/[id]/submissions` | GET | done | `?format=csv`; live questions first, retired ones after, so an export never silently drops what somebody said |
| `/api/admin/forms` | GET POST | done | `manage_events` |
| `/api/admin/group-assignments/[id]` | DELETE | done |  |
| `/api/admin/group-assignments` | GET POST | done | By userId or email; category xor series scope |
| `/api/admin/groups/[id]/members/[memberId]` | DELETE | done | Taking somebody off moves the waiting list |
| `/api/admin/groups/[id]/members` | POST | done | By email; this is how a site manager joins a conversation they need to read, leaving a row saying so |
| `/api/admin/groups/[id]` | GET PATCH DELETE | done | `leaderEmail` also puts a leader in, since a group with nobody to answer a request is the failure this screen is for |
| `/api/admin/groups` | GET POST | done | `manage_events` |
| `/api/admin/guest-login` | GET PATCH | done |  |
| `/api/admin/guides/[id]` | GET PATCH DELETE | done | plus POST `/api/admin/guides/[id]/items` and PATCH/DELETE on one item (the port's: the original edits items through the guide) |
| `/api/admin/guides` | GET POST | done | With /admin/guides, the page the brief asks for |
| `/api/admin/home-rows/[id]` | PATCH DELETE | done | title, enabled, move up/down; only curated rows delete |
| `/api/admin/home-rows` | GET POST | done | POST creates CATEGORY/TAG rows only |
| `/api/admin/live/[id]` | PATCH DELETE | done | Publishing one fires `live.published`, which tells members after the response has gone |
| `/api/admin/live` | GET POST | done | `manage_plugins`; an embed or cover address must be https |
| `/api/admin/people/[id]` | PATCH DELETE | done | A new spelling keeps the old one as an alias |
| `/api/admin/people/merge` | POST | done | Moves the history, keeps the losing spelling as an alias; never automatic |
| `/api/admin/people` | GET POST | done | |
| `/api/admin/permission-groups/[id]` | PATCH DELETE | done | A group with scoped assignments can’t gain site-wide-only capabilities |
| `/api/admin/permission-groups` | GET POST | done | Returns the capability list with the groups |
| `/api/admin/plugins/[slug]/overrides` | POST | done |  |
| `/api/admin/plugins/[slug]` | PATCH | done |  |
| `/api/admin/plugins/overrides/[id]` | DELETE | done |  |
| `/api/admin/plugins` | GET | done | PLUGIN_META slugs plus installed packages; never the query-monitor row |
| `/api/admin/prayer/[id]` | PATCH DELETE | done | `{status, visibility, answeredNote}`; the decision is audited, the words never are |
| `/api/admin/prayer` | GET | done | The same presenter as the wall, so an anonymous request is anonymous here too |
| `/api/admin/query-monitor` | PATCH | done | `{enabled}` → `{enabled, configured}` |
| `/api/admin/schedules/[id]/events` | GET POST | done | |
| `/api/admin/schedules/[id]` | GET PATCH DELETE | done | The sheet source is saved with it, under `source` |
| `/api/admin/schedules/[id]/sync` | POST | done | A failed import deletes nothing; an unchanged fingerprint writes nothing |
| `/api/admin/schedules/[id]/validate` | POST | done | Test connection: the events as read, and every skipped row with its reason |
| `/api/admin/schedules/reorder` | POST | done | |
| `/api/admin/schedules` | GET POST | done | |
| `/api/admin/series/[id]/draft` | GET PUT DELETE | done | One staged DraftRevision; any publish clears it |
| `/api/admin/series/[id]` | GET PATCH DELETE | done | publish fields need publish_content; moving needs the capability in both places |
| `/api/admin/series/[id]/viewer-groups` | GET POST | done |  |
| `/api/admin/series/[id]/viewers` | GET POST | done | By email of an existing member |
| `/api/admin/series` | GET POST | done | ?q, ?categoryId, ?page; scoped |
| `/api/admin/series/viewer-groups/[id]` | DELETE | done |  |
| `/api/admin/series/viewers/[id]` | DELETE | done |  |
| `/api/admin/services/[id]/rota` | GET | done | Who is on, with the answer each gave |
| `/api/admin/services/[id]` | PATCH DELETE | done | `items` replaces the order and `assignments` the rota, keeping the answers people already gave |
| `/api/admin/services/report` | GET | done | `?from`, `?to`, `?format=csv` |
| `/api/admin/services` | GET POST | done | `manage_files` |
| `/api/admin/share-links/[id]` | PATCH DELETE | done | DELETE revokes (keeps the row) |
| `/api/admin/share-links` | GET POST | done | GET ?state=active|revoked |
| `/api/admin/sheets/tabs` | GET | todo | |
| `/api/admin/speakers/[id]` | PATCH DELETE | done | Videos keep playing without a speaker |
| `/api/admin/speakers` | GET POST | done |  |
| `/api/admin/teams/[id]` | PATCH DELETE | done | `addEmail`/`addPosition` and `removeMemberId` keep the members on the same route |
| `/api/admin/teams` | GET POST | done | |
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
| `/api/calendar-events` | GET | done | Dates for anyone, people for members; a `personId` filter is a 403 signed out |
| `/api/calendar/[token]/marine-team.ics` | GET | done | The member's own diary; the token is the whole of the authentication, `private, no-store`, `X-Robots-Tag: noindex`, and on the export's forbidden-key list. Plugins fill it through `calendar.entries` |
| `/api/comments/[id]/report` | POST | done | once per member, never one's own |
| `/api/comments/[id]` | DELETE | done | the author, or a moderator for that part of the library |
| `/api/comments` | GET POST | done | GET for anybody who may open the page; POST `{seriesId|videoId, body, parentId?}` members, one level of replies, 10 a minute |
| `/api/cron/broadcasts` | GET | done | Job `broadcasts` every 5 minutes: the backstop for a closed laptop, not the delivery path |
| `/api/cron/extend-events` | GET | done | Job `extend-events`, daily at 02:20 UTC through /cron/run; keeps every series filled in six months ahead |
| `/api/cron/notification-digest` | GET | done | Job `notification-digest`, daily at 13:00 UTC (plugins/notifications) |
| `/api/cron/schedule-reminders` | GET | todo | |
| `/api/cron/sync-schedules` | GET POST | done | The `sync-schedules` job, 05:30 UTC, before the reminders |
| `/api/cron/sync-video-feeds` | GET | done | Job `sync-video-feeds`, daily at 07:15 UTC as before |
| `/api/cron/sync-video-status` | GET | done | Job `sync-video-status` every 15 min through /cron/run; abandoned upload placeholders marked FAILED after a day |
| `/api/cron/transcribe` | GET | done | Job `transcribe` every 10 minutes through /cron/run, 20 s budget for starting work; stale RUNNING (30 min) re-queued |
| `/api/downloads/[videoId]` | GET | done | Four gates after canViewVideo; an MP4 link or the specific reason there isn’t one |
| `/api/events/[slug]/register` | POST DELETE | done | Under a row lock on the event, so the last place goes to one person; honeypot and a per-address limit for visitors |
| `/api/favorites` | POST | done | `{seriesId}` or `{videoId}` toggles → `{favorited}`; 404 for what the member can't open; 403 `plugin_disabled` where a category switches it off |
| `/api/files/[id]/content` | GET | done | ContentAccess per request; Range, ETag/304, private no-cache, ?download=1; X-Sendfile family via RangeStreamer |
| `/api/files/[id]/search` | GET | done | plugins/book-reader; the words inside one book, from the indexed text, with the hymn each hit falls inside |
| `/api/forms/[slug]` | POST | done | `{answers: {fieldId: value}}`, honeypot and a per-address limit; the server decides what a valid answer is |
| `/api/groups/[slug]/join` | POST DELETE | done | A full group takes the name in order; leaving frees a place and moves the list |
| `/api/groups/[slug]/meetings` | GET POST | done | The roll: a member is given at most their own row, never a count |
| `/api/groups/[slug]/messages/[messageId]` | DELETE | done | Hidden rather than deleted; the author's own, or a leader's decision |
| `/api/groups/[slug]/messages` | GET POST PATCH | done | Standing is re-read from the database on every request; PATCH is the mute |
| `/api/groups/[slug]/requests/[memberId]` | PATCH | done | The leader's answer. Only a yes is a notification |
| `/api/groups/[slug]/requests` | GET | done | The group's own leaders, without a capability |
| `/api/groups` | GET | done | Every group through `presentGroup`, so no answer can carry an address it shouldn't |
| `/api/hymnals/search` | GET | done | Across every book this reader may open: by title, by printed number, and by the words inside a scan |
| `/api/hymns/lookup` | POST | done | One number across the shelf |
| `/api/inbox` | GET PATCH DELETE | done | `{notifications, hasMore, unreadCount}`; PATCH/DELETE take `{ids}` or `{all: true}` |
| `/api/live/[id]/chat/[messageId]` | DELETE | done | The author's own, or anybody's for a moderator; hidden rather than deleted |
| `/api/live/[id]/chat/mute` | POST | done | `{messageId, muted?}` — the person is named by their message, so no account id travels to a chat |
| `/api/live/[id]/chat` | GET POST | done | `?since=<id>` polls; the answer carries `state`, `slowMode`, `messages` and the `removed` ids (the port's shape) |
| `/api/locale` | POST | done | Sets marine-locale cookie |
| `/api/manifest` | GET | done | From branding, base-path aware |
| `/api/notes/[id]` | PATCH DELETE | done | the member's own |
| `/api/notes` | GET POST | done | `?videoId`; `&format=text` downloads the sheet and the notes as one text file |
| `/api/offline/hymnal/[seriesId]` | GET | done | A hymn-per-file series as JSON, with `?probe=1` answering only its fingerprint |
| `/api/offline/service/[id]` | GET | todo | |
| `/api/people` | GET | done | 403 without a session |
| `/api/playlists/[id]/items` | POST PATCH DELETE | done | `{videoId}`; PATCH `{videoId, move}` or `{order}` |
| `/api/playlists/[id]` | GET PATCH DELETE | done | `{playlist, items}`; PATCH `{title, public}` |
| `/api/playlists/for-video` | GET | done | `[{id, title, contains}]` for the Add to playlist menu |
| `/api/playlists` | GET POST | done | POST `{title, videoId?}` |
| `/api/prayer/[id]/pray` | POST | done | Members; a number, never a list of names; twice is not two |
| `/api/prayer/[id]` | DELETE | done | The writer's, and the moderator's; anybody else gets 404 |
| `/api/prayer` | GET POST | done | Every read goes through `Prayer::visibleTo`; asking is open to visitors, with a honeypot and a per-address and per-account limit |
| `/api/profile/calendar` | POST DELETE | done | Makes, replaces or stops the member's calendar link |
| `/api/profile/devices/[id]` | DELETE | done | Takes effect on the set's next request, not whenever a session lapses |
| `/api/profile/devices` | GET | done | |
| `/api/profile/export` | GET | done | Every member-keyed table, scoped queries, assertExportSafe, 2/min from the audit log |
| `/api/profile` | PATCH DELETE | done | PATCH: fields owned by active plugins only; DELETE `{confirm: email}`, never the last admin |
| `/api/push/subscribe` | POST | done | App\Modules\Push: push services only, eight per member, 409 until Web Push is set up |
| `/api/push/unsubscribe` | POST | done | |
| `/api/ratings` | GET POST | done | plugins/ratings: `?seriesId`/`?videoId` → `{average, count, mine}`; POST `{…, value: 1–5 or null}` (members) |
| `/api/reactions` | GET POST | done | plugins/likes-dislikes: → `{likes, dislikes, mine}`; POST `{…, type: LIKE, DISLIKE or null}` (members) |
| `/api/reading/marks/[id]` | PATCH DELETE | done | plugins/book-reader; a mark is named by its own id and the book comes from the mark, so only its owner can reach one |
| `/api/reading/marks` | GET POST | done | plugins/book-reader; `?fileId=` to read, `fileId` in the body to add. Private to whoever made them |
| `/api/reading/progress` | POST | done | plugins/book-reader; one row per member per book, the percent clamped rather than stored as it came |
| `/api/rota` | POST DELETE | done | One member's own: an answer, a cover request, taking a slot that is going begging, and blockouts (POST adds, DELETE removes) |
| `/api/schedules/[id]/events` | GET | done | By id or by slug |
| `/api/schedules` | GET | done | |
| `/api/share-links/[id]` | PATCH DELETE | done | PATCH note or revoked; DELETE revokes |
| `/api/share-links` | GET POST | done | 20 new links an hour; private links email and inbox their recipients |
| `/api/share-links/unlock` | POST | done | 10 wrong guesses in 15 minutes lock the link; also 30 tries per address per 15 minutes |
| `/api/subscriptions` | POST PATCH | done | POST `{seriesId|categoryId}` toggles → `{following}`; PATCH `{…, muted}` → `{muted}` |
| `/api/sync/snapshot` | GET | done | Full or delta; signed out it is always full and nameless, and never cached by a shared cache |
| `/api/tv/approve` | POST | done | A member's yes or no; conditional on the pairing still being pending |
| `/api/tv/feed.json` | GET | done | Roku Direct Publisher. Public content only, cached an hour both sides |
| `/api/tv/feed.xml` | GET | done | The same catalogue as MRSS, leaving out exactly what the JSON leaves out |
| `/api/tv/lookup` | POST | done | What is behind a code, for the approval screen. Members only, rate-limited |
| `/api/tv/pair` | POST | done | A code for the screen and the secret that redeems it, stored hashed |
| `/api/tv/poll` | POST | done | Claiming is a conditional update, so concurrent polls cannot both mint a token |
| `/api/v1/analytics` | GET | done | Counts only — videos, series, files, members. `analytics:read` |
| `/api/v1/calendar-events` | GET | done | `schedules:read`; a personal scope, so it answers for the key's own member |
| `/api/v1/categories` | GET | done | `content:read` |
| `/api/v1/events/[id]/registrations` | GET | done | `events:registrations`, its own scope: this is the one endpoint that names people |
| `/api/v1/events` | GET | done | `events:read`; counts of who is registered, never the names |
| `/api/v1/files` | GET | done | `content:read`; `?addedSince=` for an incremental pull |
| `/api/v1/groups` | GET | done | `groups:read`. Never an address and never who is in one — `memberCount` only, at any scope |
| `/api/v1/me` | GET | done | The calling key describes itself: name, prefix, scopes, expiry, rate limit. No scope needed |
| `/api/v1` | GET | done | Self-describing index, and the one v1 route that needs no key |
| `/api/v1/schedules` | GET | done | `schedules:read` |
| `/api/v1/series` | GET | done | `content:read` |
| `/api/v1/videos` | GET | done | `content:read`; drafts and members-only included, each flagged |
| `/api/videos/outline` | PUT | done | plugins/sermon-notes: `{videoId, outlineVersion, answers}`; 409 `outline_changed` against an older sheet |
| `/api/view-events` | POST | done | 30-minute cookie (mt_views) plus the HMAC address throttle; counts only what the caller may open |
| `/api/watch-later` | POST | done | `{categoryId|seriesId|videoId}` toggles → `{saved}` |
| `/api/watch-progress/mark-watched` | POST | done | The one way to clear a completion |
| `/api/watch-progress` | POST | done | Only ever sets completed; never clears it |
| `/auth/guest` | GET | partial | 404s unless the switch is open and the primary provider can build a guest URL (Auth0, step 3) |
| `/events/[slug]/event.ics` | GET | done | A members-only event refuses this outright: a calendar application has nobody to check |
| `/events/calendar.ics` | GET | done | The public feed; member-only events are absent |
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
| BookHymn | `book_hymns` | done | Pages stored as PDF pages; the printed number derived at the edge |
| BookPage | `book_pages` | done | Written a few pages at a time, so an hour-long OCR run is resumable |
| BookHymnDetail | `book_hymn_details` | todo | |
| FileFavorite | `file_favorites` | todo | |
| ServicePlan | `service_plans` | done | plugins/service-plans |
| ServiceTeam | `service_teams` | done | |
| ServiceTeamMember | `service_team_members` | done | |
| ServiceAssignment | `service_assignments` | done | Cover is a flag on the slot, and `covered_for_id` keeps who had it |
| ServiceBlockout | `service_blockouts` | done | Inclusive at both ends |
| ServicePlanItem | `service_plan_items` | done | Replaced as a whole when the order is saved |
| Comment | `comments` | done | the byline is the display name (Profiles on) or sign-in name, never an address |
| CommentReport | `comment_reports` | done | |
| WatchProgress | `watch_progresses` | todo | |
| FileAsset | `file_assets` | todo | |
| ReadingProgress | `reading_progresses` | done | `location` is opaque: only the engine that wrote one parses it |
| ReadingMark | `reading_marks` | done | Per member and private to them; a highlight with nothing selected is saved as a bookmark |
| ApiKey | `api_keys` | done | Only the SHA-256 hash and a 16-character prefix are stored; `window_started_at`/`window_count` carry the per-key limit |
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
| LiveStream | `live_streams` | done | plugins/live-streaming |
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
| Schedule | `schedules` | done | plugins/schedules |
| ScheduleSource | `schedule_sources` | done | One per schedule; the key itself is a setting, not a column |
| Person | `people` | done | The normalized name is the unique key, so two spellings are one person |
| PersonAlias | `person_aliases` | done | What a merge or a rename leaves behind, so the next import resolves it |
| CalendarEvent | `calendar_events` | done | `origin` says whether a sheet or somebody here put it there |
| CalendarEventPerson | `calendar_event_people` | done | |
| Event | `events` | done | plugins/events |
| EventSeries | `event_series` | done | Generated dates are ordinary events; `SetNull` on stopping, never a cascade |
| EventRegistration | `event_registrations` | done | Cancelling keeps the row, so signing up again reuses it |
| Form | `forms` | done | plugins/forms |
| FormField | `form_fields` | done | Retired rather than deleted once it has answers |
| FormSubmission | `form_submissions` | done | `handled_at`/`handled_by`, so follow-up isn't done twice or not at all |
| FormAnswer | `form_answers` | done | Every answer is text; a multi-choice is its chosen options joined by a newline |
| PrayerRequest | `prayer_requests` | done | plugins/prayer |
| PrayerIntercession | `prayer_intercessions` | done | Unique per request and member |
| SmallGroup | `small_groups` | done | plugins/groups |
| SmallGroupMember | `small_group_members` | done | Leaving keeps the row (DECLINED), so asking again is a conversation |
| GroupMessage | `group_messages` | done | Taken down = hidden, dropped in the query and in the filter |
| DiscussionGuide | `discussion_guides` | done | plugins/groups |
| DiscussionGuideItem | `discussion_guide_items` | done | A LEADER_NOTE has no field on a member's shape to be printed from |
| SmallGroupMeeting | `small_group_meetings` | done | One per group per day under the unique index |
| GroupAttendance | `group_attendances` | done | Apologies is a status of its own |
| Broadcast | `broadcasts` | done | plugins/broadcasts |
| BroadcastRecipient | `broadcast_recipients` | done | One row per person per channel with the address copied in; the port adds the provider's message id and the delivery receipt |
| VideoFeed | `video_feeds` | done | |
| LiveChatMessage | `live_chat_messages` | done | Taken down = `hidden`, never deleted |
| LiveChatMute | `live_chat_mutes` | done | Per stream: muted for the evening, not for ever |
| TvDevice | `tv_devices` | done | plugins/tv; `token_hash` is the set's own long-lived credential, which does not expire on its own |

## Test files (Appendix D) — 73

Each becomes a PHPUnit test class with the original case names.

| Original | Status | Notes |
|---|---|---|
| `lib/active-path.test.ts` | done | tests/Unit/Admin/ActivePathTest.php |
| `lib/admin-nav.test.ts` | done | tests/Unit/Admin/AdminNavTest.php |
| `lib/api-keys.test.ts` | done | tests/Unit/Api/KeysTest.php (every case) |
| `lib/api-v1.test.ts` | done | tests/Unit/Api/V1Test.php; over HTTP in tests/Integration/ReadApiTest.php |
| `lib/attendance.test.ts` | done | tests/Unit/Plugins/AttendanceTest.php (every case) |
| `lib/authorization.test.ts` | done | tests/Unit/Access/AuthorizationTest.php; the guest-login cases in tests/Integration/GuestLoginTest.php |
| `lib/book-contents.test.ts` | done | tests/Unit/Support/BookContentsTest.php (every case) |
| `lib/branding.test.ts` | done | tests/Unit/Branding/BrandingTest.php |
| `lib/broadcast.test.ts` | done | tests/Unit/Plugins/BroadcastTest.php (every case) |
| `lib/bunny.test.ts` | done | tests/Unit/Video/BunnyTest.php |
| `lib/client-bundle.test.ts` | todo | |
| `lib/content-language.test.ts` | todo | |
| `lib/content.test.ts` | done | tests/Unit/Library/ContentTest.php; the DB-backed checks in tests/Integration/ContentAccessTest.php |
| `lib/cover.test.ts` | done | tests/Unit/Plugins/CoverTest.php (every case) and tests/Integration/ServicePlansTest.php |
| `lib/cron-guard.test.ts` | done | tests/Unit/Jobs/CronGuardTest.php (no development exception: see Deviations) |
| `lib/cron.test.ts` | todo | |
| `lib/cross-site.test.ts` | todo | |
| `lib/data-export.test.ts` | done | tests/Unit/Profile/DataExportTest.php (+ a schema completeness check) and tests/Integration/DataExportTest.php |
| `lib/device-settings.test.ts` | done | tests/js/device-settings.test.mjs |
| `lib/directory.test.ts` | done | tests/Unit/Plugins/DirectoryTest.php (every case) and tests/Integration/DiscoveryPluginsTest.php |
| `lib/download-source.test.ts` | done | tests/Unit/Video/DownloadSourceTest.php |
| `lib/downloads.test.ts` | done | tests/Unit/DownloadsTest.php |
| `lib/event-series.test.ts` | done | tests/Unit/Plugins/EventSeriesTest.php (every case) |
| `lib/events.test.ts` | done | tests/Unit/Plugins/EventsTest.php (every case) and tests/Integration/EventsTest.php |
| `lib/filename.test.ts` | done | tests/Unit/Support/SupportTest.php |
| `lib/forms.test.ts` | done | tests/Unit/Plugins/FormsTest.php (every case) and tests/Integration/FormsTest.php |
| `lib/group-messages.test.ts` | done | tests/Unit/Plugins/ThreadTest.php (every case) |
| `lib/groups.test.ts` | done | tests/Unit/Plugins/GroupsTest.php (every case) and tests/Integration/GroupsTest.php |
| `lib/guides.test.ts` | done | tests/Unit/Plugins/GuidesTest.php (every case) |
| `lib/hymnal.test.ts` | done | tests/Unit/Support/HymnalTest.php (every case) |
| `lib/i18n/i18n.test.ts` | done | tests/Unit/I18n/I18nTest.php |
| `lib/ics.test.ts` | done | tests/Unit/Support/IcsTest.php (every case) |
| `lib/identity-linking.test.ts` | done | tests/Unit/Access/IdentityLinkingTest.php |
| `lib/live-chat.test.ts` | done | tests/Unit/Plugins/LiveChatTest.php (every case) and tests/Integration/LiveTest.php |
| `lib/names.test.ts` | done | tests/Unit/Plugins/SchedulesNamesTest.php (every case) |
| `lib/nav-tabs.test.ts` | done | tests/js/nav-tabs.test.mjs |
| `lib/offline-calendar.test.ts` | done | tests/js/offline-calendar.test.mjs (every case), against public/assets/js/offline-calendar.js |
| `lib/offline-shell.test.ts` | todo | |
| `lib/outline.test.ts` | done | tests/Unit/Plugins/OutlineTest.php (every case) and tests/Integration/SermonNotesTest.php |
| `lib/page-offset.test.ts` | done | tests/Unit/Support/PageOffsetTest.php (every case) |
| `lib/permissions.test.ts` | done | tests/Unit/Access/PermissionsTest.php |
| `lib/plugins.test.ts` | done | tests/Unit/Plugins/PluginStatesTest.php |
| `lib/podcast-mirror.test.ts` | done | tests/Unit/PodcastMirrorTest.php |
| `lib/prayer.test.ts` | done | tests/Unit/Plugins/PrayerTest.php (every case) and tests/Integration/PrayerTest.php |
| `lib/public-url.test.ts` | done | tests/Unit/Core/PublicUrlTest.php |
| `lib/push-endpoint.test.ts` | done | tests/Unit/Push/PushEndpointTest.php |
| `lib/reader-cache.test.ts` | todo | |
| `lib/reader.test.ts` | done | tests/Unit/Support/ReaderTest.php (every case) |
| `lib/recurrence.test.ts` | done | tests/Unit/Plugins/RecurrenceTest.php (every case) |
| `lib/reorder.test.ts` | done | tests/Unit/Support/SupportTest.php |
| `lib/rota.test.ts` | done | tests/Unit/Plugins/ServicesTest.php (every case) |
| `lib/schedules/duplicates.test.ts` | done | tests/Unit/Plugins/SchedulesLogicTest.php |
| `lib/schedules/logic.test.ts` | done | tests/Unit/Plugins/SchedulesLogicTest.php (every case) |
| `lib/schedules/visibility.test.ts` | done | tests/Unit/Plugins/SchedulesLogicTest.php, and proved over HTTP in tests/Integration/SchedulesTest.php |
| `lib/services.test.ts` | done | tests/Unit/Plugins/ServicesTest.php (every case) and tests/Integration/ServicePlansTest.php |
| `lib/share-links.test.ts` | done | tests/Unit/Library/ShareLinksTest.php |
| `lib/share-password.test.ts` | done | tests/Unit/SharePasswordTest.php, plus a hash made by Node |
| `lib/sheets/dates.test.ts` | done | tests/Unit/Plugins/SheetParseTest.php |
| `lib/sheets/parse.test.ts` | done | tests/Unit/Plugins/SheetParseTest.php (both layouts, skip-and-report) |
| `lib/slug.test.ts` | done | tests/Unit/Support/SupportTest.php |
| `lib/sms.test.ts` | done | tests/Unit/Support/SmsTest.php and tests/js/sms.test.mjs (every case, both halves) |
| `lib/toc-nav.test.ts` | done | tests/Unit/Support/BookContentsTest.php (every case) |
| `lib/transcribe-worker.test.ts` | done | tests/Integration/TranscriptionTest.php (claim, DONE/FAILED, stale sweep, deadline) |
| `lib/transcribe.test.ts` | done | tests/Integration/TranscriptionTest.php (multipart shape, model/language fields, size limit, the settings test) |
| `lib/tv-feed.test.ts` | done | tests/Unit/Plugins/TvFeedTest.php (every case) |
| `lib/tv-nav.test.ts` | done | tests/js/tv-nav.test.mjs (every case), against plugins/tv/assets/tv-nav.js |
| `lib/tv-pairing.test.ts` | done | tests/Unit/Plugins/TvPairingTest.php (every case) |
| `lib/upload-types.test.ts` | done | tests/Unit/Files/UploadTypesTest.php |
| `lib/validation/schemas.test.ts` | todo | |
| `lib/verses.test.ts` | done | tests/Unit/Support/HymnalTest.php (every case) |
| `lib/video-feed-sync.test.ts` | done | tests/Unit/Video/VideoFeedSyncTest.php, plus the fetchers against recorded answers |
| `lib/video-source.test.ts` | done | tests/Unit/Library/VideoSourceTest.php |
| `lib/view-key.test.ts` | done | tests/Unit/Library/ViewKeyTest.php |

## Security review (route by route)

Done as `tests/Integration/RouteAuditTest.php` rather than as a table here,
because a table of three hundred routes is out of date the week after it is
written and a test is not. It boots the whole site — core modules and the
bundled plugins — reads every route back out of the router with the
middleware it was given, and asserts:

1. **Every `/admin` and `/api/admin` route is behind a capability.** The five
   whose check is inside the handler are listed in the test with the reason
   (the trash needs *any one of* four content capabilities, which
   `Middleware::can` cannot express).
2. **Every write open to a stranger is one we wrote down.** Twenty-six of
   them, each with what stands in for a sign-in — a rate limit, a single-use
   token, a provider's signature, an ownership check. A new one fails the
   test until somebody explains it.
3. **Every write that skips the CSRF token is one we wrote down**, and every
   prefix in `Csrf::EXEMPT` has a reason against it — checked both ways, so
   neither a new exemption nor a stale one passes.
4. **`/api/v1` is read-only**, which is what makes its CSRF exemption free.

The other requirements of the brief are enforced where they cannot be
forgotten rather than reviewed per route: output escaping by
`tools/ci/check-templates.php` over every template; the validation allowlist
by `Validator::check`, which drops what it was not told about; the headers by
one place in `App`; SSRF by `Http::fetchUntrusted` and the resolver behind it.

| What | Where it is proved |
|---|---|
| Capability on every admin route | `RouteAuditTest::test_2` |
| Public writes all accounted for | `RouteAuditTest::test_3` |
| CSRF exemptions all accounted for | `RouteAuditTest::test_4`, `test_6` |
| The read API cannot write | `RouteAuditTest::test_5` |
| Every template output escaped | `tools/ci/check-templates.php` (CI) |
| Every provider documented | `tools/ci/check-docs.php` (CI) |
| No process functions in shipped code | `tools/ci/check-banned.php` (CI) |

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
- **The importer mints a new object name for a file it pulls out of Bunny.**
  Files were uploaded there under their own names, and "Hymnal Scan
  (2019).pdf" is not a name a local store will take; nothing outside the row
  reads that column, so the copy gets the port's own `files/<id>.<ext>`.
- **Four hooks the brief named do not exist.** `content.can_view`,
  `admin.menu`, `settings.register` and `plugin.category_override` were each
  a worse version of something already available (`Library\Viewer`,
  `nav.sections`, a plugin's own route, `$context['plugins'][…]`); PLUGINS.md
  says so per hook rather than leaving them looking unfinished.
- **v1 cursors are base64url, not the raw sort value.** The cursor is
  `"<sortValue>|<id>"` encoded, because the sort value is a datetime with a
  space in it and a bare one does not survive a query string.
- **A refused v1 request still spends a rate-limit token.** An expired key or
  a missing scope costs the same lookup as a good one, so counting only
  successes would leave the cheapest way to hammer the database uncounted.
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
- **Chapters, transcripts, trending, recommendations, share links and downloads moved into `plugins/`** once the page hooks existed: the chapter, transcript, share and download panels are page.video/series.panels, and the two homepage rows are filled through home.row by Recommendations and View counts. What stays in the library is the content and the decisions: chapters edited on the video page, the transcript and its part in search, `Library\Sharing` (opening a link is part of who may watch; revoking and the lists at /profile/shared-links and /admin/share-links never depend on the plugin, so switching it off traps nobody) and `Library\Downloads` (the four gates, decided again when the file is asked for). The plugins gate making new share links and offering the Download button.
- **A display name counts only while the Profiles plugin is on**; switched off, members are shown by their sign-in name again and `/directory` is gone. The account fields stay stored either way.
- **Bundled plugins add no data-export sections of their own**: the core's export already holds every member table (favorites, ratings, reactions, watch history…), whether or not the plugin that writes them is on, since the data outlives the switch.
- **The webhook payload is the port's**: `{event, id, title, slug, url, memberOnly, publishedAt}` (the brief names the event and the signature, not the body). Secrets are stored encrypted under app_key rather than in plain text; a delivery that fails is logged, not retried, as before.
- **Web Push is native, not minishlink/web-push**: `App\Support\WebPush` does VAPID (ES256, through the JWT code the sign-in providers use) and RFC 8291 aes128gcm encryption with OpenSSL's P-256 key agreement, `hash_hkdf` and AES-128-GCM — so neither bcmath/gmp nor a vendored dependency tree is needed. A unit test decrypts as a browser would. Keys keep the `web-push generate-vapid-keys` format, so the old site's pair carries over. The sender and `/api/push/*` are core (Live streaming and Broadcasts push too); the Web Push keys are an integration under Admin → Services, where "Generate" saves a new pair at once rather than carrying a secret back through the form.
- **Publishing notifies after the response** (where the host has `fastcgi_finish_request`), checking each member's access to the video — a viewer restriction keeps a video out of the inboxes of people who can't watch it. A daily-digest notification for a member with no browser signed up is dropped rather than queued forever.
- **`/robots.txt`** is served by the app (base-path aware, pointing at the sitemap); the original had none.
- **The view beacon's cookie is `mt_views`**, one cookie listing recently viewed ids with their times, since Appendix H names no cookie for it.
- **Uploaded images (logo, artwork) are served at `/media/<kind>/<random>.<ext>` from `storage/media/`**, through the app, with a year-long immutable cache and a sandbox CSP. `storage/` is the only place the site writes, so nothing lands in `public/`.
- **OneDrive uploads go to a Business or SharePoint drive only.** The brief offers upload "for either"; personal OneDrive has no app-only access, and a delegated refresh token there rotates and lapses, which would need the site to rewrite its own saved settings from a background request. Personal OneDrive links still resolve and play; uploads use the same Entra app the Business links already need, with Files.ReadWrite.All and a chosen drive.
- **A live stream's chat is moderated by `moderate_comments` held anywhere, and /admin/live needs `manage_plugins`** — the capability the admin menu already declares for it. A stream sits in no category, so a per-category grant has nothing to scope to here; the capability list gains nothing new.
- **The chat's poll answer is the port's**: `{state, slowMode, muted, messages: [{id, author, body, createdAt, mine, canDelete}], removed: [id]}`. `removed` is what makes "a removed message never reappears" true for a tab that was a few seconds behind — it lists the stream's taken-down ids (200 at most) so every open page drops them; no account id is ever in the answer. A take-down sets `hidden` rather than deleting the row, for both an author and a moderator, so the same message can't be re-sent past a moderator by reposting.
- **A mute names its target by one of their messages** (`POST /api/live/[id]/chat/mute {messageId}`), since the chat never carries account ids to the browser. Muting hides everything that person has already written in that stream; lifting it (`{muted: false}`) lets them write again but leaves what was taken down down.
- **A chat message is cleaned before it is stored**: whitespace collapses to single spaces and a run of one character to three, and what is left must be between 1 and 500 characters. That is the shouting a length limit leaves standing; the limit itself is the port's number.
- **A stream with no end time is assumed to run for two hours**, which is what the chat's close (an hour after the end) counts from. The brief asks for "a sensible one rather than for ever" without naming it.
- **`/live` counts down to the next scheduled stream, but the chat belongs only to the stream whose evening it is** — from half an hour before its start until an hour after it ends. A stream three weeks off gets a countdown and a place in "Coming up", not a comment box.
- **An embed or cover address must be https.** An http embed inside an https page is blocked by the browser anyway, and the page adds the embed's origin to `frame-src` for that request only, so the CSP never has to list every streaming host a church might use.
- **Publishing a stream tells every member**, rather than checking access per member as a video does: a `LiveStream` row has no member-only gate and no category, so there is nothing to check it against.
- **A taken-down prayer request is hidden from its writer too**, not only from everybody else: a moderator's decision that re-showed the words to the person who wrote them would invite the same request again, and the row is kept so the decision is a record. The moderator still sees it.
- **Whoever asks for prayer chooses who may see it, a visitor included.** The brief gives the three audiences to whoever writes the request without saying that a visitor is excluded, and "this shouldn't be a wall at all" is a visitor's decision as much as a member's. Members are the default, and nothing is shown to anybody until a moderator has read it.
- **The prayer wall's limits are 5 requests an hour per account and 20 per address.** The brief asks for both; the numbers are the port's. A single address is the looser of the two because a church shares one office network.
- **"Take down" and "delete" are different acts on the wall**: taking one down sets `HIDDEN` and keeps the row (the decision survives, and the same words can't be reposted past it), while delete removes it — the writer's own, or a moderator's for good. Only the second is a delete.
- **iCalendar writing is core (`App\Support\Ics`), not the events plugin's**, because three different features answer "who is this for" with a calendar: what's on, one event, and the member's own diary. The personal feed and its token live in the profile area (`App\Modules\Profile\Calendar`), and plugins fill it through the `calendar.entries` filter, so a rota can add to the same file later without the events plugin knowing.
- **A repeat is described in words by the server**, and the form offers the five shapes rather than an RRULE box; `Series::ruleFromChoices` is the only thing that writes a rule, and it round-trips through the parser before it is stored, so a rule this app can't expand can't be saved.
- **A rule this app can't compute is refused rather than ignored**: `BYSETPOS`, `BYWEEKNO`, `BYYEARDAY`, a `WKST` other than Monday, and combinations that don't mean what they look like (a numbered weekday with `FREQ=WEEKLY`, `BYMONTHDAY` with a weekly rule, `COUNT` and `UNTIL` together). Ignoring a part answers a question nobody asked.
- **An occurrence list is capped at 500 days and gives up after 400 empty periods.** The brief asks for both without naming the numbers; a rule that can never land again (the 30th of February) answers with its start date alone.
- **An event with no stated finish is over at the end of its own day**, which is what `registrationState` counts as "over" — it is not over the minute it starts.
- **A visitor's sign-up is limited to 20 an hour per address, with a honeypot**; a signed-in member's is not, since the account is the limit. Cancelling and signing up again reuse the same row, which is the record that they were coming.
- **`/admin/events/[id]` is the port's own screen** (the original has the page, not the shape): one event's fields and the list for the door, with the CSV beside it.
- **A form question nobody has answered is deleted rather than retired.** The brief's rule protects answers; a question added by mistake five minutes ago has none, and leaving it in the export's columns for ever is the worse outcome. The answer says which happened (`{retired: true|false}`).
- **"Only once" is enforced per account.** A visitor has no account to count against, so a form that may be sent only once still takes a second card from an unsigned-in visitor; the brief's example (a camp application) is a members' form in practice.
- **A form's notify addresses are checked when they are saved**, and a typo is refused there rather than silently failing at the first submission.
- **A group's thread is closed to a site manager who is not in the group**, exactly as the brief says, and the way in is to be put in the group from `/admin/groups/[id]` — which leaves a membership row saying so. The address is the other way round: whoever keeps the list is given it, because an address is an operational fact somebody running the site may need.
- **A leader is `role = LEADER` on an active membership**, never a capability. `manage_events` ("keeps the group list") is treated as leading every group for the address, the requests and the roll, but not for the conversation.
- **A name shown on a group page is never an email address**: the group's own byline rule, the same as comments and the live chat, so dev or imported data whose `name` holds an address shows "A member" instead.
- **Guide items are edited through their own routes** (`POST /api/admin/guides/[id]/items`, `PATCH`/`DELETE` on one) rather than as a nested array on the guide: the same shape as a form's questions, and one save can't silently drop an item somebody else added.
- **`/admin/guides` exists**, as the brief asks (the original has only the API).
- **A plan's order and its rota are saved as wholes** (`items` and `assignments` on the plan's PATCH) rather than row at a time: an order is one thing, and moving a hymn up is the same act as adding one. An ask that is already there keeps the answer somebody gave; one that has gone is withdrawn.
- **A row is named after the song, not the book it is in**: a number inside a book takes its title from the contents where there is one, on the plan and in What we sang.
- **`/books`, `/hymns` and `/present` are the book-reader plugin's**, built here because a running order links straight into them; the in-app reader itself (pdf.js/epub.js, search, highlights, read-aloud, the offline copy, the contents editor and OCR) is still to come, and `/read/[fileId]` meanwhile hands the file to the browser's own viewer through the access-checked content route.
- **Rota names need a sign-in, and the structure does not**: `/services/[id]` gives a signed-out reader the jobs and the teams with nobody's name on them, the same optional-field shape the group address uses.
- **A cover request is a conditional write**: the hand-over updates the row only while it is still open and still held by whoever asked, so two people pressing "I'll take it" in the same second get one winner and the other is told somebody got there first. Being already on that service refuses with the reason said plainly; being away only warns; and the old note does not follow the slot.
- **The schedules import removes a date only inside the stretch the sheet covered.** A successful sync takes off the dates the sheet no longer lists, but only between the first and last date it carried: a schedule whose window has moved on has history behind it that nothing deleted, and an import is not the place to lose it. A failed sync removes nothing at all, and an unchanged fingerprint writes nothing.
- **`/api/people` answers 403, not the usual 401**, signed out. It is not "sign in to continue": the list of who is on the rotas is not a stranger's to ask for, and a `personId` filter on the event and snapshot endpoints is refused for the same reason — "which days is this id on" is "who is this", sideways.
- **`mergeSnapshot` lives in `public/assets/js/offline-calendar.js` and imports nothing**, so it can be tested in Node where there is no `window`; what the module needs of `MT` it reads lazily off the page. The two rules a delta cannot state are the device's: a disabled schedule takes its dates with it, and a day that has fallen out behind the window is dropped.
- **A signed-out snapshot is always full.** A delta would let a copy saved on a shared laptop while somebody was signed in keep those names for ever, because no row changed; asking for the lot replaces them with a nameless copy on the next update.
- **A person's spelling is changed in place and the old one kept as an alias**, the same as after a merge, so the next import resolves it rather than making the duplicate again.
- **Renaming a schedule does not re-slug it.** The slug is made once, from the first name, because `/api/schedules/[id]` answers to either and a changed slug would break a link somebody kept.
- **A television's durable credential is the pairing, not a session.** A session here lapses after a month, and a set in a hall used on Sundays would otherwise need re-pairing for no reason anybody in the room could see; so `tv_devices.token_hash` is a year-long `mt_tv` cookie, and the session is a cache of it, renewed from the pairing on each television request. That is also what makes signing a set out take effect at once: every `/tv` request looks the pairing up rather than trusting the session, and a cookie whose pairing has gone takes the session with it.
- **A poll that finds the pairing already claimed answers GONE, not READY.** Claiming is `UPDATE … WHERE status = APPROVED AND token_hash IS NULL`, so of two polls arriving together exactly one changes a row and exactly one token exists; the loser is told the pairing is gone rather than handed a second one. Proved with four concurrent polls over HTTP.
- **The user code's alphabet has no digits and no vowels** (`BCFGHJKLMNPRSTVWXZ`): no digit can be misread as a letter across a room, no code is ever a word, and `normalizeUserCode` forgives a lookalike typed for a character the screen never showed (1/I → L, 5 → S, 8 → B, 6 → G, 2 → Z, U → V). 18^6 is thirty-four million against a code that lives fifteen minutes behind a rate-limited lookup.
- **`/tv` never prints an email address**, the same rule the comments and group pages keep — it is the most public screen in the building, so a member whose name is missing or is an address is "a member".
- **The television feed needs a stream, not an embed.** A set-top box has no browser to put an iframe in, so `streamUrl` is Bunny's HLS playlist (`Bunny::hlsUrl`, signed for a day where token authentication is on) or a file-based provider's own URL; a video available only as an embed falls back to its source's own page, and one with neither drops out of the feed rather than becoming a tile with nothing behind it.
- **Broadcasts are a bundled plugin, though the original's 31-feature list has no such slug.** The brief's own area table calls it one, and a church that never sends a broadcast should be able to switch the screen off; a plugin on disk that the feature registry does not name is seeded from its own header, so nothing else had to change.
- **A channel with nothing behind it marks its rows SKIPPED, not FAILED.** An install with no email provider would otherwise show four hundred red rows, each blaming an address that is perfectly good — and the enum's own word for "there was simply no way to reach them on this channel" is skipped. A provider that refuses a particular message is still a failure, with its own sentence beside the name.
- **A claim comes before the send.** Each recipient row is moved from PENDING to SENT by a conditional update before the provider is called, so two callers racing (the browser loop and the cron backstop) cannot both send to the same person; a refusal then moves it to FAILED with the reason.
- **An event audience is texted only on numbers members typed in themselves.** The number on a public sign-up form is copied nowhere near the text channel: it is not consent, and treating it as such is the mistake this rule exists to prevent.
- **`/api/people`-style secrets for callbacks**: an SMS provider with no signature scheme (ClickSend, Textlocal, BulkSMS, Sinch, the JSON webhook) is given a per-install secret in its callback address, derived with the app key rather than stored, and compared in constant time.
- **The composer's cost and the server's cost are the same code twice**: `app/Support/Sms.php` and `public/assets/js/sms.js`, tested against one case list, so the number on the screen is the number that goes out.
- **The reader libraries are committed under `public/vendor-js/`**, beside hls.js and tus, rather than copied out of `node_modules` at install time: this app installs by unzipping onto shared hosting, where there is no npm to copy from. `public/offline.html`'s four viewer constants were repointed at those addresses, which is the same address the app caches them under — the whole point of the path being fixed.
- **pdf.js's character maps are left out** (a megabyte and a half, and only for CJK encodings), and only tesseract's LSTM cores are vendored rather than the legacy engine as well. The English training data is vendored too, because a library that fetches it from a CDN on first use is exactly what the brief's "not a public CDN" is about.
- **The contents box is parsed on the server** (`App\Support\BookContents`), not in the browser as `lib/book-contents.ts` was: the rows it produces go straight into the database, and a second implementation in JavaScript would be a second set of rules for what a page is. The editor gets the problems back with the line numbers the typist sees.
- **Reading position is saved a couple of seconds after somebody stops turning pages**, not on every page, and once more on `pagehide` — a hymnal is flicked through, and one write per page would be a write per second.
- **A highlight with nothing selected is stored as a bookmark**, and says so. In an EPUB the selection lives in a frame the reader cannot read; saving an empty excerpt and calling it a highlight would be pretending otherwise.
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
- 2026-09-25 — step 5 begins: the Live streaming plugin (plugins/live-streaming).
  /live, /admin/live and /api/live/*, the "Live now" banner and nav entry
  through `render.page_top` and `nav.sections`, /live in the sitemap, and a
  polling chat (no socket: nothing here is long-lived enough to hold one)
  that opens half an hour early, closes an hour after, collapses shouting,
  counts slow mode from each person's own last message and never carries a
  taken-down message or an account id back. 17 unit tests for the rules,
  5 integration tests through a real server, and a two-tab Chromium run:
  one tab's message reaching the other by polling, and a moderator's
  take-down removing it from the tab that was behind.
- 2026-09-25 — the prayer wall (plugins/prayer): /prayer, /admin/prayer and
  /api/prayer*, with `Prayer::canSee`/`bylineFor`/`present`/`visibleTo`/
  `canPrayFor`/`canDelete` as the one place each decision is made. Nothing
  on the wall until a moderator reads it, anonymous meaning anonymous on
  every screen including the queue, "I prayed for this" as a number, and
  the request's words kept out of the audit log. 19 unit tests (the
  original's case list) and 6 integration tests, plus a Chromium run of a
  visitor asking, a moderator letting it through and a member praying.
- 2026-09-26 — events (plugins/events): /events, /events/[slug], /admin/events
  and /admin/events/[id], sign-up without an account under a row lock on the
  event (a four-way simultaneous burst through curl_multi gets one yes and
  three places on the list), guests counted as places, a waiting list that
  moves on a drop-out or a raised capacity and stops at the first party that
  doesn't fit, repeats from real RRULEs with the five shapes a diary
  contains, the daily extend-events job, and the three calendar feeds —
  what's on, one event, and the member's own diary behind a token
  (App\Support\Ics and App\Modules\Profile\Calendar, both core). 103 new
  unit tests (recurrence, events, series, ics) and 6 integration tests.
- 2026-09-26 — forms (plugins/forms): /forms, /forms/[slug], /admin/forms and
  /admin/forms/[id], with the questions as rows. The server has the last word
  on a valid answer (a crafted fourth answer to a three-way question is
  refused, uninvited multi-choice options are dropped, an unticked lone box
  is an answer rather than silence), renaming a question keeps its answers,
  retiring one keeps its column after the live ones in the table and the CSV,
  and responses are marked dealt with by name. 14 unit tests (the original's
  case list) and 4 integration tests, plus a Chromium run of a visitor
  filling one in and the office marking it dealt with.
- 2026-09-26 — small groups (plugins/groups): /groups, /groups/[slug],
  /profile/groups, /guides, /admin/groups, /admin/groups/[id] and
  /admin/guides. presentGroup is the only thing that decides whether the
  address travels (absent, never null); asking is a request the leader
  answers on the group's own page without a capability; a full group takes
  names in order and a freed place puts the longest-waiting ask in front of
  the leader; the conversation is closed to anybody not actually in the
  group, hidden messages are dropped in the query and in the filter, and
  notifications carry the first line only; the roll reaches a member as a
  list of at most one row — their own — and leader notes have no field on a
  member's shape. 92 new unit tests (groups, attendance, guides, thread —
  the original's four case lists) and 8 integration tests, plus a Chromium
  run of a stranger asking and a leader answering.
- 2026-09-26 — service plans and the rota (plugins/service-plans), with the
  hymn, book and presenter pages they link into (plugins/book-reader).
  Where a row opens, what its number means and whether it can go on a
  screen are decided in Services; a plan outlives the library it points at,
  so every row is resolved against the file as it stands now. The rota
  holds asks, answers, cover (a flag on the slot, keeping who had it) and
  blockouts inclusive at both ends, reaches the member's own calendar feed,
  and writes a declined date as CANCELLED rather than leaving it out. What
  we sang counts each song and its services for a licence return, with a
  CSV. 26 unit tests (the original's services and rota case lists) and 7
  integration tests.

- 2026-09-26 — schedules and Google Sheets: `/calendar` with the chip row,
  Only mine and keep-on-device; `/admin/schedules`, `/admin/schedules/[id]`
  with Test connection, and `/admin/people` with merging; the sheet client
  (RS256 service-account assertion), the two layouts, forgiving dates, the
  import that deletes nothing on failure and writes nothing when unchanged;
  the offline snapshot and `mergeSnapshot`; the 05:30 sync and the morning
  reminder. The rule under test throughout is that the dates are public and
  the names need a sign-in: proved over HTTP for the page, the event
  endpoints, `/api/people` and the snapshot, and in Chromium for the saved
  copy, which holds no names when it was saved signed out. 886 unit, 107
  integration, 37 browser-module tests.

- 2026-09-26 — television: `/tv` and `/tv/[slug]` over the app chrome, walked
  with four arrows; `/link` and the approval a member is asked by name;
  `/profile/devices`; the Roku Direct Publisher and MRSS catalogues; and the
  hourly prune of pairings nobody completed. Verified in two browsers at
  once: a set showing a code, a phone approving it, the set letting itself
  in seconds later, and signing it out from the phone putting the code back
  on the screen — plus the four arrows stopping at the end of a row, OK
  opening a tile and Back leaving it. Four concurrent polls yield exactly
  one token. 923 unit, 115 integration, 50 browser-module tests.

- 2026-09-26 — broadcasts and texting: the SMS slot with eleven providers
  (each with an account check and a test that ends with a code arriving on
  the administrator's own phone), the signed status and inbound callbacks
  with stop words in both languages, and the broadcasts plugin —
  planDelivery's three consent rules, the frozen list, the browser's batch
  loop with the cron job as backstop, and the failures listed afterwards
  with the reason the provider gave. Verified in a browser against a real
  SMTP conversation: the reach line and the skip reasons as the composer is
  filled in, and a send that reports what it sent. 973 unit, 128
  integration, 62 browser-module tests.

- 2026-09-26 — the book and hymnal reader: pdf.js and epub.js vendored and
  put behind one handle, so nothing above them knows a page number from a
  CFI; contents, in-book search, marks, read-aloud, and a copy kept on the
  device under the offline shell's own keys. Plus the three indexing passes
  that run in the admin's browser because that is where pdf.js is — the
  book's own bookmarks, the contents box typed by hand, and every page's
  text from the text layer or by OCR through the vendored tesseract. Six
  pure modules with the brief's case lists (page offsets, contents, toc
  navigation, reader helpers, hymnal ordering, verses). Verified in
  Chromium against a real PDF: the outline pass read its bookmarks, the
  reader drew and turned its pages, zoomed, jumped from the contents,
  searched from the indexed text with the hymn each hit falls inside,
  saved a highlight, reopened where it left off, and saved the book and
  pdf.js into Cache Storage under the paths the offline shell reads.
  1067 unit, 137 integration, 62 browser-module tests.

- 2026-09-26 — the read API (`/api/v1`) and API keys. Eleven read-only
  endpoints behind bearer keys: a key is generated once, shown once, and
  stored only as a SHA-256 hash beside a 16-character prefix, so a lost key
  is replaced rather than recovered. Six scopes with no hierarchy, two of
  them (`events:registrations`, `schedules:read`) marked personal in the
  admin screen because they answer with people. Paging is keyset over
  `(updated_at, id)`, so a row edited mid-read moves to the end of the run
  instead of being skipped, and the cursor is base64url because the sort
  value is a datetime with a space in it. Every payload goes through
  `DataExport::assertExportSafe` before it is sent — which caught the
  envelope's own `data` key and an `auth` field in the self-description
  during the build, both renamed rather than exempted. The per-key limit is
  one atomic UPDATE that rolls the window and counts in the same statement;
  a refusal counts too, since it cost the same lookup. Proved over HTTP
  with a real key: cursor paging across pages, `/api/v1/groups` returning a
  count and never an address at any scope, and the limit rolling over
  rather than locking a key out. 1099 unit, 147 integration, 62
  browser-module tests.

- 2026-09-26 — the data import from the Next.js deployment, both halves.
  `tools/export-from-nextjs/export.mjs` runs on a laptop against the old
  database's direct connection string and writes a zip of newline-delimited
  JSON with a manifest of the counts; it reads through a server-side cursor
  so a large table need not fit in memory, takes timestamps as text so the
  laptop's own time zone cannot move every service time on the way through,
  and depends on nothing but `pg` — the zip writer is 60 lines here rather
  than a toolchain somebody has to install before they can leave a platform.
  The importer on /admin/tools unpacks, loads and relinks, each phase
  resumable. Names convert by rule, with a short list of the three places
  the port renamed something and a test that reads the migration and fails
  if one of them stops naming a real column. Load order comes from the
  database's own foreign keys, so a new key cannot make a hand-kept list
  quietly wrong, and the three columns that point within their own table are
  filled after everything is in. Files left behind in Bunny Storage are
  pulled across a batch per request, each repointed only once its bytes are
  here. Verified in Chromium against a hand-built export: a category written
  before its parent found it, tags were deduplicated and lower-cased into
  their index, a Bunny video kept its guid as the id at its provider, a
  timestamp came through in UTC, the row too long for its column was
  reported with the database's own words while the rest went in, and the
  paired television was left behind with the reason. 1114 unit, 160
  integration, 62 browser-module tests.

- 2026-09-26 — step 6 begins with the documents. SERVICES.md was written at
  step 2 and said "planned (step 3)" against things that have been working
  for weeks; it is rewritten with a row per provider in all five slots — 39
  of them — carrying each provider's own `limits()` sentence, and
  `tools/ci/check-docs.php` now fails the build when a provider has no row or
  a row no longer matches the code, so it cannot go stale the same way twice.
  PLUGINS.md's hook table gained the nine it had grown without (sitemap,
  calendar, search sources, the saved/published/trashed family, video
  progress) and lost the promise of four that were never built, each replaced
  by a line saying what to use instead. UPGRADING.md is new: what a version
  number promises, what a migration may do, what is covered for plugin and
  theme authors, and where the rollback stops being a button and becomes the
  backup. Writing it turned up a real gap — `Requires App:` in a plugin
  header was parsed and never checked — now enforced beside the PHP one, with
  a fixture whose boot() throws to prove it is never reached.

- 2026-09-26 — the security walk, as `tests/Integration/RouteAuditTest.php`
  rather than a table. It boots the core and the bundled plugins, reads every
  route back out of the router with the middleware it was given (a closure is
  identified by the line of Middleware.php that made it), and asserts that
  every admin route is behind a capability, that every write a stranger may
  make is one we wrote down with what stands in for a sign-in, that every
  write skipping the CSRF token is written down — and the other way too, that
  every prefix in `Csrf::EXEMPT` has a reason against it — and that /api/v1
  cannot write, which is what makes its exemption free. Writing it meant
  reading twenty-six public endpoints and the five whose check is inside the
  handler; all of them held, and the trash was the interesting one: its
  capability is *any one of* four, which `Middleware::can` cannot say, so it
  guards itself. 1114 unit, 166 integration, 62 browser-module tests.

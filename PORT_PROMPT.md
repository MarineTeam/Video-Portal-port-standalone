Standalone prompt: port Marine Team to PHP + MySQL/MariaDB for ordinary shared hosting

This file is the whole brief for a coding-agent session started in an empty repository. Nothing else is needed: the original application's documentation, data model, URL inventory, the rules its test suite pins down, its configuration reference and its offline shell are embedded as Appendices A–J at the end, and every instruction above them is written against those appendices rather than against source files.

It is large — about 615 KB, roughly 160k tokens. Put it in the empty repository as PORT_PROMPT.md, commit it, and start the session with: "Read PORT_PROMPT.md in full, appendices included, then begin at step 1 of its work plan." An agent that reads only the top half builds a lookalike; the appendices are the specification.

About file names in this document. The instructions were written beside the original Next.js code and name its modules — src/lib/content.ts, src/components/video-player.tsx, decideLinking(), and so on. You do not have those files. Every such name is a label for a rule that the surrounding sentence states, that Appendix A describes, or that Appendix D pins down as test cases. Treat the names as vocabulary; where a sentence says a module "ports as-is", implement it from the rule and the tests. Do not go looking for the files, and do not stop because they are missing.

The one place the original deployment is still needed is the data import in Database, which is written as a script run from a laptop against the old database, not as a change to the old repository.

The appendices were generated from commit 0b9df34 on main of the original repository — the commit that closes the security audit and puts the rota's names behind a sign-in.
The job

Re-implement this application — the whole of it, as described in Appendix A — as a PHP application backed by MySQL or MariaDB that a church volunteer can install on ordinary shared hosting: upload a zip through the host's file manager or FTP, open the site in a browser, follow an installer, done. No shell, no Node, no Composer on the server, no daemons, no Redis. Everything an administrator will ever need to do after that happens in the browser, because on shared hosting the browser is all they have.

What the current app hard-wires to one vendor or to an environment variable becomes a set of swappable services, chosen during install and changeable later from the admin area without touching code:
	Options 	Notes
Sign-in 	Local accounts (password, magic link), Auth0, OpenID Connect with presets (Google, Microsoft Entra ID, Apple, Okta, Keycloak, Authentik, Zitadel, Logto, Kinde, Clerk), Clerk, Supabase Auth, Firebase Authentication 	Local works immediately. Every external provider needs the site reachable over HTTPS first.
Video 	bunny.net Stream, YouTube, Vimeo, shared links from Dropbox, Google Drive and OneDrive/SharePoint, Internet Archive, S3-compatible storage (Cloudflare R2, Backblaze B2, Wasabi, AWS S3), a direct link, the host's own disk 	Uploads go straight from the browser to the provider. Bunny, YouTube and Vimeo transcode; the file-based ones play the MP4 they are given. Only Bunny, S3 and the host's disk can keep a members-only video from anyone holding its link.
Email 	SMTP (presets for the host's own mail server, Google Workspace, Microsoft 365, Zoho, Fastmail and the API providers' relays), PHP mail(), Resend, Mailgun, SendGrid, Postmark, Amazon SES, Brevo, Microsoft 365 via Graph 	If your host blocks outbound HTTPS, SMTP is usually the one that works; if it blocks the SMTP ports instead, the HTTPS API ones are.
Files 	The host's own disk, Bunny Storage 	Local disk works immediately. Bunny Storage adds a CDN and a token-protected pull zone.
Text messages 	Twilio, Vonage, MessageBird (Bird), Plivo, Sinch, Telnyx, Amazon SNS, ClickSend, Textlocal, BulkSMS, a JSON webhook 	Optional; nothing is texted until a member gives their own number and opts in. Choose by country: sender ID and registration rules differ.

Any of them can be changed later under Admin → Services. Switching runs the new service's connection test first, and refuses the switch if it fails. The lists above are the first release, not the ceiling: every slot is a registry, and Adding a provider later below is the whole procedure for another one.

And the port gets WordPress-like plugin and theme functionality: a plugins/ directory of self-contained packages with a header, an activation hook, and access to a documented hook API; a themes/ directory of template and stylesheet overrides; both managed from the admin area, both installable from a zip. A plugin that throws while loading is deactivated automatically rather than taking the site down — on shared hosting there is no shell to disable it from.
What you have

Everything the port is measured against is in this file. Read all of it before writing PHP; the top half says what changes, the appendices say what the application is.

    Appendix A — Feature specification. The original README.md (its "How it works", performance notes and scheduled-jobs sections) and the whole of FEATURES.md, verbatim. Every feature, every URL's behaviour, and the reasoning behind each decision. Where they say Postgres, Prisma, Vercel, environment variables or React, the sections above say what replaces it.
    Appendix B — Data model. The Prisma schema, verbatim: 95 models, 34 enums, and column comments that carry rules the prose doesn't repeat.
    Appendix C — URL inventory. Every page and every API route with its HTTP methods. This is the compatibility contract's list.
    Appendix D — Rules pinned by tests. The titles of every case in the original 73-file test suite, file by file: the edge cases a rewrite loses.
    Appendix E — Plugins and capabilities. The 31 bundled features and the 15 capabilities, as the original registers them.
    Appendix F — Scheduled jobs. The original cron schedule.
    Appendix G — Configuration reference. The original .env.example, whose comments explain each integration; every variable becomes a setting.
    Appendix H — Names the browser depends on. Storage keys, cookie names, cache paths, and the offline-viewer layout that must stay identical.
    Appendix I — Service worker and offline shell. sw.js, offline.html and manifest.json, verbatim, to ship as static files.
    Appendix J — Auth0 Actions. The two Actions and their README, which stay valid for the Auth0 sign-in provider.

The app is roughly 78,000 lines of TypeScript across 91 pages, 218 API routes, 139 components and 137 library modules, with 73 test files. Plan for that; see Work plan at the end.
Non-negotiables

These describe the host the port must run on. Treat each as a test case.

    PHP 8.2 minimum, 8.3 and 8.4 supported. Use nothing newer than 8.2. Required extensions: pdo_mysql, mbstring, json, openssl, ctype, fileinfo. Optional, with the feature that needs it saying so: curl (falls back to allow_url_fopen), zip (plugin/theme upload from a zip), gd (logo resizing), bcmath or gmp (Web Push VAPID signing), intl.
    MySQL 8.0+ or MariaDB 10.6+, InnoDB, utf8mb4. One database, one user, possibly shared with other applications: every table name takes a configurable prefix (default mt_). Nothing may require SUPER, event scheduler, stored procedures, triggers, or FILE privileges.
    Apache with mod_rewrite via .htaccess is the primary target; LiteSpeed behaves the same. Provide an nginx snippet in the docs. The app must also work installed in a subdirectory (example.org/church/): every URL is built from a detected base path, never hard-coded from /.
    No shell after upload. No Composer on the host: third-party PHP libraries are vendored into the release zip by the maintainer. No exec, shell_exec, proc_open, popen, system anywhere in the codebase — many hosts disable them, and the code must not care.
    No long-lived processes. Requests are killed at 30–60 seconds; assume max_execution_time=30 and memory_limit=128M. Every job is time-budgeted and resumable. No WebSockets; live chat polls, exactly as it does now.
    Small upload limits. Assume upload_max_filesize=2M until the installer measures otherwise. Anything larger than that limit is uploaded in chunks or goes straight from the browser to the provider. Video bytes never pass through PHP.
    Outbound HTTPS may be blocked, or allow_url_fopen may be off. The installer probes this and says so; the services that don't need it (local sign-in, SMTP, mail(), local file storage) keep working.
    No HTTPS at first. A site is often set up on a temporary hostname before its certificate exists. Local sign-in, video, and email all work over plain HTTP; every external sign-in provider is offered but refuses to be activated until the site is reached over HTTPS (redirect URIs must be https://, and tokens must not cross the wire in clear).
    A writable directory is the only state outside the database. Default storage/ inside the install; the installer offers to put it above the document root when the host allows and protects it with .htaccess when it doesn't. Sessions, cache, logs, the installer's config.php, plugin state markers and chunked-upload temp files live there. Nothing else is written to disk at runtime except files the local storage backend owns.
    Errors never show a stack trace to a visitor. Every uncaught Throwable renders one plain page and writes a log line; admins read logs at /admin/logs because they have no other way to.

The compatibility contract

The port is a re-implementation behind the same URLs, the same JSON shapes, the same cookie names, and the same localStorage and Cache Storage keys. Not a resemblance — the same. This is what lets the following keep working without a rewrite: the offline shell (public/offline.html), the service worker, an installed PWA on somebody's phone, a Roku channel built on /api/tv/feed.xml, podcast apps subscribed to /series/[slug]/podcast.xml, calendar apps subscribed to /api/calendar/[token]/marine-team.ics, share links people have already sent, and any integration holding an /api/v1 key.

    Every page and every route in Appendix C exists in the port at the same path with the same methods, status codes, and response bodies, as Appendix A describes them. The one permitted difference is under /auth/*, which grows routes for the sign-in providers.
    .ics, RSS, podcast, sitemap, JSON-LD and Open Graph output are byte-compatible where a consumer might care (feeds), and equivalent where only a person reads them (metadata).
    The service worker, offline shell and manifest in Appendix I ship as static files at the same paths (/sw.js, /offline.html, /manifest.json), and the vendored viewers are laid out under the paths Appendix H lists. /api/manifest still renders the manifest from branding.
    Keep marine-device-settings, marine-locale, the bottom-nav snapshot key, the offline indexes, and the Cache Storage paths (/offline-video/<id>.mp4, /offline-book/<id>.pdf, /offline-hymnal/<id>.json, /offline-calendar/snapshot.json) exactly as they are; Appendix H lists every one.
    Ids stay strings. The existing rows use 25-character cuids; the port stores ids as VARCHAR(32) (ascii_bin) and generates new ones as ULIDs or cuid-style strings. Never integers: a data import from a running deployment must be a copy, not a remap.

Architecture

No full-stack framework. Laravel and Symfony are the wrong shape for this target: they need Composer on the host, they pin PHP versions aggressively, and a plugin author on shared hosting cannot follow them. Build a small core — router, PDO wrapper, templates, sessions, CSRF, validation, HTTP client, hooks, plugin and theme loaders, service registry, migrations, jobs — and keep it small enough to read in an afternoon. WordPress is the reference for the shape of the plugin API, not for its code quality: use namespaces, typed signatures, prepared statements everywhere, and no globals beyond one container.

Vendored third-party PHP is allowed from a short list, each pure PHP and shipped in vendor/ inside the release zip: phpmailer/phpmailer (SMTP), minishlink/web-push and its dependencies (Web Push, optional at runtime — if its extension requirements aren't met the feature reports itself unavailable), firebase/php-jwt (OIDC/Auth0 token verification, Google service-account JWTs). Development-only, never shipped: PHPUnit, PHPStan, PHP-CS-Fixer. Composer is a maintainer tool for building the zip.

Layout (the installer offers to move app/, storage/, plugins/ and themes/ above the document root; the default keeps everything in one tree with .htaccess denying direct access to anything but public/):

public/                    document root
  index.php                front controller: bootstrap, route, render
  .htaccess                rewrite everything to index.php; deny dotfiles
  assets/                  css/, js/, icons, sw.js, offline.html, manifest.json
  vendor-js/               pdfjs/, epubjs/, tesseract/, tus/ — prebuilt, committed
app/
  bootstrap.php            loads config, opens DB, registers error handling
  Core/                    Router, Db, View, Session, Csrf, Http, Hooks, Cache, Jobs, Migrator, Log
  Services/                the service registry and the provider interfaces
    Auth/{Local,Auth0,Oidc}Provider.php
    Video/BunnyStreamProvider.php
    Email/{Resend,Smtp,PhpMail}Provider.php
    Files/{LocalDisk,BunnyStorage}Provider.php
  Modules/                 one directory per core feature (Library, Access, Search, Trash, Audit, Branding, ...)
  Migrations/              0001_init.sql … numbered, applied in order
  Templates/               core templates a theme may override
  Lang/en.php, es.php
plugins/                   bundled and third-party plugins, one directory each
themes/                    default/ plus any installed theme
storage/                   writable: config.php, sessions, cache, logs, plugins/, uploads/, tmp/
vendor/                    vendored PHP libraries (shipped)
install/                   the installer; refuses to run once storage/installed.lock exists
bin/migrate.php, bin/cron.php   for the minority of hosts that do have a shell; never required

Templates are plain PHP files rendered through a small View with an escaping helper (e()), layouts, partials, and a theme-aware resolver (active theme → parent theme → app/Templates). Output is escaped by default; raw output is an explicit call that lints loudly.

JavaScript is plain ES modules, no bundler, no framework. A plugin or theme author on shared hosting cannot run a build, and neither should the core need one: a file edited over FTP is the file the browser gets. Every interactive piece attaches to server-rendered HTML through data-* attributes (progressive enhancement) and each lives in its own module under public/assets/js/. Appendix A's descriptions are the behavioural spec for these modules; the original React components are not available, so design each from its description, the storage contract in Appendix H, and the cases in Appendix D. The heavy client logic (reader, presenter, offline shell, chunked upload, live chat polling, watch-progress heartbeat, form builder, rota builder) is the bulk of this work; the offline shell (Appendix I) is already framework-free and ports with light edits, and the pure modules the browser shares with the server (device settings, bottom-bar tabs, the outline parser, SMS segment counting, book-contents parsing, contents navigation, the reader cache, the offline indexes) are specified case by case in Appendix D. Expose one small public API for plugins: MT.hooks (on/filter, mirroring the PHP side), MT.api (fetch with CSRF header and base path applied), MT.settings (device settings read/write).

CSS is hand-written, organised by component, painted entirely through the custom properties lib/branding.ts already derives (--accent, --panel, …) so branding and themes stay a form submission. No Tailwind at runtime and no compiled CSS the host can't regenerate. Reproduce the current look: the sidebar, header, bottom tab bar, dark/light with the pre-paint init script, the standalone-PWA chrome, the television shell, presenter mode.

Sessions live in the database (sessions table), not in the host's shared /tmp: httpOnly, SameSite=Lax, Secure when the request is HTTPS, id regenerated on login, absolute and idle lifetimes. The allowlist and role are re-checked on every request from the database (one cached query per request), so revocation still applies to existing sessions exactly as getCurrentUser() guarantees today.

CSRF: a per-session token, required on every state-changing request; JSON routes accept it as an X-CSRF-Token header, which MT.api sends. /api/v1 and the cron endpoint authenticate by bearer token and are exempt. Webhook receivers (none today) would be too.

Rate limits and locks stay in the database, as they are now — there is no process state to count in. SELECT … FOR UPDATE inside a transaction keeps the event-capacity, group-waiting-list, rota-cover and television-token claims correct under concurrency; keep every one of those transactions.

Errors: a global handler converts every Throwable into a logged line (with request id, user id, route, and the plugin on the stack if any) and one of two responses — a JSON {error, code} for API routes, matching errorResponse() in src/lib/api-guard.ts including which errors carry detail and which are answered generically, or a plain HTML page. Debug mode (config.php, off by default) adds the trace for admins only. Logs rotate by size; /admin/logs tails and downloads them; /admin/system shows PHP version, extensions, limits, disk, database version, the writable check, and the outbound-HTTPS probe result.

Caching is per-request memoisation (the port of React's cache() pattern) plus an optional file cache in storage/cache/ for the handful of site-wide reads every page does — branding, plugin states, nav, the active announcement — invalidated by the writes that change them. Correctness must never depend on the cache being warm or present.
Install and upgrade

/install is a wizard the front controller serves until storage/installed.lock exists, after which it 404s. Its first screen asks for the contents of storage/install.key, which it has just written: proof that whoever is installing can read the files, since a site on a temporary hostname is reachable by anyone who guesses it (see Security requirements).

    Requirements: PHP version and extensions, storage/ writable, a rewrite test (the wizard fetches a known pretty URL from itself and reports if mod_rewrite isn't doing its job), detected upload and execution limits, outbound HTTPS probe (a HEAD to a known host through curl or streams), HTTPS detection for the current request (including X-Forwarded-Proto, trusted only when the admin ticks "behind a proxy"). Failures are explained in words a volunteer can act on ("ask your host to enable the zip extension").
    Database: host, name, user, password, table prefix. Test the connection and the privileges the migrations need before writing anything. Then write storage/config.php (a PHP file returning an array: database, an app_key generated with random_bytes, base URL, storage path, debug flag) and run the migrations — one file per request with a progress bar and a resume, so a slow host's timeout can't leave the schema half-applied (schema_migrations records each file only after it commits).
    Site and administrator: site name, short name, brand colours (the BrandSettings defaults), the site's language default, timezone, and the first administrator as a local account — email and password. This local admin exists even if Auth0 or OIDC is chosen next; it is the recovery path.
    Services: for each of Sign-in, Video, Email, Files and Text messages, pick a provider and fill its fields, with a Test button per provider. Local sign-in, local file storage and mail() are preselected and Text messages defaults to off, so the wizard can finish with nothing external configured; each other provider can be "set up later". External sign-in providers are greyed out with the reason until the wizard is reached over HTTPS.
    Finish: write installed.lock, print the cron line for the host's control panel (see Scheduled jobs) and say what happens if they don't add it, and land on /admin.

Upgrades are the same zip uploaded over the old files (FTP or file manager), then a visit to /admin/update, which detects a newer code version than the database's, enters maintenance mode (a storage/maintenance file that the front controller honours for everyone but the admin performing it), runs pending core and plugin migrations with the same one-per-request resumable runner, and clears the cache. Also offer upload a release zip on that page for hosts with the zip extension: extract to storage/tmp, verify the manifest and checksum, swap directories, migrate.

Backups: /admin/tools exports the database as .sql.gz written in PHP table by table (no mysqldump), streamed so a large site doesn't hit the memory limit, and offers a download of storage/uploads as a zip in parts.
The services layer

A service is a slot the core depends on through an interface; a provider is one implementation. The services table holds one row per slot: slot, provider, config (JSON, secrets encrypted with app_key using sodium or openssl AES-256-GCM), updated_at, updated_by. Providers are registered in code — core ones in app/Services/, more through the services.providers hook so a plugin can add a Mux video host or a Mailjet email provider. Each provider declares:

interface ServiceProvider {
    public static function slot(): string;            // 'auth' | 'video' | 'email' | 'files' | …
    public static function id(): string;              // 'auth0', 'smtp', …
    public static function label(): string;
    public static function configSchema(): array;     // fields: key, label, type, secret?, help, required?
    public static function requiresHttps(): bool;
    public static function requiresOutboundHttps(): bool;
    public static function cspSources(): array;       // origins the Content Security Policy must allow for it
    public function __construct(array $config);
    public function test(): TestResult;               // ok | fail(message for a person)
}

The admin UI for every provider is generated from configSchema(); secrets are write-only fields that show "set" and never echo the value.

Admin → Services shows each slot, its active provider, when it was set and by whom, and a Change action. Changing walks: pick provider → fill fields → Test → Switch. The switch is refused unless the test passed in this same submission (the test result is signed and short-lived, so a stale pass can't be replayed after editing a field). A provider whose requiresHttps() is true can't be switched to unless the current request is HTTPS; one whose requiresOutboundHttps() is true shows the probe result beside it, which is what makes "if your host blocks outbound HTTPS, SMTP is usually the one that works" a thing the screen says rather than the manual.

Slots the port has, and what "test" means for each:

    auth (local accounts, Auth0, OpenID Connect with presets, Clerk, Supabase Auth, Firebase Authentication) — each provider's test is under Sign-in providers below. Beyond the automated test, the switch away from the current provider completes only after the switching admin has signed in through the new one: the new provider is enabled in trial mode for that admin's session alone, they finish a login in a second tab, and the switch commits. No admin can lock themselves out by typing a wrong client id.

    video (bunny.net Stream, YouTube, Vimeo, Dropbox, S3-compatible, direct link, the host's own disk) — each provider's test is under Video below. This slot chooses where new videos go; every provider that has ever been configured keeps playing the videos it holds, so a switch re-hosts nothing.

    email (SMTP, mail(), Resend, Mailgun, SendGrid, Postmark, Amazon SES, Brevo, Microsoft 365 via Graph) — each provider's test is in the table under Email below. Every one ends by sending a message to the admin; mail() alone also needs the admin to type back the six-digit code that message carried, because mail() returning true proves nothing about delivery.

    files (Local disk, Bunny Storage) — this slot was not in the original brief and is added because the port can't exist without deciding where PDFs, audio and other uploads live. Local disk is the default and works with no account anywhere: files under storage/uploads/ (never under the document root), streamed by the app route with Range support. Bunny Storage is the current behaviour, with the private pull zone, token authentication, and the optional public podcast zone. Test: write, read back, and delete a probe object. Each file_assets row records which backend holds it; switching applies to new uploads and an admin tool migrates existing files in batches.

    sms (Twilio, Vonage, MessageBird, Plivo, Sinch, Telnyx, Amazon SNS, ClickSend, Textlocal, BulkSMS, a JSON webhook) — each provider's test is in the table under Text messages below; every one ends with a text to the admin's own phone carrying a code the admin types back, because a provider accepting a message says nothing about a carrier delivering it. Off by default; the broadcast composer offers the channel and says what is missing, as now.

The remaining env-driven integrations become settings groups on the same page, same generated forms, same test button, but optional and not install-time: Web Push (VAPID pair, generated in PHP with a button), Transcription (URL, key, model, max bytes), Google Sheets (service-account JSON; the JWT is signed with openssl_sign RS256 and exchanged for an access token, replacing google-auth-library), Video import (YouTube API key, Vimeo token), API keys stay where they are. AUTHORIZATION_MODE, ADMIN_EMAILS, AUTH0_ORGANIZATION_ID, CRON_SECRET, BUNNY_STREAM_DOWNLOAD_HEIGHT, QUERY_MONITOR_ENABLED all move into settings with the same semantics (ADMIN_EMAILS becomes "bootstrap administrators", still granting ADMIN on login). Every place the current app says "set SOME_VAR" now names the setting and links to it.
Adding a provider later

Every slot's provider list is the registry, and the registry is the only thing the admin screens read, so adding a provider — in the core under app/Services/<Slot>/, or in a plugin whose header says Provides: <slot> — never touches a core screen. A new provider is:

    one class implementing the slot's interface and ServiceProvider;
    its configSchema(), which is the whole of its admin form;
    its test(), written to fail with a sentence a volunteer can act on;
    recorded HTTP fixtures for the test and the main calls, so CI covers it without an account;
    a row in SERVICES.md's table for that slot, in the same columns as the tables in this document, saying honestly what it can and can't do;
    and per slot: a video provider's VideoCapabilities and PlayerSpec; a sign-in provider's flow kind and how sub, email_verified and the membership claim are derived; an email provider's error mapping; an SMS provider's sender kind and its receipt and reply webhooks; a files provider's streaming and signing rules.

The providers each section lists as "later" are expected to fit without changing an interface. If one doesn't, change the interface in the core and record it under "Deviations" in PORT_MAP.md, rather than special-casing that provider.
Sign-in providers

The slot holds one primary provider. Local sign-in can stay enabled beside it — for ADMIN accounts by default, for members when the admin says so — which is what the lockout rule below relies on. Several external providers active at once is not required; the identity model already allows it, so a later version can offer it without a migration.

Keep the identity model: a users row per person, user_identities rows keyed by sub with provider, email, email_verified. decideLinking() (the rule follows; Appendix D's identity-linking cases pin it) stays the only way an incoming identity is attached to a member: sub first; a never-seen sub may attach by email only when the provider verified it; an unverified match is refused indistinguishably from any other denial. sub is stored namespaced as <provider id>|<the provider's own sub> for every provider except Auth0, whose subs (google-oauth2|…) are already globally unique and are kept verbatim so imported identities still match. authorizeIdentity() and the four AUTHORIZATION_MODEs port with their fail-closed defaults; the organisation check becomes a membership claim any provider may supply (Auth0's org_id, an OIDC groups value, Entra's tid, Clerk's org_id, Google's hd), and a provider without one reports "not applicable", which under BOTH means the allowlist decides. Refusals record unauthorized_access_attempts with the same reasons, the same once-an-hour dedupe, the admin email on first refusal, and the 90-day prune. /access-denied stays one plain sentence.

Three flow kinds are all the interface has to know about:

interface AuthProvider extends ServiceProvider {
    public function flow(): string;                 // 'form' | 'redirect' | 'token'
    public function routes(Router $r): void;         // its own /auth/* routes
    public function logoutUrl(?string $returnTo): ?string;
    public function membershipClaim(): ?string;      // what stands in for Auth0's org_id, if anything
    public function registrationCheck(): ?RegistrationCheck;
        // how the provider can ask "may this address sign up" before creating an account
}

    form — the provider renders and handles its own forms: local accounts; Supabase's password and magic-link modes.
    redirect — start → provider → /auth/callback, with state, nonce and PKCE checked: Auth0, OpenID Connect and every preset, Supabase's social sign-in, Clerk used as an OIDC provider.
    token — the provider's own script, which the browser loads from the provider's CDN (the host's outbound rules don't apply to the browser), signs the person in; the browser POSTs the resulting JWT to /auth/token; PHP verifies it against the provider's JWKS or secret, derives the identity, and only then writes the app session: Clerk native, Firebase Authentication, Supabase through supabase-js.

Whatever the flow, one function turns a verified assertion into an Identity (sub, provider, email, email_verified, name, picture, membership), and authorizeIdentity and decideLinking run on it unchanged. Every provider except local requires the site to be HTTPS — redirect URIs must be https://, and a token or a password must not cross the wire in clear — and both the installer and Admin → Services refuse them otherwise and say why. Every provider hands the post-login destination through one safeReturnTo(), which accepts only a relative path under the base path.

Providers in the core:

    Local accounts (provider = 'local'). Passwords with password_hash(PASSWORD_ARGON2ID) where available, bcrypt otherwise; a rehash on login when the algorithm improves. Routes: /auth/login (form), /auth/logout (POST, CSRF), /auth/register (only when self-registration is on — off by default; invitation is an ACTIVE row in authorized_emails, which is what the registration form checks, so the Pre-User-Registration Action's job survives), /auth/verify/[token], /auth/reset and /auth/reset/[token] (via the email service; a reset link is single-use, expires in an hour, and a request for an unknown address answers exactly like a known one). Magic link is a mode of the same provider: a single-use sign-in link by email, valid fifteen minutes, offered as the first button when the admin turns it on — for the members who will never keep a password — and needing the email service. Login throttling is per account and per IP, in the database. Password change and "sign out everywhere" live on /profile/settings. Test: nothing external; the row says "always available".
    Auth0. Authorization Code with PKCE against the tenant's OIDC endpoints, the organization parameter sent under the same rules src/lib/auth0.ts documents (exactly one configured and required → send it; zero, several, or ALLOWLIST/EITHER → omit it), the org_id claim of the verified ID token as the only proof of membership, /auth/guest with its database-backed master switch and 404 behaviour, the callback-error recording including the error/error_description Auth0 sent back, and the registration-check endpoint for the Pre-User-Registration Action (the Actions in Appendix J stay valid; ship them with their README updated for the new setting names). ID tokens are verified against the tenant's JWKS (cached in the file cache with kid rotation handled), nonce and state checked, and the session cookie is only written after authorizeIdentity says yes — what src/proxy.ts exists to enforce is natural here because the app owns the cookie. Test: discovery and JWKS fetch, and the redirect URI is HTTPS.
    OpenID Connect, generic. Issuer URL → discovery document → PKCE code flow → JWKS verification (RS256/ES256), email, email_verified, name, picture, an optional membership claim and required value, an optional end-session endpoint. Presets fill the discovery URL, scopes, claim mapping and the notes for a named provider and are otherwise this same provider: Google (with hd as the membership claim for a Workspace domain; not every account type carries email_verified), Microsoft Entra ID (tid or groups as membership; the email optional claim must be enabled), Sign in with Apple (the client secret is an ES256 JWT signed with the Apple key and regenerated before it expires; response_mode=form_post; the name arrives on the first sign-in only; private-relay addresses), Okta, Keycloak, Authentik, Zitadel, Logto, Kinde, and Clerk as an OIDC provider (discovery on the instance's Frontend API domain, org_id as membership). Test: discovery, JWKS, and the redirect URI is HTTPS.
    Clerk, native. ClerkJS, loaded from the instance's Frontend API domain, mounts Clerk's sign-in on /auth/login; on success the browser posts the session JWT to /auth/token; PHP verifies it against the Frontend API's /.well-known/jwks.json, takes sub (user_…) and org_id, and gets the address and its verification from a JWT template the docs tell the admin to create (email, email_verified) or, failing that, from the Backend API (GET /v1/users/{id} with the secret key). Production instances need the DNS records Clerk asks for and an HTTPS site. Test: fetch the JWKS, and GET /v1/users?limit=1 with the secret key.
    Supabase Auth. GoTrue's REST API, pure HTTP from PHP, no SDK: password (POST /auth/v1/token?grant_type=password), magic link (POST /auth/v1/otp, then POST /auth/v1/verify with the token_hash from the link), and any social provider Supabase has enabled through its PKCE flow (GET /auth/v1/authorize?provider=…&code_challenge=… → /auth/callback?code= → POST /auth/v1/token?grant_type=pkce). The access token is verified locally — HS256 with the project's JWT secret, or the project's JWKS when it uses asymmetric signing keys — with GET /auth/v1/user as the fallback; email_confirmed_at is email_verified. Sign-up through /auth/v1/signup only when self-registration is on and the allowlist says yes; reset through /auth/v1/recover; Supabase's "before user created" auth hook can call /api/auth/registration-check the way the Auth0 Action does. Test: GET /auth/v1/settings with the anon key, and GET /auth/v1/admin/users?per_page=1 with the service-role key when one is given.
    Firebase Authentication. Token flow: the Firebase JS SDK (and optionally FirebaseUI) from Google's CDN signs the person in; PHP verifies the ID token's RS256 signature against Google's published certificates (cached per their Cache-Control), iss = https://securetoken.google.com/<project>, aud = <project>, and takes sub, email, email_verified, name, picture. Test: fetch the certificates and the project's public configuration with the web API key.

Later, and expected to fit without changing the interface: passkeys (WebAuthn) on local accounts with a single-file pure-PHP library; SAML for a diocese or denomination's identity provider; Stytch, Descope and Hanko by their token flows; more than one external provider active at once.

Lockout prevention is a rule, not a hope: the bootstrap local admin always exists; every switch of this slot completes only after the switching admin has signed in through the new provider in trial mode; and a break-glass that needs no shell — creating an empty file storage/enable-local-login re-enables local sign-in for ADMIN accounts on the next request and shows a banner until it is deleted. Document it in INSTALL.md under "Locked out".
Video

The Video slot is where new videos go, not the only player. A library can hold videos on several providers at once — it already does, through Video.source — so every provider with saved configuration keeps playing the videos it holds after the default changes, each video row records its own provider, and a switch re-hosts nothing. The switch test applies to the new default.

VideoProvider is the interface. Providers declare what they can do and the admin screens show it, rather than promising the same thing of all of them:

interface VideoProvider extends ServiceProvider {
    public function capabilities(): VideoCapabilities;
        // upload, link, transcodes, thumbnails, duration, captions, mp4,
        // enforcesPrivacy, progressEvents — each true or false
    public function createUpload(string $title, UploadHints $hints): UploadTicket;
        // a placeholder at the provider, plus what the browser must do next
    public function matchesLink(string $url): bool;
    public function resolveLink(string $url): LinkedVideo;
        // canonical id, title, duration, thumbnail, playable URL(s)
    public function get(string $id): VideoInfo;          // status, duration, thumbnail, renditions
    public function delete(string $id): void;
    public function player(string $id, PlayerOptions $o): PlayerSpec;
        // {kind: 'iframe', src} or {kind: 'native', sources[], tracks[], poster}
    public function thumbnailUrl(string $id, ?string $file): ?string;
    public function setThumbnail(string $id, string $imageUrl): void;
    public function mp4(string $id, int $maxHeight): Mp4Result;   // ok(url, height) | reason
    public function captions(string $id): ?CaptionOps;           // list, add, delete — or null
}

UploadTicket tells the one vendored uploader which of six things to do, and in none of them does a long-lived credential reach the browser: tus (endpoint, headers, metadata — Bunny and Vimeo), put or multipart (presigned URLs — S3-compatible), resumable (a pre-authorised session URL PHP opened with the provider's OAuth token, which the browser PUTs to with no credential of its own — YouTube, Google Drive, OneDrive), dropbox (a four-hour access token for the admin's browser and Dropbox's upload-session calls), or chunked (slices through PHP — the host's own disk).

Adding a video in /admin/videos is two tabs: Upload, to the default provider, and Link, a pasted URL that each link-capable provider is asked to matchesLink(), with the first match resolving it.

Providers in the core, and what each honestly offers:
Provider 	Upload from the browser 	Transcodes, adaptive 	Thumbnail and duration 	Captions 	MP4 for downloads and Cast 	Keeps a members-only video from anyone with the link 	Limits the screen must state
bunny.net Stream 	TUS 	yes 	from the provider 	API 	MP4 fallback 	yes: signed embed, token auth 	paid, cheap
YouTube 	link; optional Data API resumable upload 	yes 	from the provider 	on YouTube 	no 	no: unlisted is a secret, not a lock 	default API quota allows about six uploads a day; an unverified app's uploads are locked private
Vimeo 	TUS through the API 	yes 	from the provider 	API 	file links on paid plans 	partly: domain-restricted embed on paid plans 	free tier is 500 MB a week
Dropbox shared link 	link; optional API upload with a short-lived token 	no: must already be H.264/AAC MP4 	captured and read in the browser 	VTT sidecar via the Files slot 	yes, it is a file 	no 	20 GB a day on Basic, 200 GB on paid; links pause past that
Google Drive shared link 	link; optional resumable upload through the Drive API 	no 	from the Drive API with a browser-restricted key; otherwise captured 	VTT sidecar 	yes with the API key; no in preview-embed mode 	no 	Drive is not a CDN: a much-watched file trips its per-file download quota for a day
OneDrive / SharePoint shared link 	link; optional upload through a Graph upload session 	no 	from the shares or Graph API; otherwise captured 	VTT sidecar 	yes 	no for the link; the Business download URL is short-lived 	Microsoft throttles hot files; a tenant can forbid anonymous links
Internet Archive 	link only 	no; the Archive derives an H.264 copy after upload 	from the metadata API and services/img 	VTT sidecar 	yes 	no, everything there is public 	free; playback speed varies
S3-compatible (Cloudflare R2, Backblaze B2, Wasabi, AWS S3) 	presigned PUT, multipart above the single-PUT limit 	no 	captured and read in the browser 	VTT sidecar 	yes 	yes: presigned GET per request 	bucket CORS must allow the site; egress pricing varies, R2's is free
Direct link 	link only 	no; HLS .m3u8 through vendored hls.js 	captured and read in the browser 	VTT sidecar 	yes when it is a file 	no 	whatever hosts the file
The host's own disk 	chunked through PHP 	no 	captured and read in the browser 	VTT sidecar 	yes 	yes, at the cost of PHP bandwidth 	the plan's disk and bandwidth; the last resort

What must be right per provider:

    bunny.net Stream keeps everything src/lib/bunny.ts does for Stream: the placeholder via POST /library/{id}/videos; the TUS presign sha256(libraryId . apiKey . expirationTime . videoId) with a one-hour expiry against https://video.bunnycdn.com/tusupload, the vendored tus-js-client streaming the file and the browser calling /api/admin/videos/[id]/sync-status afterwards; embed and thumbnail URLs signed per request as sha256_hex(tokenAuthKey . videoId . expires) when a token-auth key is set and unsigned otherwise; the t= start parameter; MP4 fallback per resolveMp4Source — read hasMP4Fallback and availableResolutions, cache them on the row, pick the highest at or under the configured height, answer with the same four reasons; captions in Bunny; status polling of PROCESSING videos; "import from the Bunny library". Test: GET /library/{id} with the API key succeeds, the CDN hostname answers, and with token auth a signed thumbnail URL returns 200 while an unsigned one returns 403.
    YouTube: link mode parses watch, youtu.be, shorts and embed URLs; plays in youtube-nocookie.com with rel=0, as video-source.ts does now; thumbnail from i.ytimg.com; title and duration from the Data API when a key is set, from oEmbed otherwise. Upload mode is optional and needs an OAuth client — a Google Cloud project, the channel owner's one-time consent stored as a refresh token, the youtube.upload scope — after which PHP opens a resumable session and the browser PUTs the file to it; new uploads default to unlisted. The settings page says that the default Data API quota allows about six uploads a day and that an app Google has not verified has its uploads set private. Test: videos.list with the key; with OAuth, refresh the token and channels.list mine=true. Importing a channel or playlist stays under Admin → Video feeds.
    Vimeo: link mode via oEmbed or GET /videos/{id}; embed on player.vimeo.com with dnt=1, as now; upload via POST /me/videos with upload.approach=tus, the browser PATCHing to the returned upload_link; privacy set from the video's members-only flag (unlisted, and disable plus a domain whitelist on plans that allow it); captions as text tracks; MP4 from files on plans that expose them, otherwise the reason "your Vimeo plan doesn't give file links". Test: GET /me shows the upload scope and the remaining quota.
    Dropbox shared link: paste dropbox.com/s/…, /scl/fi/… or dl.dropboxusercontent.com; normalise to a direct URL (dl=1), HEAD it to confirm video/* and Range support, play in a native <video>. Optional upload through an app-folder OAuth app: PHP holds the refresh token, mints a four-hour access token for the admin's browser, the browser runs upload_session/start, append_v2, finish, and PHP creates the shared link. Say on screen that the link itself is the credential and that Dropbox pauses links that pass its daily bandwidth. Test: refresh the token and users/get_current_account; a link-only configuration has nothing to test and the row says so.
    Google Drive shared link: paste drive.google.com/file/d/<id>/view, open?id=, or uc?id=. Two modes, chosen on the settings row. Preview embed needs no key: an iframe on drive.google.com/file/d/<id>/preview, Google's own player, no MP4, no progress events, no start time. Drive API needs an API key restricted to the site's HTTP referrers with the Drive API enabled: metadata from files/<id>?fields=name,size,mimeType, videoMediaMetadata,thumbnailLink, playback in a native <video> straight from files/<id>?alt=media&key=…, which supports Range and CORS for files shared with anyone with the link. Never use the uc?export=download path — it interposes a virus-scan page above about 100 MB. Optional upload with OAuth (drive.file scope): PHP opens a resumable session, the browser PUTs chunks to it, PHP grants anyone: reader. Say on screen that Drive is not a CDN and that a much-watched file is refused for a day once it passes its download quota. Test: with a key, GET files/<known public id>; in embed mode there is nothing to test and the row says so.
    OneDrive / SharePoint shared link: paste a 1drv.ms, onedrive.live.com, <tenant>-my.sharepoint.com or <tenant>.sharepoint.com link. The link is encoded as u! plus its base64url form. Personal OneDrive resolves anonymously through api.onedrive.com/v1.0/shares/<encoded>/root for name, size, video.duration and thumbnails, and streams from …/root/content, which redirects to a Range-capable URL. OneDrive for Business and SharePoint need an Entra app registration with the Files.Read.All application permission: PHP takes a client-credentials token, resolves graph.microsoft.com/v1.0/shares/<encoded>/driveItem, and hands the player the pre-authenticated, short-lived @microsoft.graph.downloadUrl minted per request after canViewVideo — the link is still the credential, but the URL the page carries expires. Optional upload for either: Graph's createUploadSession returns a pre-authenticated uploadUrl the browser PUTs to in ranges, then PHP creates an anonymous view link — and says so when the tenant forbids those. Test: personal, resolve a known public share; Business, obtain the client-credentials token and resolve a share link the admin pastes into the test, which proves the permission and the consent together.
    Internet Archive: paste archive.org/details/<identifier>; read archive.org/metadata/<identifier> for the files, pick the H.264 or MPEG4 derivative, play it natively from archive.org/download/<identifier>/<file> (Range and CORS both fine), thumbnail from archive.org/services/img/<identifier>, duration from the file's length. Everything there is public, so members-only is page-level only and the screen says so. No configuration, so no test. Upload through the Archive's S3-like API is a "later" item: its LOW key:secret authorisation is not SigV4, so the S3-compatible provider doesn't cover it.
    S3-compatible: endpoint, region, bucket, key id, secret, optional public base URL or CDN. SigV4 presigning in pure PHP; one presigned PUT up to the provider's single-object limit and presigned multipart above it; playback through a fifteen-minute presigned GET minted per request after canViewVideo — which is what makes this provider enforce members-only — or through the public base URL when the admin marks the bucket public. Thumbnail and duration captured by the browser on upload and stored beside the object. Test: from the server, presign, PUT, GET and DELETE a probe object; from the browser during the same test, PUT a one-byte object with a presigned URL, which proves the bucket's CORS.
    Direct link: any HTTPS URL; HEAD for Content-Type: video/* or application/vnd.apple.mpegurl; native player; vendored, prebuilt hls.js for .m3u8 where the browser has no native HLS. No configuration, so no test; always available.
    The host's own disk: chunked upload as under Uploads through PHP, stored under storage/videos/ with an unguessable name; a public video is published as public/media/videos/<random>.mp4 so Apache serves it without PHP; a members-only video stays in storage/ and streams through the app route with Range support, X-Sendfile where the host has it. The settings page shows free disk space and says plainly this is for a church with a few videos. Test: the directory is writable and the public path serves a probe file.

Rules that cut across providers:

    One player module, two kinds. iframe for Bunny, YouTube and Vimeo; native (<video>) for everything file-based. Both take a start time for resume, chapters and ?t= links — swapping the iframe src as now, setting currentTime on native — and both report progress where the provider allows: native timeupdate, YouTube's IFrame API, Vimeo's Player SDK, Bunny's Player.js. The watch-progress heartbeat becomes accurate on those and keeps its elapsed-time fallback elsewhere. The autoplay and playback speed device settings apply wherever the player kind can honour them.
    Members-only is honest. Marking a video members-only on a provider whose enforcesPrivacy is false shows, at that moment, "the page is gated; the video's own URL is not", and the admin list badges it. The television feed lists a video only when its provider can hand a Roku a stream it can play — HLS from Bunny, an MP4 URL from the file-based providers — and keeps its members-only-on-video-and-series rule.
    Downloads and Cast go through mp4() and its reasons; resolveMp4Source is the Bunny implementation of it. YouTube's answer is "not from this provider", said as such.
    Captions on file-based providers are .vtt files uploaded through the Files slot and attached as <track> elements; Bunny and Vimeo use their APIs; YouTube's are managed on YouTube.
    Thumbnails can be replaced by an admin on any provider. For file-based ones the uploader captures a frame in the browser (<video> to a canvas, when CORS allows it) at upload or link time and otherwise asks for an image.
    Live streaming is unchanged: LiveStream rows point at a stream hosted elsewhere.
    Feed import from a YouTube channel or playlist and a Vimeo account or showcase stays under Admin → Video feeds with the three-way sync rule intact; imported rows are ordinary youtube and vimeo rows.
    A pasted link is a request the server makes on the admin's behalf. Every link resolution and every HEAD goes through Http::fetchUntrusted() — host resolved and checked against private and metadata ranges on each redirect hop, ten-second timeout, capped body — as Security requirements specifies.
    Later, and expected to fit the interface without changes: hosts with a placeholder-plus-direct-upload flow like Bunny's — Cloudflare Stream (one-time upload URLs, TUS), Mux (signed direct uploads), Wistia, JW Player, PeerTube; embed-only sources — Rumble, Facebook video, Twitch for live; and link sources — Box shared links (direct links on paid plans), Backblaze B2's native API, Internet Archive upload. PLUGINS.md uses a video provider plugin as its worked example, and Adding a provider later is the procedure.

Email

EmailProvider::send(Message) where a message has to, subject, a plain text body and an html body, and an optional replyTo. Everything that sends — notifications, broadcasts (one row per recipient per channel, marked as it goes, batch loop driven from the browser exactly as broadcast-send.ts does), password resets and magic links, verification, the admin refusal alert, the mail() confirmation code — goes through it. Every send writes an email_log row (to, subject, provider, status, provider message id, error), shown at /admin/email with a resend button, because on shared hosting the log is the only way to learn a message never left.

The two sentences the Services page says, both true: if the host blocks outbound HTTPS, SMTP is usually the one that works; if it blocks the SMTP ports (25, 465, 587) instead — common too — the HTTPS API providers are. Every API provider below also offers an SMTP relay, and the SMTP provider has a preset for each, so nothing forces one transport.

Providers in the core, and what "test" means for each. Every test ends by sending a message to the admin; the mail() test alone also requires the admin to type back the six-digit code it carried before the switch commits, because mail() returning true proves nothing about delivery.
Provider 	Talks over 	Test before switching
SMTP (PHPMailer) 	25 / 465 / 587 	connect, STARTTLS or TLS as configured, EHLO, AUTH, then the test message
PHP mail() 	the host's own mail transfer agent 	the function exists; the code-confirmed test message
Resend 	HTTPS 	GET /domains; from is on a verified domain
Mailgun 	HTTPS, US or EU endpoint 	GET /v3/domains/<domain> shows the domain active
SendGrid 	HTTPS 	GET /v3/scopes includes mail.send; from is among the verified senders
Postmark 	HTTPS 	GET /server with the server token; from is a confirmed sender signature
Amazon SES 	HTTPS, SigV4 (the same signer as the S3 video and files providers) 	GetAccount; the from identity's verification status; a warning while the account is in the sandbox
Brevo 	HTTPS 	GET /v3/account
Microsoft 365 via Graph 	HTTPS 	a client-credentials token, then GET /users/<from>; the docs explain the Mail.Send application permission, admin consent, and an application access policy limiting it to that mailbox — this provider exists because SMTP AUTH is off by default in new tenants

SMTP presets fill host, port and encryption and say which credential goes where: the host's own mail server (the installer's first suggestion — on cPanel that is localhost:25 with no authentication or the mailbox's own credentials, and it is usually what works), Google Workspace / Gmail with an app password, Microsoft 365 (SMTP AUTH must be enabled for the mailbox), Zoho, Fastmail, and the relays of Mailgun, SendGrid, Postmark, Amazon SES, Brevo and SMTP2GO.

Unconfigured email is a first-class state: sends become recorded no-ops, the screens that need email say so, and local sign-in's reset flow explains that an admin must set the password instead.

Later: MailerSend, SparkPost, Mailjet, Elastic Email, Mailtrap, and the Gmail API with domain-wide delegation for a Workspace that forbids app passwords. Inbound mail is out of scope.
Text messages

Texting is its own slot rather than a settings group: a church picks a texting provider by country and price the way it picks an email provider, and switches it for the same reasons. SmsProvider::send(To, Body) returns the provider's message id or throws an SmsError carrying the provider's own sentence ("is not a valid phone number"), which the broadcast screen shows beside the recipient's name — what sms-send.ts does for Twilio today. The provider-independent half stays in the one module the composer also loads in the browser: sms.ts's E.164 normalisation with the default country code (a national number with no default is refused, never guessed at) and GSM-7 versus UCS-2 segment counting, so the cost on screen is the cost that goes out.

Consent stays the app's rule, not the provider's: smsOptIn is set only by the member, is never inferred from a number a sign-up form collected, and planDelivery decides who is texted before any provider is asked. A text carries the opt-out instruction its destination country expects whenever the provider doesn't append one itself.

Every provider declares the kind of sender it takes — a phone number, an alphanumeric sender ID, or a provider-side messaging service — and the settings row shows the country rules that decide whether a text arrives: the US and Canada refuse alphanumeric senders and need A2P 10DLC registration at the provider before a long code delivers reliably; toll-free numbers need verification; the UK and most of Europe accept an alphanumeric sender. "Accepted and never arrived" is usually one of these, which is why the test below goes end to end.

Test before switching: the credentials call in the table, then a text to a number the admin types, carrying a six-digit code the admin types back. The switch commits only on the code — a text the provider accepted but that never arrived (an unregistered sender, a sandbox, a sender ID the country rejects) is exactly the failure this catches.
Provider 	Send 	Credentials test
Twilio 	POST /2010-04-01/Accounts/{sid}/Messages.json, basic auth (account SID and token, or an API key), form To, From or MessagingServiceSid, Body, optional StatusCallback 	GET /2010-04-01/Accounts/{sid}.json; the From number or messaging service exists on the account
Vonage 	SMS API POST rest.nexmo.com/sms/json; messages[0].status of 0 is success, error-text otherwise 	GET rest.nexmo.com/account/get-balance
MessageBird (Bird) 	POST rest.messagebird.com/messages with Authorization: AccessKey; a second mode for Bird's workspace-and-channel endpoint, since the company renamed and accounts are moving 	GET rest.messagebird.com/balance, or the workspace's channel list in the second mode
Plivo 	POST api.plivo.com/v1/Account/{id}/Message/, basic auth, JSON src, dst, text 	GET api.plivo.com/v1/Account/{id}/
Sinch 	POST {region}.sms.api.sinch.com/xms/v1/{plan}/batches, bearer; the region is chosen on the row 	GET …/batches?page_size=1
Telnyx 	POST api.telnyx.com/v2/messages, bearer, from or messaging_profile_id 	GET api.telnyx.com/v2/messaging_profiles
Amazon SNS 	Publish with PhoneNumber, SMSType=Transactional, optional SenderID; SigV4 through the shared signer 	GetSMSAttributes; a warning while the account is in the SMS sandbox or still at the default monthly spend limit
ClickSend 	POST rest.clicksend.com/v3/sms/send, basic auth 	GET rest.clicksend.com/v3/account
Textlocal (UK, India) 	POST api.txtlocal.com/send/ 	the same call with test=1, which Textlocal validates without sending, then GET /balance/
BulkSMS 	POST api.bulksms.com/v1/messages, basic auth, JSON to, from, body 	GET api.bulksms.com/v1/profile
JSON webhook 	POST of {to, from, body} with an optional bearer, as SMS_WEBHOOK_URL does now — a gateway of the church's own, a national provider with an API of its own, an office phone system 	POST {"ping": true} answered 2xx; the docs say what a gateway must implement

Delivery receipts and replies are optional per provider. /api/sms/status/<provider> takes the provider's status callback with its signature checked — Twilio's X-Twilio-Signature, Vonage's signed JWT, MessageBird's signature header, Telnyx's Ed25519 signature, each a pure-PHP check — and moves the broadcast_recipients row from accepted to delivered or failed with the provider's reason, so /admin/broadcasts can say "reached" rather than "sent". /api/sms/inbound/<provider> takes replies, and a reply that is a stop word (STOP, UNSUBSCRIBE, CANCEL, END, QUIT, and the Spanish catalogue's equivalents) switches that member's smsOptIn off and writes an audit row. Providers that handle stop words themselves on US and Canadian numbers are noted; the app still honours the reply, because the same number can be on the list under a different provider tomorrow. Both endpoints are unauthenticated by design and verified by signature; a provider with no signature scheme gets a per-install secret in its callback URL instead.

Later: Infobip, Africa's Talking, 46elks, Esendex, SignalWire, Bandwidth, SMSGlobal, Clockwork. WhatsApp through the providers that offer it is a different channel with different consent rules and is out of scope here.
Plugins

A plugin is a directory under plugins/<slug>/ containing plugin.php with a header block, the same idea as WordPress:

<?php
/**
 * Plugin Name: Sermon notes
 * Slug:        sermon-notes
 * Version:     1.0.0
 * Description: Lets members keep their own timestamped notes on a video.
 * Author:      Marine Team
 * Requires PHP: 8.2
 * Requires App: 3.0
 * Provides:    (optional) auth|video|email|files — this plugin registers a service provider
 * Category Override: yes|no — whether it can be switched per category
 */

plugin.php returns (or registers) an object implementing:

interface Plugin {
    public function boot(Hooks $hooks, Container $c): void;   // every request while active
    public function activate(Container $c): void;             // once, on activation: run own migrations
    public function deactivate(Container $c): void;           // on deactivation; must not drop data
    public function uninstall(Container $c): void;            // explicit "delete data" from the admin
    public function migrations(): array;                      // versioned SQL/PHP steps, prefix p_<slug>_
}

Hooks are the extension surface, Hooks::on($name, callable, $priority) for actions and Hooks::filter($name, callable, $priority) for filters, with Hooks::do($name, ...$args) and Hooks::apply($name, $value, ...$args) on the core side. Every hook name is documented in PLUGINS.md with its arguments and where it fires. The core must fire at least these, and the bundled plugins must be implementable with them alone:

    lifecycle: app.boot, app.request, app.shutdown
    routing: routes.register(Router) — public, API and admin routes with middleware (auth, capability, CSRF, rate limit)
    access: capabilities.register, content.can_view(bool, item, user), user.resolved(user), auth.refused(attempt)
    content: series.saved, video.saved, file.saved, *.published, *.trashed, *.restored, *.purged, content.search_sources, home.rows, related.items
    UI: nav.sections, nav.tabs, admin.menu, render.head, render.body_end, page.video.panels, page.series.panels, profile.sections, profile.settings, admin.dashboard.cards
    services and settings: services.providers, settings.register, plugin.category_override (declares support)
    jobs: jobs.register(Scheduler) — name, interval, callable, budget
    i18n: lang.catalogue(locale, array)
    templates: template.resolve(path) and per-template template.<name>.vars

Plugins may add tables (own prefix, own migrations), routes, admin pages built from the same layout and form helpers the core uses, settings groups, capabilities, jobs, service providers, nav items, and translations. They may read core data through the module services (Library, Access, Users, …), never by reaching into another plugin's tables. Plugin JS is a plain module under plugins/<slug>/assets/, served through a route that maps /plugins/<slug>/assets/* to it with the right cache headers and refuses anything executable or hidden; plugin CSS uses the same custom properties.

The 31 features in PLUGIN_META (Appendix E) become bundled plugins in plugins/, written against the public hook API and nothing else. That is not tidiness: it is how you find out the API is complete. Bundled plugins are marked so in the plugins table, cannot be deleted from the UI, and default to active on a fresh install exactly as ensurePluginsSeeded() does now. Per-category overrides (plugin_category_overrides, nearest-ancestor wins, fail-open when a row is missing) stay a core facility available to any plugin that declares it; getPluginStates() — all plugins resolved in two or three queries, once per request — is the port's PluginStates service and the only way a page asks. The query-monitor row keeps its special status: a Plugin row that is not a plugin, excluded from the list.

The core keeps what is not a plugin today: the library, access, users and permissions, search, trash, audit, branding, i18n, the profile shell, the PWA and offline shell, services, jobs, plugins and themes themselves.

Loading, and what happens when a plugin throws. On each request the loader reads the active list, orders it (bundled first, then by declared dependencies, then name) and for each plugin:

    writes storage/plugins/loading.json — {slug, request_id, started_at};
    requires plugin.php and calls boot() inside try { … } catch (\Throwable $e). ParseError, TypeError, a missing class, an exception from a constructor — all land here;
    deletes the marker.

Three things turn a failure into an automatic deactivation, each recorded on the plugins row as deactivated_reason, deactivated_at, and the first 2 KB of the error, and each shown as a dismissible notice on every admin page and in the plugin list:

    the catch above;
    a register_shutdown_function that checks error_get_last() for E_ERROR/E_PARSE/E_COMPILE_ERROR/E_CORE_ERROR while $currentlyLoading is set — memory exhaustion and the timeout both reach a shutdown function — deactivates that plugin, and renders the plain error page instead of a white screen;
    at the top of bootstrap, a loading.json older than 60 seconds from a different request id means a request died mid-load in a way nothing above caught (a hard kill, a segfault); the plugin it names is deactivated before any plugin loads.

Throwing from a hook callback after boot is contained rather than fatal: the dispatcher catches, logs, counts it in the file cache, and the page continues without that callback's contribution; ten failures in ten minutes deactivates the plugin the same way. A plugin's own route throwing renders the error page for that route only. Deactivation is a one-column write plus a cache clear, and it emails the administrators once.

Two pages never load third-party plugins, so an administrator can always reach them: /admin/plugins and /admin/logs. /auth/* loads only plugins whose header says Provides: auth, and if that one fails, falls back to local sign-in for ADMIN accounts with the reason on screen.

Installing and updating from the admin: upload a zip (needs ext-zip; without it, the page explains the FTP route: unzip into plugins/, then refresh the list), which must contain exactly one top-level directory with a plugin.php whose header parses and whose Slug matches the directory; the zip is extracted into storage/tmp and moved into place only after that check. Activation runs activate() in a transaction where the migrations allow it, and a throw there leaves the plugin inactive with the error shown. Updating is uploading a newer version over the old; the loader notices the version change and runs the new migrations on the next request under the same resumable runner. Uninstall is separate from deactivate and is the only thing that calls uninstall(). Only manage_plugins may do any of this. Plugin code runs with the application's full privileges, like WordPress; PLUGINS.md says so in its first paragraph.
Themes

A theme is themes/<slug>/ with theme.json (name, slug, version, parent?, author, screenshot), templates/ mirroring app/Templates/ paths to override any of them, assets/ (theme.css is loaded after the core stylesheet; theme.js after the core modules), an optional functions.php that receives the same Hooks as a plugin, and an optional customizer.json declaring settings (colour, image, text, select, toggle) rendered under /admin/appearance and merged over BrandSettings — the three brand colours, name, short name and logo are the base every theme inherits. Resolution is child → parent → core; a missing template in a child falls through, so a theme can override one partial. The default theme reproduces the current interface and ships as themes/default/; other themes are installed from a zip with the same checks as plugins. A theme whose functions.php throws at load is switched back to the default with the same notice a plugin gets. Template files are PHP and are rendered with the View's escaping helpers in scope; document the variables each core template receives in THEMES.md, since that is a theme author's whole API.
Database

Port the schema in Appendix B model for model. Naming is snake_case for tables and columns (MySQL identifier case depends on the filesystem; don't gamble on quoted PascalCase), and the import tool below maps names. Rules:

    String @id @default(cuid()) → VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin.
    DateTime → DATETIME(3), always UTC; @updatedAt → ON UPDATE CURRENT_TIMESTAMP(3); @db.Date → DATE.
    Enums → VARCHAR(32) with the allowed values enforced in PHP (MySQL ENUM alterations rewrite the table).
    Video.source becomes videos.provider VARCHAR(32) (bunny, youtube, vimeo, dropbox, gdrive, onedrive, archive, s3, direct, host, or a plugin's id); external_id is whatever identifies the video at that provider (Bunny guid, YouTube id, S3 key, a hash of a direct URL); provider_data JSON holds the rest (the direct URL, thumbnail and caption keys, Dropbox path, Vimeo privacy, renditions). The unique index stays (provider, external_id); the importer maps BUNNY/YOUTUBE/VIMEO and bunnyVideoId onto it.
    Json → JSON (MariaDB aliases it to LONGTEXT with a validity check; fine).
    String[] (Category.tags, Series.tags, Video.scriptureRefs, PermissionGroup.capabilities, ApiKey.scopes) → a JSON column and, where the array is queried, a join table kept in step by the module that writes it: series_tags(series_id, tag) for /tags/[tag] and search, video_scripture_books(video_id, book) for /scripture/[book]. EventSeries.excludedDates (DateTime[] @db.Date) → JSON.
    Text columns that hold markdown, transcripts, page text or lyrics → MEDIUMTEXT; coverDataUrl → MEDIUMTEXT.
    Case-insensitive comparisons come from the collation (utf8mb4_unicode_ci, which both engines have); normalizeEmail() still lowercases before write and compare so the unique index on authorized_emails.email means what it means now.
    Foreign keys with the same onDelete semantics as the schema (Cascade, SetNull), declared explicitly.

Postgres-only constructs and their replacements:

    Trigram fuzzy search (pg_trgm, similarity()): FULLTEXT indexes on series(title, description), videos(title, description, transcript), speakers(name), categories(name), queried in natural-language mode alongside the existing LIKE '%q%' substring pass, and — only when the exact pass returns nothing, as now — a PHP re-rank of at most 500 candidate titles by similar_text/levenshtein, which is how the app worked before the trigram migration. Test that "chruch" finds "Church".
    WITH RECURSIVE for categoryChainIds: load the category table (tens of rows) once per request and walk parent_id in PHP, which getPluginStates() already does; keep the depth cap.
    UPDATE … RETURNING for the API-key rate limit: a transaction with SELECT … FOR UPDATE, the window roll-over in PHP, UPDATE, commit. Still one atomic decision per request; test it with concurrent requests.
    gen_random_uuid() in two data migrations → ids generated in PHP.
    SELECT … FOR UPDATE works unchanged on InnoDB; keep it.
    Prisma's P2002/P2025/P2003 mapping → PDO SQLSTATE 23000 (duplicate, foreign key) and affected-row checks, answering the same 409/404/400.

Migrations are numbered files under app/Migrations/ (0001_init.sql, 0002_….php where data needs code), applied by Migrator inside the installer, /admin/update, and bin/migrate.php; schema_migrations holds the file name and a checksum. Each file must complete within one request on a slow host or split itself; MySQL's DDL is not transactional, so a file that fails halfway must be re-runnable (IF NOT EXISTS, checks before ALTER). Both MySQL 8 and MariaDB 10.6 run every migration in CI.

Data import from a running Next.js deployment is a deliverable, not an afterthought: write tools/export-from-nextjs/export.mjs in this repository — a Node script run from a laptop with the old deployment's direct Postgres connection string, using pg rather than Prisma, reading the tables Appendix B names (every table to newline-delimited JSON in a zip, ids and timestamps verbatim, no secrets that don't transfer — push subscriptions and television tokens are dropped; share-link password hashes are kept in their scrypt$salt$key form, which the port verifies with a small vendored pure-PHP scrypt (RFC 7914, Node's defaults N=16384, r=8, p=1 — PHP has no native standard scrypt) and rehashes with password_hash on the first successful unlock, while new share passwords use password_hash from the start) and /admin/tools/import in the port that reads it in resumable batches, maps table and column names, converts arrays, and reports counts per table against the export's manifest. Files in Bunny Storage need no move if the Files slot is Bunny; the importer offers to pull them to local disk in batches otherwise. Auth0 users keep their identities; the importer creates no local passwords, so those members either keep signing in through Auth0/OIDC or use "forgot password" once local is enabled.
Running on shared hosting

Scheduled jobs. The eight crons in Appendix F plus the prunes become named jobs in a jobs table (name, interval, next_run_at, last_run_at, last_status, last_error, lock_until), each a callable with a time budget that stops starting new work at 20 seconds and marks what it finished, exactly as /api/cron/transcribe does today. Two triggers, both hitting /cron/run?token=<cron token>:

    a real cron the installer prints for cPanel/Plesk (curl -fsS "https://…/cron/run?token=…" >/dev/null every five minutes; wget and the bin/cron.php form too);
    page-view triggering, WordPress-style, when no real cron has been seen for 15 minutes: at the end of a page request, if any job is due, fire a non-blocking loopback request to the same URL (fastcgi_finish_request when available, a short-timeout socket otherwise) and let it run out of band. A lock_until claimed with a conditional UPDATE stops two triggers running one job at once.

With no cron token configured, /cron/run answers 503 and runs nothing. The installer generates the token, so only a broken install ever sees this — and a broken install must not be a public job runner. A wrong token is a 401, compared in constant time. Security requirements says why.

/admin/jobs shows each job, when it last ran, whether a real cron is detected, and a Run now button. Daily is no longer a platform limit, so the default intervals are what the feature wants (status sync every 15 minutes, digests once a day at the configured hour, transcription every 10 minutes with its budget) — and the transcription docs stay honest that a 30- second budget per run means a long sermon takes several runs, with the RUNNING stale sweep unchanged.

Long work stays browser-driven where it is today (broadcast sending, book text indexing, cover generation, feed sync from the admin button) and the cron path is the backstop.

File streaming. /api/files/[id]/content remains the only URL for an uploaded file and re-runs canViewFile per request. Local disk: serve with Range support in 512 KB chunks with output buffering off, X-Sendfile / X-Accel-Redirect / X-LiteSpeed-Location when the installer detects support, Content-Disposition per ?download=1, and the books' private, no-cache plus ETag/304 behaviour. Bunny Storage: when the pull zone has token authentication, redirect to a URL signed for ten minutes (and to the client IP when the option is on) rather than proxy — on shared hosting, bandwidth through PHP is the thing to avoid; without token auth, proxy with Range forwarding and show a warning on Admin → Services that files are being served through PHP and why. The public podcast zone logic (podcast-mirror.ts) ports as-is under the Bunny backend, and for local disk the podcast enclosure is the app route, as it is when the zone is unset now.

Uploads through PHP (files under the Files slot, thumbnails, branding logo, plugin zips): chunked from the browser in slices no larger than half the measured upload_max_filesize, assembled under storage/tmp/<upload id>/ with a manifest, finalised in one request, and swept after a day. For Bunny Storage, a finalised file is pushed with a streaming PUT (curl CURLOPT_INFILE) so it never sits in memory; if the push can't finish inside the request budget it is queued and completed by a job, and the admin sees "uploading to storage" until it is. The existing "import from Bunny Storage" flow remains the route for files uploaded via Bunny's own tools.

Web Push uses minishlink/web-push; the requirements page reports whether bcmath/gmp are present and the Push settings group refuses to enable without them. The daily digest, PendingNotification, and the inbox rows are unchanged.

Base path and HTTPS: one function builds every absolute URL from the configured base URL; the .htaccess RewriteBase is written by the installer; X-Forwarded-Proto is trusted only when configured. Cookies are scoped to the base path.

Query Monitor ports as a debug bar over the PDO wrapper: query count and time, per-query list, render time, peak memory; gated by the config flag and the database switch, shown only to ADMIN.
Frontend

Port every page's behaviour, including the parts the README calls out as deliberate: generateMetadata gating (a page for content the viewer can't see gets a generic title and no image), JSON-LD VideoObject and BreadcrumbList, slug aliases with permanent redirects, sequential unlock, premieres, ?t= timestamp links, the view-event beacon with its 30-minute cookie throttle, the watch-progress heartbeat, the bottom bar with its per-device tab choice and localStorage snapshot, the theme init script before first paint, the standalone (installed PWA) chrome, the locale cookie plus Accept-Language parsing with quality weights, and the Spanish catalogue. Lang/en.php and Lang/es.php return arrays whose keys you define — the original catalogues are not provided, and Appendix A's "Languages" section says which screens are translated; a test asserts the key sets are identical and every {placeholder} survives translation, which is what the type system did for free.

The reader (pdf.js, epub.js), presenter, hymnal grid, contents editor, book text reader (tesseract.js pointed at the vendored /tesseract path), offline books/hymnals/services/calendar, and the offline shell keep their storage formats; offline.html needs only its endpoint base path made configurable.
Feature inventory

Everything in the current app, in port order, with the module names Appendix A uses when it describes each area — vocabulary, not files to open. "Core" is in app/Modules/; everything else is a bundled plugin.
Area 	Public routes 	Admin routes 	Names Appendix A uses
Library (core) 	/, /categories/[slug], /series/[slug], /videos/[slug], /tags/[tag], /speakers, /speakers/[slug], /scripture, /scripture/[book], /search, /recently-added, /feed.xml, /series/[slug]/podcast.xml, /sitemap.xml 	/admin, /admin/categories, /admin/series, /admin/videos, /admin/files, /admin/speakers, /admin/trash, /admin/media-check, /admin/home-rows 	content.ts, bunny.ts, video-source.ts, download-source.ts, podcast-mirror.ts, slug.ts, reorder.ts, drafts.ts, cover.ts, seo.ts, json-ld.ts, content-language.ts
Access (core) 	/auth/*, /access-denied, /link 	/admin/users, /admin/authorized-emails, /admin/access-attempts, /admin/permissions, /admin/audit, /admin/api-keys 	current-user.ts, authorization.ts, identity-linking.ts, permissions.ts, capabilities.ts, audit.ts, api-keys*.ts, api-v1.ts, no-secrets.ts
Site (core) 	/api/manifest, /api/locale, /profile, /profile/settings, /profile/inbox 	/admin/branding, /admin/plugins, /admin/analytics, /admin/query-monitor, /admin/video-feeds 	branding.ts, i18n/, nav.ts, nav-tabs.ts, device-settings.ts, standalone.ts, inbox.ts, profile.ts, data-export.ts, video-feeds.ts, video-feed-sync.ts, query-monitor.ts
Member plugins 	/favorites, /watch-later, /playlists, /playlists/[id], /subscriptions, /recently-played, /s/[token], /share/*, /profile/shared-links, /profile/downloads, /directory (under the profiles plugin; opt-in from /profile/settings via PATCH /api/profile) 	/admin/comments, /admin/announcements, /admin/webhooks, /admin/share-links, /admin/downloads 	plugins.ts, share-links.ts, share-access.ts, share-password.ts, downloads.ts, download-platform.ts, push.ts, webhooks.ts, outline.ts, directory*.ts
Live (plugin) 	/live, /api/live/* 	/admin/live 	live-chat.ts
Books, hymnals, services (plugins) 	/books/[fileId], /read/[fileId], /hymns/[fileId], /present/[fileId], /services, /services/[id], /profile/rota, /api/offline/*, /api/hymnals/search, /api/hymns/lookup 	/admin/services, /admin/services/report, /admin/teams 	hymnal.ts, book-contents.ts, page-offset.ts, reader*.ts, toc-nav.ts, verses.ts, services.ts, rota.ts, offline-*.ts, fingerprint.ts, ocr-client.ts
Schedules (plugin) 	/calendar, /api/schedules/*, /api/calendar-events, /api/sync/snapshot, /api/people (the dates for anyone, the names for members: the page and the event and snapshot endpoints answer a signed-out reader with events that have nobody on them, /api/people is a 403 without a session), /api/calendar/[token]/marine-team.ics, /api/profile/calendar 	/admin/schedules, /admin/schedules/[id], /admin/people 	schedules/ (visibility.ts and viewer.ts decide who sees names), sheets/, calendar-feed*.ts, ics.ts, names.ts
Events, forms, prayer, groups, broadcasts (plugins) 	/events, /events/[slug], /events/calendar.ics, /events/[slug]/event.ics, /forms, /forms/[slug], /prayer, /groups, /groups/[slug] (with the group's thread, /api/groups/[slug]/messages, and its roll, /api/groups/[slug]/meetings), /guides, /guides/[slug], /profile/events, /profile/groups 	/admin/events, /admin/forms, /admin/prayer, /admin/groups, /admin/broadcasts, /api/admin/guides (gated by manage_events; the original has only the API, so give it an /admin/guides page) 	events.ts, event-series*.ts, recurrence.ts, forms*.ts, prayer*.ts, groups*.ts, attendance*.ts, guides*.ts, group-messages*.ts, broadcast*.ts, sms*.ts
Television (plugin) 	/tv, /link, /profile/devices, /api/tv/* 	— 	tv-pairing.ts, tv-session.ts, tv-feed*.ts, tv-nav.ts
Read API (core) 	/api/v1/* 	/admin/api-keys 	api-v1.ts, api-keys-query.ts

Keep every invariant Appendix A argues for. A non-exhaustive list of the ones that are easy to lose in a rewrite: bylineFor as the only place a prayer author's name leaves; presentGroup as the only place an address travels; memberOnly filtered on the video and its series in the television feed; assertNoSecrets on the data export and the read API; the podcast publicPath written after copy and cleared before delete; claimToken as a conditional update; the three-way compare in feed sync; revoke never gated by the share-links plugin; the heartbeat never un-completing a video; Serializable avoided in favour of row locks; promotion stopping at the first party too big to fit; consent rules in planDelivery. From the four newest features: the attendance roll reaching a member as a list of at most one row — their own — never a flag or a count, and reaching nobody outside the group; apologies a status of its own, never a shade of absent; one meeting per group per day under a unique index, so two leaders opening the form at once can't make two half-rolls; a roll refused for an evening that hasn't happened, and only current members markable, checked against the database rather than the form; presentGuide leaving leaderNotes off a member's shape rather than sending it empty, and notes readable by whoever leads any group without a capability; inTheThread re-read from the database on every request, a site manager outside the group getting nothing from it, a hidden message dropped in the query and in the filter, mute keeping somebody in the group, and thread notifications carrying the first line only, to active members minus the author and the muted; directory listing off by default with each contact detail its own separate yes, leaving the directory clearing those flags, search never matching a contact detail even a published one, and the page noindex behind sign-in; a member's own group messages in their data export, taken-down ones labelled as such. And from the last change before this document was pinned, rota names are for members: the schedules module came from an app built for people who never log in and published every volunteer's name beside the days they are at the building, while the directory next to it needed opt-in and sign-in for a name to appear. The structure stays public — which rotas, what days, what notes — and the people need a sign-in. visibleEvents/visiblePeople hand a signed-out reader events with nobody on them rather than names to hide, the same optional-field shape the group address uses; /api/people is a 403 without a session; a personId filter is refused signed out ("which days is this id on" is "who is this", sideways); and a signed-out offline sync is always a full, nameless snapshot, so a copy saved on a shared laptop while somebody was signed in is replaced on its next update rather than kept. Choosing your name on the calendar therefore needs a sign-in; the per-device preference still works once there is one.
Security requirements

Shared hosting changes the threat model: the app shares a machine, and often a database server, with strangers; the administrator has no shell; an upload is the deployment path; and a site is often reachable on a temporary hostname before anyone has told the congregation about it. Each item below is a requirement with a test where one is possible, and the security pass in the Work plan walks every route against this list.

Installer and configuration

    The installer is reachable by anyone who finds the site before it is installed, so it requires proof of file access: on its first hit it writes storage/install.key (32 random bytes, hex) and asks for the contents, which only the host's file manager or FTP can show — the same access as owning the site. installed.lock closes it. A config.php with no lock (a half-finished install) shows the key prompt again rather than resuming.
    storage/ must not be web-readable. The installer writes a probe file and fetches it over HTTP; if it is served, the wizard refuses to continue until the directory is moved above the document root or the rewrite denies it (.htaccess on Apache; the nginx docs give the location block). Every directory the app writes to also carries an index.php that exits, against AllowOverride None, and Options -Indexes.
    storage/config.php holds the database credentials and app_key; the services table holds provider secrets encrypted with app_key (AES-256-GCM through sodium or openssl, a random nonce per value, the key never in the database). Secrets are write-only in every form and are never echoed, exported, logged, or written in the clear into a backup.
    Debug mode is off by default; display_errors is forced off at bootstrap whatever php.ini says; no response carries a stack trace, a query, or a file path to anyone but an ADMIN with debug on.
    The table prefix is validated as [a-z0-9_]{1,16}, and no SQL identifier is ever built from request input.

Sessions, CSRF and headers

    Session ids are 32 random bytes and the database stores their SHA-256, so a database read does not yield a usable session. Ids are regenerated on login and on any privilege change; lifetimes are 30 days absolute and 7 days idle; "sign out everywhere" deletes the member's rows. The cookie is HttpOnly, SameSite=Lax, Secure on HTTPS, scoped to the base path, and carries the __Host- prefix when the site sits at a domain root over HTTPS.
    Every state-changing request carries the session's CSRF token (form field or X-CSRF-Token), compared in constant time, with an Origin / Sec-Fetch-Site check as defence in depth. GET never changes state. The only exemptions are bearer-authenticated /api/v1, /cron/run, and the signature-verified webhook receivers.
    returnTo and every other post-action destination are accepted only as a relative path under the base path; a scheme, a leading //, or a backslash falls back to /.
    Headers on every response: X-Content-Type-Options: nosniff, Referrer-Policy: strict-origin-when-cross-origin, X-Frame-Options: SAMEORIGIN (the presenter and television screens included), a Permissions-Policy granting only what the player needs, Strict-Transport-Security only after the admin confirms HTTPS is permanent, and a Content Security Policy with a per-response nonce for the two inline init scripts and the branding <style>; plugins and themes get the nonce from View::nonce(). Providers declare the origins they need through cspSources() — ClerkJS, Firebase, the YouTube and Vimeo frames, the Cast SDK, TUS endpoints, S3 hosts — so the policy is assembled from what is active rather than opened wide.

Accounts and sign-in

    Passwords: Argon2id where available, bcrypt at cost 12 otherwise; at least 12 characters, checked against a short list of the commonest; no maximum under 200. Reset and magic-link tokens are 32 random bytes, stored hashed, single-use, bound to one account, expiring in 60 and 15 minutes. A request for an unknown address answers identically and takes the same time.
    Login, reset, magic link, share-link unlock, television pairing and API-key authentication are throttled per account and per IP in the database with exponential backoff; these counters are among the ones tested under concurrency.
    Changing a local account's email sends a verification to the new address and a notice to the old one, and applies only when the new one is verified. A provider-side change that decideLinking would rename a member onto is held the same way.
    Only an ADMIN grants ADMIN; no permission group can carry it; manage_users cannot promote. Role and allowlist are re-read per request.
    JWT verification, for Auth0, OIDC, Clerk, Supabase and Firebase alike: the accepted algorithms are pinned per provider — never none, and never both an HMAC and an asymmetric algorithm for one issuer — and iss, aud or azp, exp, nbf and iat (60 seconds of skew) are checked, plus nonce on ID tokens. JWKS is fetched over TLS, cached, and refreshed on an unknown kid at most once a minute. The token flow's POST to /auth/token carries the CSRF token, so a third party cannot log a victim into the attacker's account.
    Supabase's service-role key, Clerk's secret key and every OAuth client secret are server-only; the browser sees only anon keys, publishable keys and client ids.

Requests the server makes

    Everything that fetches a URL somebody typed — the direct-link, Dropbox, Google Drive, OneDrive and Internet Archive providers, the JSON SMS webhook, the transcription URL, the Webhooks plugin, a cover image URL if it is ever fetched server-side — goes through one Http::fetchUntrusted(), which resolves the host itself, refuses loopback, private, link-local and cloud metadata ranges (169.254.169.254 included) on every hop of a redirect, allows only http and https, caps the body it reads, and times out in ten seconds. Provider-specific calls use fixed hosts and never take a host from input. The cron loopback request goes to the configured base URL only.
    Web Push subscriptions are URLs the browser hands the page and the page hands the server, and the server then POSTs a signed body to every one of them on every notification. Accept only https: endpoints whose host is one of the browsers' push services — fcm.googleapis.com, android.googleapis.com, push.services.mozilla.com, notify.windows.com, push.apple.com, push.samsungosp.com, matched on a label boundary, with a setting that adds a suffix for a browser not on the list — and no more than eight per member, the oldest evicted when a ninth arrives. Http::fetchUntrusted()'s rules apply to the send as well. The original accepted any URL and capped nothing: one member could make the server POST wherever they liked, a thousand times per notification.

Files and uploads

    Every upload is typed by finfo on its bytes, never by extension, against an allowlist per purpose: images JPEG, PNG, WebP, GIF; documents PDF and EPUB; audio MP3, M4A, OGG; captions VTT and SRT; video MP4 and WebM. SVG is not an image here — it can carry script, and the branding logo is on every page. Images are re-encoded through GD when it exists; without it they are served with nosniff and, outside an <img>, as attachments.
    The type stored and the type served both come from that allowlist and never from the request: the extension and the bytes must agree with one entry or the upload is refused (415), and the object is stored as <random id>.<ext> with nothing of the client's name in the path. On the way out, Content-Type is decided from the stored extension alone — never from a stored MIME string, which for an imported object is whatever a dashboard was told. Only PDF, EPUB, audio and raster images may be served inline; documents are attachment; anything not on the list at all — including a file that predates the rule — is application/octet-stream, attachment, nosniff, with Content-Security-Policy: sandbox, the combination a browser refuses to interpret. The original shipped a version that trusted the browser's file.type and served it back inline: a series editor could upload notes.html as text/html and have it run as the site in the browser of any admin who opened the link. That is the finding this bullet exists for.
    Images are decoded by GD once, at upload, after getimagesize has read the dimensions from the header and refused anything over 40 megapixels — never again on request, and never from a URL. The original's image optimizer route decoded whatever same-origin path it was handed, uploads included, and carried a critical advisory for it; the port has no such route, and <img> tags point at the stored file.
    Stored names are random; the original name lives only in the database and is sanitised before it becomes a Content-Disposition filename (no CR, LF, quotes or path characters). A Range request may name one range. X-Sendfile paths are built from the stored name, never from input.
    Nothing under storage/uploads, public/media, plugins/*/assets or themes/*/assets executes: an .htaccess there turns the PHP handler off (php_flag engine off, RemoveHandler, SetHandler default-handler), the asset route refuses .php, .phtml, .phar and dotfiles, and the nginx docs say the same.
    Chunked uploads are addressed by a random id, assembled only under storage/tmp/<id>/, size-capped by the admin's setting and swept after a day; .. and absolute paths are refused by construction.
    Plugin and theme zips: refuse entries with .., absolute paths or symlinks, and archives over 10,000 files or 200 MB uncompressed (zip slip and zip bombs); extract into storage/tmp, verify the manifest, then move. A plugin is code and runs as the site; only manage_plugins installs one, and the page says so.
    Release zips uploaded through /admin/update are signed: the maintainer's Ed25519 public key ships in the app, the archive's checksum is signed at build, and sodium_crypto_sign_verify_detached rejects anything else before a file is touched.

Data

    Prepared statements everywhere; LIKE arguments have % and _ escaped; ORDER BY columns and directions come from allowlists; FULLTEXT queries are stripped of boolean operators.
    Every POST and PATCH body is validated against an explicit field allowlist per route — the port of the zod schemas; unknown fields are dropped, never spread into an update.
    Templates escape by default; the raw helper is the only exception and CI greps for it. Email bodies are escaped the way email.ts does, and mail() and SMTP reject CR or LF in any address or subject.
    Logs mask Authorization headers, cookies, tokens, passwords and API keys before writing; /admin/logs is ADMIN only.
    Backups are written under storage/tmp with a random name, streamed, and deleted after download; the services rows keep their secrets encrypted in the dump.
    The data export and /api/v1 keep assertNoSecrets; the directory, prayer, small-group, attendance, thread and rota-name rules under Feature inventory are security rules and are tested as such.

Abuse

    Public write endpoints — forms, event sign-up, prayer requests, comments, live chat, share-link unlock, the registration check — are rate-limited per IP and per account in the database, with a honeypot field on the public forms; a CAPTCHA (Turnstile or hCaptcha) is an optional integration, off by default.
    Inbound SMS and delivery-receipt webhooks verify signatures, reject timestamps older than five minutes, and are rate-limited.
    The page-view cron trigger fires at most once a minute per install and holds a database lock, so it cannot be used to make the site hammer itself.
    Scheduled jobs fail closed: /cron/run with no cron token configured answers 503 and runs nothing, and a wrong token is a 401 compared in constant time. The original's eight cron routes each checked if (secret && …), which with the variable unset ran every job for anybody — transcription (paid per call), broadcast sends, feed syncs — so a preview environment without the variable was a public job runner. Never if (token) { check }.
    View counts throttle on the server, not only in a cookie: an HMAC of the caller's address under app_key, one count per address per item per thirty minutes, the key column blanked by the daily job after a day so it is a throttle and not a record. An id that doesn't exist is a 404, never a foreign-key 500. A cookie the caller sets on itself was the whole throttle once; a script simply doesn't send one.
    Small-group asks: ten per member per hour, and what an ask pages the leaders with is capped per leader per group per hour — a member who asks, withdraws and asks again leaves no row behind to count, so the cap is on the notifications. Search inputs are capped: 100 characters on the site search, whose similarity query runs over every published row, 200 inside a book.

Dependencies and process

    Vendored libraries are pinned with checksums recorded in MANIFEST; CI runs composer audit and fails on a known vulnerability; the release build records every version.
    The security pass in the Work plan walks every route against this section and records the result per route in PORT_MAP.md.

Testing and CI

    PHPUnit unit tests for every pure module: Appendix D lists the original suite's cases file by file. Write each as a PHPUnit test with the same name before the code it tests, then the code until they pass.
    Integration tests against real MySQL 8 and MariaDB 10.6 in GitHub Actions service containers: migrations from empty, every /api/* route's status codes and shapes, the row-lock scenarios under concurrency (two registrations for the last place, two television polls, two cover takers), the API-key limiter, the allowlist revocation on an existing session.
    A shared-hosting smoke test in CI: a Docker image of Apache + PHP 8.2 with disable_functions=exec,shell_exec,proc_open,popen,system,passthru, open_basedir, upload_max_filesize=2M, max_execution_time=30, no Composer, installed from the built release zip by HTTP only. It drives the installer with a headless browser, activates every bundled plugin, creates a category/series/video/file, installs a test plugin that throws on load and asserts the site stays up and the plugin is marked deactivated with the reason, installs one that exhausts memory on load and asserts the same, switches email from mail() to a Mailpit SMTP with a failing then a passing test and asserts only the second commits, and runs /cron/run.
    Video providers are tested against recorded HTTP fixtures for Bunny, YouTube, Vimeo, Dropbox, Google Drive, OneDrive and Internet Archive (no live accounts in CI); end to end against MinIO for the S3-compatible provider — presign, a browser PUT that proves CORS, presigned GET playback; and for real on the host's own disk, including a chunked upload through the 2 MB limit and a members-only stream answering Range requests.
    Sign-in, email and SMS providers likewise: fixtures for Auth0, Clerk, Supabase, Firebase, every email API, and every SMS API together with each provider's signed status callback and a stop-word reply; the OIDC provider end to end against a Dex or Keycloak container in the smoke test, including a preset; the token flow end to end with a JWT minted by the test against a local JWKS; SMTP against Mailpit; and the trial-mode switch proven by a test that fails to complete the second-tab login and asserts nothing changed.
    Security tests, one per item under Security requirements where a test is possible: the installer refuses without the install key and refuses while storage/ is web-readable; a state-changing request without the CSRF token is refused; returnTo with a scheme or // lands on /; a JWT with alg: none, a wrong aud, an expired exp, or an HMAC signature under the asymmetric issuer is refused; a session id read from the database cannot be presented as a cookie; a plugin zip with ../ in a path, a symlink, or a .php under assets/ is refused or not executed; an SVG or a PHP file disguised as a JPEG is refused; a direct link to 127.0.0.1, 10.0.0.1, 169.254.169.254 or a host that redirects to one is refused; % and _ in a search do not act as wildcards; the security headers are present on a page, an API response and a file response; a mail() subject with CR LF is refused; and a log line written during a failed login contains no password.
    Static checks: PHPStan level 6 or higher, PSR-12 via PHP-CS-Fixer, php -l across the tree on 8.2/8.3/8.4, a grep that fails on the banned process functions, and a check that no template echoes an unescaped variable outside the explicit raw helper.
    The release zip is built in CI (vendor/ included, dev tools excluded, storage/ empty with the right permissions, a MANIFEST with checksums) and is what the smoke test installs.

Work plan

The current code is large enough that "port it" is a programme, not a task. Work in this order, and keep docs/PORT_MAP.md current from the first commit: every page, route, model and lib module from the inventory above with its port status (todo, partial, done, dropped: reason), so any later session can pick up where this one stopped without re-deriving the state.

    Read this document to its end, appendices included. Write PORT_MAP.md with every page and route from Appendix C, every model from Appendix B and every area from the inventory above at todo. Commit it before writing PHP.
    Foundation: core (router, DB, view, session, CSRF, hooks, cache, log, errors), the full schema as 0001_init.sql, the migrator, the installer, local sign-in, users and capabilities, the admin shell, branding, i18n, the services registry with the Files slot on local disk and Email on mail(), jobs with both triggers, the plugin and theme loaders with the auto-deactivation paths and their tests, the default theme skeleton.
    Library: categories, series, videos — the Bunny Stream provider with its browser upload first, then the link providers (YouTube, Vimeo, Dropbox, direct) and the player module for both kinds; S3-compatible and the host's own disk once the chunked uploader exists — files, search, trash, audit, permissions and scoped grants, viewer restrictions, share links, downloads, feeds, sitemap, metadata. Then the remaining sign-in providers (Auth0, OpenID Connect and its presets, Clerk, Supabase Auth, Firebase), the remaining email providers, and the Bunny Storage files provider.
    Bundled plugins, simplest first (favorites, watch-later, view-counts, social-share, ratings, likes, related, up-next, watch-history, profiles, chapters, transcripts, recommendations, announcements, webhooks, notifications, subscriptions, playlists, sermon-notes, share-links, downloads), each proving the hook API by needing nothing else.
    The rest: book reader and hymnals, service plans and rota, schedules and sheets, events and series, forms, prayer, groups, broadcasts and SMS, live streaming and chat, television, the read API, the data export and import.
    Hardening and docs: the smoke test green, the Security requirements checklist walked route by route and recorded in PORT_MAP.md, INSTALL.md written for a volunteer with screenshots' worth of detail and a "Locked out" section, PLUGINS.md, THEMES.md, UPGRADING.md, and the migration guide from a Vercel deployment.

Commit small and often on the branch you are given, with messages that say what changed and why. When a decision here turns out to be wrong against the code you meet, say so in PORT_MAP.md under "Deviations" with the reason, and carry on — don't stall on it.
Definition of done

    A fresh zip installs on the smoke-test image by HTTP alone and every bundled plugin activates.
    Sign-in, Video, Email and Files are each switchable from Admin → Services, every switch is preceded by its provider's test, a failing test refuses the switch, and an admin cannot switch sign-in into a state they can't sign in from.
    Every core video provider does what its row in the Video table says: upload where it can, link by pasted URL where it can, both player kinds resume and report progress, downloads and Cast answer with the right reason, and a members-only video on a provider that can't enforce it says so where the admin sets the flag.
    Every core sign-in, email and SMS provider passes its fixture tests and its test-before-switch, and SERVICES.md has a row for each provider in every slot saying what it can't do as plainly as what it can.
    A plugin that throws, parse-fails, or exhausts memory on load is deactivated automatically with the reason visible; the site stays up; a plugin throwing in a hook is contained; /admin/plugins is reachable with every third-party plugin broken.
    A theme installs from a zip, overrides one template, and a broken theme falls back to the default.
    Every URL in the inventory answers; offline.html, the service worker, the television feed, the podcast feed, the .ics feeds and /api/v1 are byte-compatible with the current app's output for the same data.
    The export from the Next.js deployment imports and the counts match.
    CI is green on MySQL 8 and MariaDB 10.6, PHPStan passes, the banned-function grep is clean.
    Every item under Security requirements has a passing test or a recorded manual check, and the route-by-route security review in PORT_MAP.md has no open rows.
    PORT_MAP.md has no todo rows, and every dropped row has a reason a maintainer would accept.

Non-goals

Do not build a Node runtime dependency, a JavaScript bundler step, a Docker requirement for production, a queue server, WebSockets, a second ORM, or a Vercel-shaped deployment. Do not proxy video through PHP, other than the host-disk provider's members-only files, which is that provider's documented cost. Do not keep pooled and direct database URLs, force-dynamic, React cache(), Prisma, or any other artefact of the platform this is leaving; keep the reasons they existed where those reasons still apply.
Appendices
Appendix A — Feature specification

Two documents from the original repository, verbatim except that their headings are nested one level deeper. They describe the Next.js implementation: where they say Postgres, Prisma, Vercel, an environment variable, React or cache(), the instructions above say what replaces it, and where they name a source file, see the note at the top of this document. Their rules are the port's rules.
A.1 — README.md
Marine Team (original README)

What a church runs its week on. It began as a Subsplash-style media library — Auth0 login, an admin CMS over series, categories, videos and files, video on Bunny Stream — and that is still the middle of it. Around that now: hymnals you can search by number even when they are scans, a service's running order with the rota beside it, two kinds of volunteer schedule, events people sign up for, forms, a prayer wall, small groups, announcements by email and text, Spanish, and a screen for the television.

Almost all of it is optional. Every feature past the library is a plugin an admin switches on at /admin/plugins, so a church that wants a video site gets a video site.

See FEATURES.md for the full feature list and CHANGELOG.md for release history.
Optional services

None of these is needed to run the app; each switches a feature on, and the screen that needs it says which variable is missing rather than failing quietly.
What it does 	Wants
Email (notifications, announcements) 	RESEND_API_KEY, EMAIL_FROM
Text messages 	TWILIO_ACCOUNT_SID + TWILIO_AUTH_TOKEN + TWILIO_FROM, or SMS_WEBHOOK_URL
Web push 	VAPID_PUBLIC_KEY, VAPID_PRIVATE_KEY
Automatic transcription 	TRANSCRIBE_API_URL
Importing from YouTube / Vimeo 	YOUTUBE_API_KEY / VIMEO_ACCESS_TOKEN
Rotas from a spreadsheet 	a Google service account (see the Schedules section)
Scheduled jobs 	CRON_SECRET
How it works

    Public site (/, /categories/[slug], /series/[slug], /videos/[slug], /search): browse published categories/series as a vertical list of tiles (thumbnail, title, item count), watch videos (embedded Bunny Stream player), download files. Content flagged memberOnly requires login. Categories can nest arbitrarily deep (a category's children can themselves have children) via Category.parentId — the homepage shows top-level categories and any uncategorized series as tiles, each category tile linking to a /categories/[slug] page that shows that category's own series plus a tile for each child category, recursively. The search box in the navbar (and the /search page) does a case-insensitive substring search across category names, series titles/descriptions, and video titles/descriptions, falling back to a typo-tolerant fuzzy title match when that finds nothing.

    Ordering: every list (categories, series, videos, files) has a position field. The admin CMS shows ↑/↓ buttons next to each item to reorder it among its siblings — categories among the same parent, series within the same category, and videos/files within the same series — which is what every public listing sorts by.

    Auth: /auth/login, /auth/logout, /auth/callback are handled automatically by the Auth0 SDK's proxy.ts (Next.js 16 renamed Middleware to Proxy, and Proxy runs in the Node.js runtime, so Prisma works there). Access is decided from two independent checks — by default both must pass, but AUTHORIZATION_MODE (below) can relax that to either one alone:
        Membership of an approved Auth0 organization. AUTH0_ORGANIZATION_ID is a comma-separated list of accepted org ids. With exactly one configured, src/lib/auth0.ts sends it as organization on the authorization request, so Auth0 refuses non-members at the identity provider — a personal Google account never reaches the callback with a usable token. With two or more configured, that parameter is left out instead, so Auth0's own organization prompt shows and the member picks which one they're signing in to (requires "Prompt for Organization" on for this Application in the Auth0 dashboard). Either way, isOrganizationMember() re-checks the org_id claim of the verified ID token server-side against the same list. The parameter we send — or the choice made at Auth0's prompt — is a request; the claim is the proof. Membership is never read from anything the browser controls, and the check fails closed if AUTH0_ORGANIZATION_ID is unset.
        An ACTIVE AuthorizedEmail row in PostgreSQL, managed at /admin/authorized-emails ("Who can sign in"; the Grant/Revoke buttons on /admin/users, "Members & roles", write to the same list). Stored trimmed + lowercased behind a unique index, so casing and whitespace can't create a second row or dodge a lookup (normalizeEmail() is the only way an address is ever compared or written).

    AUTHORIZATION_MODE chooses how the two combine: BOTH (default, an AND — neither is enough alone), ORGANIZATION (membership only), ALLOWLIST (the list only), or EITHER (an OR — either is enough alone). Unset or unrecognised resolves to BOTH, and no value turns both checks off entirely. EITHER is the "personal account or organization account" mode: an organization member gets in without an allowlist entry, and someone with no organization still gets in with an ACTIVE entry — it also needs this Application's "Type of Users" set to "Both" (Login Experience tab in the Auth0 dashboard), or Auth0 itself will keep insisting on an organization before our check ever runs. In ALLOWLIST or EITHER mode the app also stops sending organization on the login request — otherwise Auth0 would reject non-members before the app's own check ran, which in ALLOWLIST mode would make it a no-op and in EITHER mode would block the personal-account path entirely. Both check results are still recorded on every refusal whatever the mode, and a relaxed or reshaped mode shows as a banner on /admin/authorized-emails.

    For a one-off guest rather than a deployment-wide policy change, an individual AuthorizedEmail row can be flagged organizationExempt ("Guest" in the UI): that one address gets in on an ACTIVE allowlist entry alone, with AUTHORIZATION_MODE staying at BOTH and everyone else still needing both checks. This is the narrower alternative to EITHER mode — EITHER changes the rule for every allowlisted address at once, an exempt row changes it for one address an admin named. Toggle it per row with the "Make guest" / "Require organization" button on /admin/authorized-emails.

    A guest must sign in via /auth/guest, not the normal Log in button. When an organization is required and configured, /auth/login names it on the authorization request, so Auth0 refuses a non-member at the identity provider — before the callback, and so before the allowlist is ever consulted. /auth/guest (src/app/auth/guest/route.ts) starts the same login with the organization parameter omitted, which is the only way a guest's request gets far enough to be judged on their exempt row. The route grants nothing by itself: authorizeIdentity still decides, and someone without an ACTIVE exempt row is refused exactly as before. It 404s when no organization is required, since the normal login already omits the parameter then. This also needs the Auth0 Application's "Type of Users" set to "Both" (Login Experience tab), or Auth0 insists on an organization even when we stop asking for one.

    /auth/guest also has its own master switch, closed by default: the "Guest sign-in link" toggle at the top of /admin/authorized-emails (backed by the AuthSettings singleton, isGuestLoginEnabled() / setGuestLoginEnabled() — a database row rather than an env var, so opening or closing it takes effect immediately with no redeploy). Closed, the route 404s identically to the "no organization required" case, so its response doesn't reveal that a guest path exists at all; /access-denied only shows the guest link once it's open, so a stuck guest isn't pointed at a dead link. There's no reason to leave an org-skipping login path reachable once the guest who needed it is done — open it while inviting someone, close it after.

    Both run inside getCurrentUser() — the choke point every server-rendered page and API already goes through — so revocation applies to existing sessions: remove an email and that person is refused on their very next request, cookie or no cookie. User.authorized is kept in step with the decision so the older queries that read it stay correct.

    Signup is closed off separately by an Auth0 Pre-User-Registration Action that calls POST /api/auth/registration-check (bearer secret, 5s timeout, fails closed, returns only a boolean). The Action never holds database credentials — see auth0-actions/README.md for the source and the dashboard checklist.

    Every refusal — organization rejection, missing-state callback error, an unauthorized email, or a revoked session — lands on /access-denied with one plain message and no stack trace, Auth0 error, or Prisma detail. Because the SDK writes its session cookie after the onCallback hook returns, src/proxy.ts strips it from any response redirecting there, so no application session exists after a failed authorization. State, nonce, and CSRF validation are untouched.

    Refused attempts are recorded in UnauthorizedAccessAttempt (no tokens, codes, or passwords — only who was refused and why), shown at /admin/access-attempts, and pruned after 90 days by the daily cron. The first refusal for an address emails the admins; the same address is then left alone for an hour and no more than ten notifications go out per hour overall, both counted in Postgres (no Redis anywhere in this app).

    A callback-error attempt (Auth0 refused before the app ever saw an identity) also records detail: the Auth0 SDK's own error code/message, plus — since several error types (an organization rejection among them) leave their own .message at a fixed generic default — the underlying error/error_description Auth0 actually sent back, read from the SDK error's .cause (getErrorCause() in src/lib/auth0.ts). Still nothing but Auth0's own human-readable classification; never a token or secret. Also console.error'd immediately, so it's in Vercel's function logs even before anyone opens the admin page.

    Admin CMS (/admin): manage categories, series, videos, and files. Video upload creates a placeholder in Bunny Stream, signs a TUS upload session, and streams the file straight from the browser to Bunny; small files (≤4.5MB) are uploaded to Bunny Storage via the server. Beyond ADMIN, an admin can grant a MEMBER content-editor access to one category (and everything under it) or one specific series from /admin/users — that scopes their /admin view to just series/videos/ files they're allowed to touch (src/lib/permissions.ts enforces this server-side on every admin API route, not just in the UI).

    Featured/pinned: a series can be marked featured (used for the homepage hero, overriding the recency-based default) or pinned (sorts first in its listing regardless of position) from its edit page or the series/category admin lists.

    Tags: series can have free-form tags, shown as chips on the series page and searchable; /tags/[tag] lists everything with a given tag.

    Scheduled publishing: series/videos/files can have a publishAt timestamp — even if published is true, the item stays hidden from the public site until that time passes. They can also have an unpublishAt timestamp (set from the series edit form, or via "Set expiry" on the video/file admin lists) so time-limited content disappears automatically without a manual unpublish step.

    Bulk actions & filtering: the series/video/file admin lists support multi-select (Publish/Unpublish/Delete) and a title filter box; selected series can also be bulk-moved to a different category (or uncategorized) in one action, and any series row can be recategorized inline.

    Audit log (/admin/audit, needs the view_audit_log capability): an append-only record of admin/editor create/update/delete/grant/revoke actions, exportable as CSV or JSON for offline/compliance review.

    Favorites: logged-in users can bookmark a series or video from its page; /favorites lists everything they've saved. Gated by the favorites plugin (see Plugins below).

    Comments: logged-in users can leave comments on a series or video page; authors can delete their own, admins or moderate_comments capability holders can delete any. Gated by the comments plugin. Any other logged-in member can report a comment (CommentReport, one per member per comment); reported or already-hidden comments surface in /admin/comments (getReportedComments() in src/lib/content.ts, scoped to a moderator's own categories/series via getCapabilityScope() unless they hold a site-wide grant), where a moderator can hide (Comment.hidden, excluded from public reads) or permanently delete.

    Related content: series pages show a "More like this" row (same category, then shared tags); video pages show "More from this series" or "You might also like" for standalone videos. Gated by the related-content plugin.

    Relevance-ranked search: /search and the navbar search box rank results by how well they match — an exact or prefix title match outranks a description-only hit — across categories, series, videos, and speaker names, with optional category/speaker filters and a relevance-vs-newest sort. When the exact pass returns no series (or no videos), a fuzzy fallback re-ranks candidates by Postgres trigram similarity (pg_trgm, via the GIN indexes added in the search_trigram_indexes migration) so a typo like "chruch" still finds "Church". It only runs on an empty result, so the common case pays no extra query.

    Speakers: an admin-managed directory (src/app/admin/speakers) of preachers/presenters, attachable to a video from the video manager. /speakers and /speakers/[slug] list them and their published videos.

    Scripture references: free-form Bible references on a video (e.g. "John 3:16-18"), edited from the video manager's "Scripture" panel and browsable at /scripture and /scripture/[book] (scriptureBook() in src/lib/content.ts derives the book from the leading text of a reference).

    Live streaming (plugin): LiveStream rows point at a stream already hosted elsewhere (YouTube, Boxcast, etc. — Bunny Stream has no live ingest). /live shows the current stream when live, a countdown to the next scheduled one otherwise, and a "Live now" banner appears on the homepage and nav while one is live. Publishing a stream pushes a notification the same way publishing a video does.

    Sitemap: /sitemap.xml (src/app/sitemap.ts, backed by getSitemapData()) lists published categories and series, guest-visible videos, every distinct series tag, every speaker, every distinct scripture book, and /live. It's force-dynamic because the database isn't reachable at build time here, same as the root layout, and it uses APP_BASE_URL for absolute URLs.

    Closed captions: each row of the admin video list has a Captions button for uploading a .vtt/.srt track per language code and removing it later. Captions are stored in Bunny Stream rather than locally (no new Video column, no local copy) via bunnyAddCaption/bunnyDeleteCaption in src/lib/bunny.ts, and Bunny's embed player adds a CC toggle by itself once a track exists. This is separate from the Transcripts plugin, which renders a searchable text panel beside the video instead of subtitles.

    Plugins (/admin/plugins, needs manage_plugins): a WordPress-style list of optional features with a site-wide Active/Inactive toggle, plus per-category overrides — e.g. disable Comments just under "Kids" while leaving it on everywhere else. Nearest-ancestor override wins; falls back to the site-wide default. src/lib/plugins.ts has the plugin registry and isPluginEnabled(). Current plugins:
        Favorites / Watch later: two independent per-user lists (bookmark vs. queue) at /favorites and /watch-later.
        Comments: see above.
        Related content: see above.
        Ratings: a 1-5 star rating on a series/video; average + count shown to everyone, the star row itself is only clickable when logged in.
        View counts: a simple counter incremented on each page load — no dedup/anti-spam, it's a basic "how many hits" number, not analytics.
        Social share: copy-link plus share-to-X/Facebook buttons.
        Announcements (/admin/announcements): a dismissible (per-browser- session) site-wide banner; only the newest active one matching the viewer's login state shows, checked site-wide only (no per-category override — it's a global message). Two optional refinements on top of active: a publishAt/expiresAt scheduling window, and an audience (ALL/GUESTS/MEMBERS) targeting the banner to logged-out visitors, logged-in members, or everyone — getActiveAnnouncement() takes the viewer's login state and is cached per state (guest vs. member), not globally, since the result now differs by audience.
        Notifications: Web Push to subscribed members when an admin flips a video from unpublished to published (see PWA below) — a no-op if VAPID keys aren't configured. Members choose a frequency on /profile: INSTANT (default, unchanged behavior) pushes immediately, while DAILY queues a PendingNotification row per event that the digest cron batches into one push a day (see Deployment below). A member can also opt into User.emailNotifications — a separate, always-instant email channel (src/lib/email.ts, via the Resend API, a no-op without RESEND_API_KEY/EMAIL_FROM) sent alongside push regardless of the INSTANT/DAILY choice, which only governs push timing.
        Subscriptions (/subscriptions): follow a series or category; its subscribers get a targeted push notification when it publishes a new video, on top of the general Notifications above.
        Playlists (/playlists): member-created, reorderable video playlists, separate from the single Watch Later queue. Playlist.public (toggled from the playlist page) lets anyone with the link view it read-only at /playlists/[id] without an account — getPublicPlaylist() in src/lib/content.ts only resolves when that flag is set; otherwise the route falls through to the existing owner-only getPlaylist().
        Likes / dislikes: a thumbs up/down on a series or video, alongside (and independent from) the star Ratings plugin.
        Live streaming: see above.
        Sermon notes: a member's own private, timestamped notes on a video (SermonNote), added from a panel on the video page and exportable as a text file. The timestamp field is prefilled once from the same elapsed-time heartbeat used for Continue watching, then edited freely — it isn't kept in sync with real playback (see the technical notes below).
        Share links: gates creating share links (see below). Revoking is deliberately never gated — turning this plugin off must not trap a member with links they can no longer switch off.
        Downloads: offline viewing (see below).

    Sequential unlock: a per-series "Require watching in order" toggle (Series.requireSequential, set on the series edit page — not a plugin, since it's a property of one series rather than a site feature). When on, a video is locked until the previous one (by position) is marked completed in the viewer's WatchProgress; anonymous viewers (no progress tracking) are never locked out by this.

    Permissions (/admin/permissions, needs manage_permissions): a phpBB/WordPress-style permission builder — define named groups (e.g. "Moderators") as a custom bundle of capabilities from a fixed list (src/lib/capabilities.ts: manage categories/series/videos/files, publish content, moderate comments, share restricted content, manage users/permissions/plugins, view audit log), then assign a group to a user site-wide or scoped to one category (and everything under it) or one series. This sits alongside the older simple per-category/series "content-editor" grants in /admin/users — both are checked by src/lib/permissions.ts. The real ADMIN role always has every capability and can't be granted through a group (only another ADMIN can promote someone to ADMIN, to avoid a privilege-escalation hole via a custom "manage_users" group).

    Granular viewing permissions: beyond the plain "Members only" checkbox, a series or video's edit page has a "Restricted viewing" panel (src/components/viewer-access-manager.tsx) that grants view access to specific permission groups (reusing the groups from Permissions above as "roles") and/or specific people by email. As soon as either exists for an item, "Members only" is ignored for it and only those roles/people (plus admins) can view it — checked by canViewSeries/canViewVideo in src/lib/content.ts. With no grants, behavior is unchanged. This only applies to series and videos, not files, which stay governed by their own "Members only" flag.

    Share links: a revocable, tracked link to one series or video, opened at /s/[token]. Any member may share, including gated content — but a link only overrides a members-only/viewer restriction when the sharer ticks the override box, which needs the share_content capability (site-wide or scoped to a category/series). That opt-in is the "let this one guest in" path; without it the link opens only for people who already have access. Access-granting links are checked by canViewSeries/canViewVideo alongside the grants above. Redemption stores the token in an httpOnly cookie so access survives navigation, but the cookie is re-validated against the DB on every request — revoking is immediate. Links can be public or addressed to specific emails (which requires logging in as that address), can carry an optional scrypt-hashed password (src/lib/share-password.ts, unlocked at /share/unlock/[token] with a per-link lockout after repeated wrong guesses), can expire, and are listed for the sharer at /profile/shared-links and for admins at /admin/share-links. Split across src/lib/share-access.ts (redeem + resolve grants) and src/lib/share-links.ts (create, list, revoke) so content.ts can consult grants without importing the permission machinery it depends on; the stored password hash is stripped from every API response by a single DTO mapper.

    Downloads: members save videos to the device and play them offline. Four gates, all of which must pass: the plugin (site-wide, with the usual per-category override), a tri-state downloadEnabled on the video/series/category resolved most-specific-first (resolveDownloadEnabled in src/lib/downloads.ts), the DownloadPolicy singleton's audience (any member, or named permission groups/users), and its platform (web, installed PWA, or both). /api/downloads/[videoId] calls canViewVideo before any of it, so downloading can only ever narrow what a member can already watch. The file is a signed, short-lived Bunny MP4 URL — requiring MP4 Fallback on the Stream library, since HLS segments can't be played offline by a <video> — streamed into Cache Storage under /offline-video/<id>.mp4 and served back by the service worker, range requests included, so seeking works with no network. The downloaded list is per device and never reaches the server. Admin settings at /admin/downloads; member view at /profile/downloads.

    A plain page load with no network — including the installed PWA's own start_url on a cold launch — has no HTML to render and would otherwise hit the OS's own offline error, which has no way to reach a video already saved to the device. public/sw.js falls back to public/offline.html (precached at install time) for any navigation whose network request fails; that page is static and reads nothing but the same localStorage index and Cache Storage the download feature already writes, so it needs no server, no auth, and no build step.

    The MP4 rendition isn't assumed. Bunny reports hasMP4Fallback and availableResolutions per video — enabling MP4 Fallback on a library only affects uploads made afterward, so older videos routinely have neither — and resolveMp4Source (src/lib/download-source.ts) reads that instead of guessing a fixed height. It picks the highest available resolution at or under BUNNY_STREAM_DOWNLOAD_HEIGHT (default 720p), caches the result on Video.hasMp4Fallback / Video.mp4Resolutions so the member-facing request is a Postgres read rather than a Bunny API call, and returns a specific reason — no fallback generated, no resolution at or under the cap, Bunny/CDN rejected the request (403 — almost always a token-auth or pull-zone setting, not a missing file), or Bunny doesn't have the file (404) — rather than one catch-all "no downloadable file" message. /admin/videos shows each video's synced MP4 state; the sync-status routes (manual and cron) are what notice a video has become downloadable after a re-upload or Bunny repackage.

    The profile area (/profile): the member's account hub — inbox, shared links, downloads, and settings — shown identically on the web and in the PWA, with a Profile tab in the mobile bottom nav badged with the unread count. Notifications are persisted as Notification rows by notifySubscribers, so the inbox is a complete record regardless of whether push or email reached the member. Theme/language/autoplay/playback speed/download-network live in localStorage (src/lib/device-settings.ts), deliberately per device rather than on the User row; account-level settings and account deletion sit below them on /profile/settings.

    Continue watching / recently added: logged-in users get a periodic heartbeat (src/components/watch-progress-tracker.tsx) that approximates watch position (elapsed-time based, not a precise scrub position — Bunny's embed does support postMessage control via Player.js, see video-player.tsx, just not wired up here yet) — the homepage shows a "Continue watching" row from that, resuming playback near where you left off via Bunny's t= embed parameter, plus a "Recently added" row of newest published series. A "Mark as watched" toggle on the video page (MarkWatchedButton) sets or clears WatchProgress.completed directly — the same completion flag that gates Sequential unlock and feeds the watch-through-rate analytics — independent of the heartbeat, which only ever sets it to true, never back to false (a stray heartbeat must not silently undo a completion).

    Trending / Up next / premieres: the homepage shows a "Trending this week" row (from a timestamped view log, distinct from the simple viewCount counter); video pages show an "Up next" panel with an autoplay toggle for the next episode in the series; a video can be marked a premiere with a future publish time to show a live countdown instead of staying fully hidden until then.

    Admin analytics (/admin/analytics, view_analytics capability): view totals and top series/videos for a selectable window (?days=7|30|90), from the same view log, plus a CSV/JSON export of the same data (/api/admin/analytics/export) for pulling into a spreadsheet.

    Homepage rows (/admin/home-rows, manage_plugins capability): a HomeRow per built-in section (seeded once via ensureHomeRowsSeeded()) lets an admin toggle, rename, and reorder Continue watching/Because you watched/Trending/Recently added, plus add curated CATEGORY/TAG rows. getHomeRows() falls back to the default built-in order when nothing's configured yet, the same fail-open pattern as getPluginStates(). Continue watching (when shown) always renders directly above the category/series browse list, which itself isn't a configurable row.

    Trash (/admin/trash): deleting a category, series, video, or file now sets deletedAt instead of removing the row (publishedNow() in src/lib/content.ts excludes it everywhere public, and every admin list route filters it too). Restore clears deletedAt; permanent delete (/api/admin/trash/[type]/[id] DELETE) is the only point a video/file's underlying Bunny Stream/Storage asset is actually removed — trashing alone leaves it in place, unlike before. Gated on holding at least one of manage_categories/manage_series/manage_videos/manage_files site-wide (or ADMIN), since the queue spans all four types at once. Trashing a category/series doesn't cascade: a child row keeps its categoryId/seriesId as-is and just stops appearing in listings that traverse through the trashed parent, while staying reachable directly by URL.

    Slug aliases: changing a series/video's slug from its edit page records a SlugAlias (old slug -> current id); the /series/[slug] and /videos/[slug] pages fall back to resolving one when the direct lookup finds nothing, then permanentRedirect() to the current slug — so a link shared before a rename still works instead of 404ing.

    Book reader: /read/[fileId] opens a PDF (pdf.js) or EPUB (epub.js) in-app, with contents, in-book search, read-aloud and marking. Both engines are dynamically imported inside an effect — each is large and touches browser globals on load — and sit behind one ReaderHandle interface (src/components/reader-types.ts), so BookReader never needs to know a PDF page number from an EPUB CFI. That's also why ReadingProgress.location and ReadingMark.location are opaque strings: only the engine that wrote one parses it.
        Bytes are served by /api/files/[id]/content — the single route every file link now goes through, readers and downloads alike. See File access below for why. Range requests are forwarded so pdf.js can chunk large documents.
        Books (and only books) are served private, no-cache rather than private, no-store, and conditional requests are forwarded to Bunny, so a re-open revalidates into a bodyless 304 instead of re-downloading — the access check still runs on every request, which max-age would have given up. A PDF under WHOLE_BOOK_MAX_BYTES is fetched whole rather than in ranges so there is a single cacheable resource to revalidate.
        A resolved contents list is cached per device in localStorage (src/lib/reader-cache.ts), tagged with the file's size, because resolving a hymnal's bookmarks to page numbers is hundreds of round trips. ReaderHandle.order maps each engine's opaque locations onto one number line so src/lib/toc-nav.ts can step between contents entries without knowing which engine produced them.
        A book can also be saved to the device (src/lib/offline-books.ts), which stores its bytes under /offline-book/<id>.pdf in Cache Storage, its contents list, and a copy of the reader it needs — pdf.js from public/pdfjs, or epub.js plus JSZip from public/epubjs, since the offline shell has no bundle to render with. scripts/copy-offline-viewers.mjs puts those there at install and build time so they track the pinned versions rather than being committed, and only the library a saved book actually needs is fetched.
        A hymn-per-file book has no document to store, so /api/offline/hymnal/[seriesId] returns its hymns and lyrics (same view and plugin checks as the page) and they are cached as JSON under /offline-hymnal/<id>.json. Both kinds share one index, discriminated by kind.

    A book's contents are indexed server-side into BookHymn rows by the admin's cover/index pass (derivePdfBook resolves the outline in the browser, since that is where pdf.js runs, and PUTs it to /api/admin/files/[id]/contents, which parses the hymn number with the same hymnNumberOf the reader uses). That is what lets searchHymnsInCategory answer across a whole shelf, and lets searchContent find a hymn printed inside a scanned book. Pages are stored as PDF pages; the printed number is derived at the edge, as everywhere else. A PDF with no bookmarks indexes to nothing, so the same rows can be typed by hand instead — ContentsEditor parses the box with lib/book-contents.ts (printed pages in, PDF pages stored, indentation as nesting) and PUTs to the same route; the outline pass declines to send an empty list, which would otherwise replace a typed one.

    A hymn inside a book can have words, in BookHymnLyric, keyed by (fileId, number) rather than by a BookHymn row — those are deleted and rewritten on every reindex, and these are typed by hand. That is what makes a whole-book hymn presentable (planItemPresentable, presentHref, /present/[fileId]?hymn=) and findable by a line of its words (hymnsMatchingWords, which reads the words first and then the contents entries they belong to, since Prisma can't match a relation against the parent row's own column).

    A scanned book's pages can be read into BookPage — the file's own text layer where there is one, OCR off the image where there isn't (BookTextReader in the admin's browser; lib/ocr-client.ts points tesseract.js at this app's own /tesseract, vendored by scripts/copy-offline-viewers.mjs, rather than at a CDN). Stored a page at a time so an hour-long run is resumable and interruptible; textIndexedAt is set only by a run that reaches the last page. searchBookText then backs the reader's in-book search (falling back to parsing the open document for a book nobody has read), and hymnsMatchingPages attributes a matching page to the contents entry it falls inside, so a section search still returns hymns rather than page numbers.

    A service's running order can be kept on the device (lib/offline-services.ts, /api/offline/service/[id]): its own Cache Storage cache and localStorage index rather than the books' ones, since a plan is kept for one Sunday and thrown away after it. Fingerprinted with the shared lib/fingerprint.ts over what is actually handed out, so a ?probe=1 request answers "is the order I saved still the order" without re-fetching it. The offline shell renders it, and opens a hymn whose book is also saved.

    Reading text size (readingTextScale in lib/device-settings.ts) is one per-device value shared by the lyrics view, the EPUB reader and the offline shell — which both reads and writes it, so its clamp bounds are copied there and pinned by offline-shell.test.ts. EPUB scaling goes through rendition.themes.fontSize as a percentage: the book's pages live in an iframe with their own stylesheet, so a size set outside it reaches nothing.

    Schedules are a second, separate rota system, ported from the calendar app: Schedule / ScheduleSource / CalendarEvent / Person. The point of its shape is the provider layer — lib/schedules/provider.ts picks a ScheduleProvider (Google Sheets or the database) and nothing above it knows which, so a schedule can switch source without a component changing. lib/sheets/ is the parsing (two layouts, forgiving dates, skip-and-report), lib/schedules/sync.ts the import (resolve names to people, never delete on failure, skip writes when the payload is unchanged).
        Adapted rather than copied where this app already had the machinery: its ApiError folded into errorResponse, its audit into logAudit, its in-memory rate limiter dropped for this app's database-backed one, and lib/schedules/http.ts keeps the four idioms its twenty route handlers were written against so they port without a rewrite.
        The calendar goes on the device incrementally (lib/offline-calendar.ts, /api/sync/snapshot): Cache Storage under /offline-calendar/snapshot.json like everything else saved here, rather than the calendar app's IndexedDB, so the static offline shell can read it with no bundle. What is stored is the merge rather than the server's bytes — mergeSnapshot is pure and carries the two rules a delta can't state, since disabling a schedule doesn't touch its events' updatedAt and a day leaving the window is never reported deleted. Snapshot lives in lib/schedules/types.ts, not beside the query that builds it, because the merging runs in a browser.

    An event's capacity is decided under a row lock (lib/events.ts): SELECT … FOR UPDATE on the Event row inside the transaction that writes the registration, so concurrent sign-ups for the last place serialise per event while other events proceed in parallel — and without the serialisation failures a Serializable transaction would make the caller retry. Reading the count outside the transaction and writing inside is the version of this that overbooks under load. Promotion off the waiting list happens in that same transaction, because "a place is free" and "you have it" must never be two facts another request can slip between.
        The decision itself (registrationState, promotable) is pure and tested without a database, including the rule that promotion stops at the first party too big to fit rather than skipping to a smaller one.
        manage_events is a new capability rather than a reuse of manage_files: a registration list carries names, phone numbers and addresses that the media library never does.

    A form's questions are rows, and its answers point at those rows (lib/forms.ts for the rules, lib/forms-query.ts for the reads). Two consequences are the design: renaming a question can't detach its answers, and deleting one is replaced by deletedAt — a hard delete would cascade a year of answers away, or leave them under a column nobody can name. columnsFor puts live questions first and retired ones after, so an export never silently drops what somebody actually said.
        The split between those two files is load-bearing rather than tidiness: the fill-in component is "use client", and one value imported from a module that reaches lib/db bundles PrismaClient into the browser. client-bundle.test.ts walks every client component's value imports transitively and fails on any that reach it — a class of bug that type-checks, lints and builds, and only shows up as a blank page.

    The prayer wall's two decisions are one function each (lib/prayer.ts): canSee for whether a reader may see a request at all, bylineFor for what they may be told about who wrote it. Every read path — the wall, the moderation queue, the API — goes through visibleTo, which composes them. The alternative is a where clause copied between four queries that eventually disagree, and here disagreement means somebody's name on something they asked to post anonymously.
        Anonymity is not a missing column: the row keeps userId so the writer can delete their own and a moderator can act on abuse. It is enforced by bylineFor being the only place a name is allowed out, and by VisiblePrayer having no userId field to populate.
        The narrowing where in listPrayers exists for the query planner; visibleTo is still what decides, so widening it cannot widen who sees what.

    A small group's address is structurally hard to leak (lib/groups.ts). area and address are separate columns; presentGroup is the only thing that decides whether the second travels, and VisibleGroup declares address? — absent rather than null when withheld, so a page that forgets to check renders nothing instead of a home. Verified against a running server: the string appears in neither the API response nor the page's HTML for a visitor, a signed-in stranger, or somebody who has only asked to join.
        "Has asked to join" deliberately doesn't qualify. If it did, anyone with an account could learn a leader's address by pressing a button — the leader's answer is what turns a stranger into somebody who is coming.
        Leaders act through canLead on their own group rather than through a capability: whoever hosts the Tuesday group shouldn't need an admin grant to answer somebody knocking on their own door.

    A broadcast is resolved into rows before anything is sent (lib/broadcast.ts for the rules, lib/broadcast-send.ts for the work). One row per person per channel with the address copied in, each marked as it goes — which is what makes a send resumable across a killed function, and what stops a changed phone number splitting a broadcast between the old one and the new. A unique index on (broadcast, channel, address) is the backstop against a double-clicked button.
        The batch loop lives in the browser: the admin screen calls /send repeatedly. A single request that tried to send four hundred emails would be killed at the platform timeout with no record of how far it got, and this way the same mechanism produces a progress bar. /api/cron/broadcasts is the backstop for a closed laptop, not the delivery path.
        planDelivery is pure and holds all three consent rules, so the count on the screen is the count that goes out. smsOptIn is deliberately not inferable from having a phone number: an event's sign-up form collects numbers, and that is not permission to text.
        sms.ts (segment counting, number normalising) is split from sms-send.ts (providers) because the composer shows the cost as you type and so ends up in the browser bundle.

    Translation is a typed object, not a key-path lookup (lib/i18n/). Messages is derived from the English catalogue, so a language file missing or misspelling a key fails to compile — completeness needs no test. The test covers what types can't see: a translation that drops a {placeholder}, which loses a number from a sentence and still renders.
        The chosen locale is a cookie as well as a device setting, because these pages are server-rendered and the server cannot read localStorage. Storing it only in the browser would mean every page arriving in the old language and flipping after hydration.
        pickLocale parses Accept-Language with its quality weights and matches regional tags to their base language; it is pure and tested, including the case where the header asks for a language the app doesn't speak.
        device-settings.ts keeps its own copy of the language list so that the module every page imports to read a preference doesn't pull two catalogues with it; i18n.test.ts asserts the copies agree.

    Video.source decides which player fills the frame (lib/video-source.ts). bunnyVideoId became nullable rather than an empty string, which made the type-checker enumerate every Bunny-only capability — downloads, captions, MP4 renditions, encode-status sync, transcription — and each now refuses an imported video with a reason instead of failing at the API call. The three players all take a start time, so chapters and resume work unchanged.
        The sync (lib/video-feed-sync.ts) keeps importedTitle / importedDescription beside the live fields and only overwrites a field whose live value still equals the imported one. Without that three-way comparison every nightly sync silently undoes the edits made after the last one. A null imported* means "we don't know", which resolves to don't touch rather than to overwrite.
        lib/video-feeds.ts is the provider layer, the same shape as ScheduleProvider: four feed kinds, two APIs, one fetchFeed.

    Live chat polls; it does not hold a socket (lib/live-chat.ts). Nothing here is long-lived enough to keep a connection open, so the client asks ?since=<id> — one indexed range scan on (streamId, id), usually returning nothing — and pauses the interval on document.hidden.
        visibleMessages drops hidden rows after the query as well as in it, so a poll cannot hand a tab that was seconds behind a message a moderator has just removed. Hidden rather than deleted, so the same message can't be reposted past them.
        chatState closes the chat an hour after a stream ends. An unattended comment box on an old stream is the failure mode this whole design is arranged against; the messages stay readable, the input goes.
        Slow mode is computed per author (waitSeconds), not per stream.

    Signing a television in is RFC 8628, not a password box (lib/tv-pairing.ts for the rules, lib/tv-session.ts for the storage). The user code and the device code are two different secrets on purpose: the first is on a screen in a public room and only ever names a request; the second never leaves the television and is the only thing that can exchange an approval for a token. Both are stored hashed; the token is compared in constant time; claimToken is a conditional update, so two polls arriving together cannot both mint one.
        pollAnswer checks expiry before "approved", so a code somebody approved and walked away from stops being redeemable rather than waiting for ever.
        The feed routes use force-dynamic plus s-maxage, not revalidate: revalidate on a route with no dynamic input makes Next prerender it at build time, and the feed would then ship carrying whatever database the build machine saw. Same one-fetch-an-hour behaviour, always from live data.
        feedVideos filters memberOnly: false on the video and on its series. That is the whole safety argument for the feature: there is no session on a request from Roku's crawler.
        /tv covers the app chrome with fixed inset-0 the way presenter mode does, rather than restructuring the root layout — a remote cannot use a sidebar, and on a television it would eat a fifth of the screen.

    A rota lives beside the running order: ServiceTeam / ServiceTeamMember are the pick-list, ServiceAssignment is one ask with its answer, and ServiceBlockout is when somebody is away. The job is free text on the assignment rather than a positions table — every church names those differently — and it defaults to "" rather than null so the unique index over (planId, userId, position) actually constrains anything.

    Automatic transcription is a queue on the video row (transcriptStatus), drained one at a time by /api/cron/transcribe: an hour of audio takes minutes, which outlives a request. lib/transcribe.ts speaks the multipart file + { text } shape every speech-to-text service implements, so the deployment picks the provider — including one on its own network.

    A sermon note sheet is text with ___ in it (lib/outline.ts), parsed into segments at render. Answers are keyed by a gap's position, and the outline's fingerprint travels with them so an edited sheet is reported rather than silently misaligned.

    Hymn openings are counted in the browser (HymnLookup + the beacon component of the same name), not on render: Next prefetches links on hover, so a server-side count would largely count hovering. Feeds "most looked-up hymns" in the admin analytics.

    The bottom bar is per device: getShellNav returns both the app's suggested tabs and every destination this viewer could choose (tabOptions), and src/lib/nav-tabs.ts resolves a stored list of hrefs against the latter — so a destination that disappears drops out instead of 404ing. BottomNav also writes a snapshot of what it drew to localStorage, which is the only way public/offline.html can draw the same icons with no server. That file is a standalone offline app: the tab bar, the books and videos saved on the device, a book's cached contents, and a pdf.js page view, all from Cache Storage and localStorage, with the browser's own PDF viewer as the fallback where it can't render.
        pdf.js's worker is resolved via new URL(..., import.meta.url) so it stays version-locked instead of needing a copy in /public; the build emits it to .next/static/media.
        epub.js is opened with openAs: "epub" because it otherwise infers format from the URL extension, and the content route has none. Its bundled typings are also wrong in places (Section.find() is declared Array<Element> but returns {cfi, excerpt}), so that shape is declared locally.

    File access: /api/files/[id]/content is the only URL the app hands out for an uploaded file. It runs canViewFile against the live session per request, streams from Bunny with bunnyStorageSignedUrl (which signs when BUNNY_STORAGE_TOKEN_AUTH_KEY is set and passes through unsigned when it isn't), and forwards Range requests. ?download=1 switches Content-Disposition to attachment.
        bunnyStoragePublicUrl still exists but nothing user-facing calls it. A pull-zone URL needs no login, can't be revoked, and can't express the rule this app actually has — a file's visibility follows its series' mutable memberOnly flag, so a static CDN path is the wrong shape for it.
        Locking the pull zone is a dashboard step, not a code one. Enable Token Authentication (Pull Zone -> Security) and set the key; until then URLs already in circulation keep working even though the app has stopped producing them.
        Public podcast zone (optional, off by default): a separate Bunny storage zone plus its own unauthenticated pull zone, holding only audio an admin explicitly published. src/lib/podcast-mirror.ts owns the lifecycle — isMirrorEligible is a pure, unit-tested predicate (podcastPublished is necessary but never sufficient; file and series audience, publish state and schedule all re-checked), and syncPodcastMirror reconciles the zone to it. It's called from every path that can change eligibility: applyFileUpdate (which the bulk route also goes through), removeFile, the series update and trash routes, and trash restore. Permanent deletion calls purgePodcastMirror, since deleting the private object doesn't touch the other zone.
        publicPath is written only after a successful copy and cleared before deletion, so the two failure modes are "absent from the feed" rather than "advertised but missing" or "public but forgotten". Enclosure URLs are built from publicPath, never from bunnyPath, so a private object's path isn't derivable from a public one. syncPodcastMirror never throws — a Bunny outage shouldn't fail an admin's edit.
        The copy streams rather than buffers (sermon audio is routinely hundreds of MB), but still passes through the function, so a large enough file can hit the request timeout. That's reported as a failed copy and leaves the file unmirrored.

    Feeds: /feed.xml is a site-wide RSS feed of recently added series; /series/[slug]/podcast.xml is an iTunes-compatible podcast feed of a series' published audio files (skipped for memberOnly series, since podcast apps can't authenticate).

    Bunny Stream playback: bunnyStreamEmbedUrl() and bunnyStreamThumbnailUrl() (src/lib/bunny.ts) build the iframe/thumbnail URLs fresh on every request. If BUNNY_STREAM_TOKEN_AUTH_KEY is set, they sign a short-lived token/expires pair per Bunny's token authentication formula (sha256_hex(tokenAuthKey + videoId + expires)); if it's unset, plain unsigned URLs are used instead.

    Per-page metadata, OG images, and JSON-LD: video/series/category/ speaker pages export generateMetadata (title, description, an OpenGraph/Twitter image from the content's own thumbnail/cover/photo) instead of relying on the one static metadata in src/app/layout.tsx (which now also sets metadataBase from APP_BASE_URL so relative image paths resolve). The four slug lookups it shares with the page component (getCategoryBySlug/getSeriesBySlug/getSpeakerBySlug/ getVideoBySlugIncludingPremiere in src/lib/content.ts) are wrapped in React's cache(), the same per-request-dedup pattern getCurrentUser already uses, so both callers share one query instead of two. A page for content the current viewer can't see returns a generic title/no image rather than the real ones — generateMetadata re-runs the same canViewVideo/canViewSeries/canAccess check the page body itself uses, rather than trusting that whatever's in the database is safe to publish as metadata. Video pages also emit a schema.org VideoObject and, alongside series/category pages, a BreadcrumbList (src/lib/json-ld.ts) — gated the same way, and paired with a real, visible <Breadcrumbs> nav (src/components/breadcrumbs.tsx) built from the same items, replacing the old bare "← back" link on video and (when unlocked) category pages. The JSON-LD script tag itself renders nothing — it's invisible metadata for search engines, not the visible trail.

    Timestamp/clip sharing: the video page reads a ?t=<seconds> search param into resumeAt, which seeds bunnyStreamEmbedUrl's t= param, taking priority over the viewer's own watch progress. TimestampShareLink (mm:ss input, parseTimestamp from src/lib/format.ts) builds that link, and each chapter row in VideoPlayer gets its own copy-link button using its own timestampSeconds.

    Cast to TV: CastButton (src/components/cast-button.tsx) integrates Google's Cast Web Sender SDK (loaded at runtime, no npm types package — see the file's local ambient types) and reuses the signed MP4 URL already built for the Downloads plugin (/api/downloads/[videoId]) as its cast source, since the default Chromecast receiver needs a direct file rather than an iframe embed; shown next to the download button, under the same getDownloadAvailability gate. AirPlay needs no equivalent code — Safari shows its own AirPlay control for any actively-playing <video>, including one inside a cross-origin iframe, since that's a system-level media route. Note: Bunny's embed turns out to have both of these built in already — a chromecast=true query param puts a native Cast button in Bunny's own player UI, and AirPlay is on by default (disableAirplay turns it off) — discovered after CastButton was already built; the two haven't been reconciled.

    PWA: public/manifest.json + public/sw.js make the site installable (Add to Home Screen / desktop install prompt) and able to receive Web Push. The service worker deliberately does not cache pages or API responses — this site's content is dynamic and often auth-gated, so an aggressive offline cache would risk showing stale or wrong-audience content; it only caches its own static shell assets (manifest + icons) and handles push/ notificationclick events for the Notifications plugin. Icons are rendered from two committed SVG sources — public/icon.svg and the full-bleed public/icon-maskable.svg, whose artwork sits inside the 80% safe zone because launchers apply their own mask. Editing an SVG means re-rendering the PNGs beside it. The service worker caches the icons by name, so bump its CACHE_NAME when they change or installed PWAs keep the old artwork.

Performance notes

Written for a Postgres free tier, where every query counts:

    getCurrentUser()/getSessionIdentity() (src/lib/current-user.ts) are wrapped in React's cache() so the many call sites that each need the current user in a single request (root layout's Navbar, the page itself, admin layout, ...) share one query instead of hitting the DB repeatedly.
    getPluginStates() (src/lib/plugins.ts) resolves every plugin's enabled state for a page in one pass (2-3 queries total, regardless of category-tree depth or how many plugins exist) instead of checking each plugin individually — use it instead of calling isPluginEnabled() in a loop or a Promise.all of several calls.
    Series/video pages fetch ratings, reactions, and comments server-side and pass them down as initial props instead of letting the client component fetch on mount, and skip that work entirely when the relevant plugin is off rather than fetching and hiding it in the UI.
    ViewEvent writes (Trending/Analytics) go through a client-side beacon (/api/view-events, src/components/view-event-beacon.tsx) throttled by a 30-minute cookie rather than a DB check — a cookie read is free, so a throttled repeat view costs zero database operations.
    Query Monitor: set QUERY_MONITOR_ENABLED=true to get a WordPress-Query-Monitor-style debug bar at the bottom of every page — query count/time, a per-query breakdown, page render time, and process memory — so the query counts above are something you can actually watch rather than take on faith. Two switches gate it: that env var (a redeploy to flip) and a DB-backed admin switch on /admin/query-monitor (no redeploy — e.g. to hide the bar mid-demo); both must be on, and even then it only renders for logged-in ADMIN users. The admin switch reuses the Plugin table (slug "query-monitor") but is deliberately excluded from PLUGIN_META//admin/plugins, since it's an ops toggle with no per-category meaning — /api/admin/plugins filters to PLUGIN_META's own slugs so it doesn't show up there with a nonsensical "Category overrides" control. src/lib/db.ts wraps every Prisma model call in a client extension that's a no-op unless the env flag is set — checking the DB switch too would add a query to every query, so recording only depends on the env flag and the DB switch purely gates whether the bar renders; src/lib/query-monitor.ts tallies per request via React's cache() (the same primitive getCurrentUser() uses), so concurrent requests never mix each other's counts — verified by firing concurrent requests with known, distinct query counts and confirming none leaked into another's tally. Client-side <Link> navigations reuse the root layout's previous render instead of re-executing it (Next's "partial rendering"), so QueryMonitorRefresher forces a router.refresh() on every path change while the bar is mounted, otherwise it'd keep showing whichever page triggered the last full load — verified with a real browser clicking between routes with different query counts and confirming each one's numbers actually updated.

Deployment (the part that still applies: the scheduled jobs)
Scheduled jobs

Every schedule here runs at most once a day, and that is a hard constraint rather than a preference. Vercel's Hobby plan refuses anything more frequent at deploy time — the whole deployment fails with "Hobby accounts are limited to daily cron jobs" — so an hourly entry in vercel.json is not a job that runs too often, it is a site that doesn't go live. cron.test.ts asserts it, because nothing else in the build does. On a paid plan, tighten these and delete that test deliberately.

vercel.json declares five crons, all guarded by the same CRON_SECRET bearer-token check (Vercel Cron attaches it automatically), and all at different minutes so two never share a run:

    /api/cron/sync-schedules, 05:30 UTC. Imports every Google Sheets schedule whose own interval has elapsed; a schedule set to sync more often than daily is effectively capped by this cadence. Before the reminders below, so they go out on the morning's data rather than yesterday's.
    /api/cron/sync-video-status, 06:00 UTC. Polls Bunny for every video still stuck in PROCESSING and reconciles its status/duration/thumbnail, the same as the admin's manual "Sync from Bunny" button — so a finished encode doesn't sit unprocessed until someone happens to click refresh.
    /api/cron/notification-digest, 13:00 UTC. Batches every queued PendingNotification per user into a single push and clears the queue — the only delivery path for members who chose the "Daily digest" frequency; if this cron isn't running, their notifications pile up and never arrive.
    /api/cron/schedule-reminders, 18:00 UTC. Tells people what they are on for tomorrow, one message however many rotas they are on.
    /api/cron/transcribe, 02:00 UTC. Works through the transcription queue. Bounded by time, not by a count: it takes as many videos as fit inside the function's own limit (maxDuration, and the shorter BUDGET_MS it stops starting new work at) and leaves the rest for tomorrow. It was one video per run on an hourly cron, which on a daily cadence would have meant one video a day — a church with forty untranscribed sermons waiting until Christmas. A run killed mid-transcription leaves that video RUNNING; the stale sweep in transcribeNextQueued re-queues anything stuck there for half an hour.
        Note the honest limit: on Hobby, maxDuration is 60s, and an hour of audio may not transcribe inside that at all. A deployment that needs this to work on long sermons wants a plan with a longer function timeout, raising maxDuration and BUDGET_MS together.

A.2 — FEATURES.md
Features (original FEATURES.md)

A complete list of what's built. See README.md for setup and CHANGELOG.md for release history.

Everything past the media library is a plugin, off until an admin turns it on at /admin/plugins — a church that wants a video site is not given a prayer wall it never asked for.
Where things are
For the congregation 	
/ /categories /series /videos 	the library
/search 	one search across titles, descriptions, transcripts and hymn text
/services 	the running order for a service, and the hymns in it
/calendar 	the rota, for people with no account
/events 	what's on, and signing up (including things that repeat)
/forms 	connect cards and sign-up forms
/prayer 	the prayer wall
/groups 	small groups
/live 	a live stream, with chat
/tv 	the same library, for a remote control
/link 	signing a television in
/profile 	your own rota, events, groups, downloads and televisions
/profile/settings 	how the site behaves for you — and a copy of your data
For whoever runs it 	
/admin/series /admin/videos /admin/files 	the library
/admin/services /admin/teams 	a service and who is serving at it
/admin/schedules /admin/people 	rotas from a spreadsheet
/admin/events /admin/forms /admin/groups /admin/prayer 	church life
/admin/broadcasts 	one message to everybody
/admin/video-feeds 	importing from YouTube or Vimeo
/admin/plugins /admin/branding /admin/permissions 	how the site behaves
/admin/api-keys 	keys another system uses to read this one
Public site

    Browsing — a vertical list of tiles (thumbnail, title, item count), Subsplash-style. Categories nest arbitrarily deep (a category's children can themselves have children); the homepage shows top-level categories and any uncategorized series, each linking one level deeper.
    Series & video pages — description, tags, cover image, video playback (embedded Bunny Stream player), file downloads, inline playback for audio files.
    Featured/pinned content — a series can be marked featured (used for the homepage hero, overriding the recency-based default) or pinned (sorts first in its listing regardless of position).
    Tags — free-form tags on a series, shown as chips, searchable; /tags/[tag] lists everything with a given tag.
    Scheduled publishing — publishAt/unpublishAt timestamps on series/videos/files gate visibility independently of the published flag, so content can go live or expire automatically without a manual step.
    Search — /search and the navbar search box rank results by relevance (exact/prefix title match outranks a description-only hit) across category names, series titles/descriptions/tags, video titles/descriptions, and speaker names. Filters narrow results to one category and/or speaker, and a sort toggle switches between relevance and newest-first. If the exact pass finds no series or no videos, a typo-tolerant fuzzy pass runs as a fallback, ranking by Postgres trigram similarity so "chruch" still finds "Church" — see the technical note below.
    Speakers — an admin-managed directory of preachers/presenters (/speakers, /speakers/[slug]), attachable to a video from the video manager; a speaker's page lists their published, viewable videos.
    Scripture references — free-form Bible references on a video (e.g. "John 3:16-18"), shown as chips and browsable at /scripture (an index of referenced books) and /scripture/[book].
    Live streaming (plugin) — an admin-scheduled LiveStream pointing at an already-hosted embed (YouTube, Boxcast, etc. — Bunny Stream has no live ingest). /live shows the current stream when one is live, a countdown to the next scheduled one otherwise, and a site-wide "Live now" banner appears on the homepage and in the nav while a stream is live. Publishing a stream sends a push notification, same as a new video.
    Continue watching / recently added — a periodic heartbeat approximates watch position (see note below) and powers a homepage "Continue watching" row with resume-from-where-you-left-off; a "Recently added" row shows the newest published series.
    Trending — a homepage "Trending this week" row of the series with the most logged views in the last 7 days (gated by the View counts plugin, which now also logs timestamped view events, not just the all-time counter).
    Admin-configurable homepage rows — an admin can turn any homepage row on/off, rename it, and reorder it, plus add curated rows pointing at a specific category or tag. See Admin CMS below.
    Up next — a panel under a video showing the next episode in its series, with an autoplay toggle (persisted per-browser) that best-effort advances once the current video's known duration elapses — see the technical note below on why this isn't a real "ended" event.
    Scheduled premieres — a video can be marked as a premiere with a future publish time: unlike a normal scheduled video (fully hidden until then), a premiere's page is visible early with a live countdown to the exact publish time, then swaps to the real player automatically.
    Related content — series pages show "More like this" (same category, then shared tags); video pages show "More from this series" or "You might also like" for standalone videos.
    Audio has the controls a phone expects — playing a talk or a hymn puts its title, its series and its cover on the lock screen, with play, pause, a scrubber that actually moves, and skip buttons (15 seconds back, 30 forward). A sleep timer (15/30/45/60 minutes) stops it on a wall-clock deadline, so pausing to answer the door doesn't extend the night, and a speed control sits beside it. The lock screen is claimed on play rather than on load, so a page listing eight talks doesn't have eight players fighting over it.
        This is also where the default playback speed from /profile/settings finally applies. It was stored and shown as a reminder because Bunny's embed takes no such parameter — a reason that never applied to audio, which is our own element.
    Backgrounding on Android pauses playback — minimizing the app stops the audio; Android's media notification then resumes it with one tap, and from there it keeps playing in the background. That resume notification has always worked and isn't something this app implements — the browser provides it for any playing media. Automatically resuming instead has been tried and does not work; see the Technical notes.
    Cast to TV — AirPlay needs no code from this app: Safari shows its own AirPlay control for any actively-playing <video>, including one inside Bunny's iframe, since that's a system-level media route. Chromecast is different — the default receiver needs a direct, castable file rather than an iframe — so a cast button (next to Download, same gate) uses Google's Cast Web Sender SDK and reuses the signed MP4 endpoint built for Downloads as its media source. Not verified against a real Chromecast device — there isn't one available in the environment this was built in; check it on a preview deploy with an actual receiver. (Bunny's own embed turns out to support both natively — chromecast=true and disableAirplay — discovered after this was already built.)
    Sequential unlock — a per-series "require watching in order" toggle locks a video until the previous one (by position) is marked completed in the viewer's watch history. Anonymous viewers are never locked out (no progress tracking without an account).
    Feeds — /feed.xml (site-wide RSS of recently added series) and /series/[slug]/podcast.xml (iTunes-compatible podcast feed of a series' audio files, skipped for member-only series since podcast apps can't authenticate).
    Sitemap — /sitemap.xml lists published categories and series, guest-visible videos, and every distinct series tag, so search engines don't have to discover pages by crawling links alone. Member-only videos are left out (as in every public video listing); member-only categories and series are included, matching how they already list publicly behind a "Members" badge.
    PWA — installable (Add to Home Screen / desktop install prompt) with a minimal service worker; see the PWA section below.
    Per-page metadata — video, series, category, and speaker pages set their own title, description, and Open Graph/Twitter card image (video thumbnail, series/category cover, or speaker photo) instead of sharing one site-wide <title>, so links shared to chat apps and social media preview with the real title and image. A member-only page a visitor can't view gets a generic "Members Only" title and no image, matching what the page body itself withholds from a non-viewer.
    Breadcrumbs — video, series, and category pages show a visible Home / parent / current-page trail at the top (replacing the old bare "← back" link on video and, when unlocked, category pages). The same items also build a schema.org BreadcrumbList, an invisible <script type="application/ld+json"> tag search engines read for rich-result breadcrumbs — it's not shown on the page itself, the visible trail is.
    Structured data (JSON-LD) — video pages also emit a schema.org VideoObject (title, description, thumbnail, upload date, duration, embed URL) for Google's video rich results. Skipped, like the breadcrumbs above, for content the current visitor can't view.

Member features (optional plugins — see Plugins below)

    Favorites (/favorites) — bookmark a series or video.
    Watch later (/watch-later) — a separate queue from Favorites.
    Comments — discuss a series or video, one level of replies deep; authors can delete their own comments and replies, moderators can delete any (see Permissions). Any other logged-in member can report a comment; reported (and moderator-hidden) comments surface in the /admin/comments moderation queue, where a moderator can hide (without deleting) or delete them — see Admin CMS below.
    Ratings — a 1-5 star rating on a series or video; average and count shown to everyone, the stars are only clickable when logged in.
    View counts — a simple counter shown on series/video pages.
    Social share — copy-link and share-to-X/Facebook buttons. Video pages also get a "Share at" mm:ss field that copies a link back to that moment (?t=<seconds>, read on load to seed the player's start time — takes priority over the viewer's own resume position), and each chapter in the player gets its own 🔗 copy-link button using that chapter's timestamp. There's no "share from where I'm currently watching" yet — Bunny's embed does support reading live playback position via Player.js, just not wired up for this.
        Hymns and books share too. A hymn is the thing in this app most worth sending somebody — "we're singing this on Sunday" — and had no way to be sent. The same buttons now sit on a hymn's page and a book's, and the reader's bottom bar has a Link button for the hymn open in front of you. That one links by number (/books/<id>?hymn=214) rather than by page, because a page number means nothing to somebody holding a different edition and stops meaning anything here the moment the book is re-scanned; only an unnumbered spot falls back to its page.
        Not offered on a members-only hymn or book: a link a stranger can't open is worse than no button.
    Announcements — a dismissible (per browser session) site-wide banner, optionally scheduled (start/expiry time) and targeted to guests, members, or everyone.
    Notifications — opt-in Web Push, sent when an admin publishes a video. Each member picks a frequency on /profile: Instant (the default) pushes the moment content publishes, Daily digest queues notifications and delivers one batched push a day via a scheduled job. The selector only appears while this plugin is on. A member can also opt into an email copy of the same notifications — a separate, always-instant channel (independent of the push frequency choice) that reaches members without a push subscription at all; see the technical note below.
    Subscriptions (/subscriptions) — follow a series or category; when a followed series publishes a new video, its subscribers get a push notification (in addition to, and independent from, the general Notifications plugin above). Each subscription has a mute toggle that keeps the follow but skips push notifications for it.
    Playlists (/playlists) — member-created, ordered, reorderable video collections, separate from the single site-wide Watch Later queue. A playlist can be made shareable ("Make shareable"), which lets anyone with the link view it read-only at /playlists/[id] without logging in — otherwise it's only visible to its owner.
    Likes / dislikes — a thumbs up/down on a series or video, shown alongside (and independent from) the 1-5 star Ratings plugin.
    Watch history — gates the /recently-played page and its bottom-nav tab, matching the toggle pattern of the other member features.
    Profiles — lets a member set a display name, shown instead of their Auth0 account name in comments and the navbar. Blank falls back to the Auth0 name, then the email. The field lives on /profile/settings; the profile area itself is always available (see The profile area below), since it also holds the inbox, shared links, and account settings.
    Share links — lets a member create a revocable link to a series or video, either public or emailed to named people. See Share links below.
    Downloads — lets members save videos to their device and watch them with no connection. See Downloads below.
    Book reader — opens PDF and EPUB files in an in-app reader rather than only offering them as downloads. See Book reader below.
    Chapters — an admin-managed, ordered list of named timestamps on a video; the video page shows a jump-to-section list underneath the player. Clicking a chapter reloads the embed starting at that timestamp (Bunny's iframe has no seek API — see the technical note below).
    Transcripts — a full-text transcript per video, shown in a collapsible panel; when this plugin is on, /search also matches against transcript text (weighted below a title/description match). Pasted by an admin, or written automatically: see below.
    Recommendations — a homepage "Because you watched X" row for logged-in members, anchored on the series of their most recently watched video and reusing the same same-category/shared-tag logic as related content.
    Sermon note sheets — the fill-in-the-blank sheet handed out at the door in a great many churches, as a page in the app. An admin writes the outline as plain text with three or more underscores marking each gap; the congregation fills it in while the talk is going on, and keeps their copy.
        Saved as it is typed, not on a button: somebody filling this in is listening to something else at the time, and a Save they forget is the whole sheet lost.
        A gap is identified by its position, so an outline edited afterwards can leave an answer against a gap it was never written for. Rather than guess, the sheet says it has changed — the version it was filled in under is stored with the answers.
        Signed-out visitors get the sheet to read, print and copy; keeping answers needs an account, and it says so.
    Sermon notes — a member's own private, timestamped notes on a video (e.g. "12:03 — great point about grace"), added while watching and exportable as a plain text file. The timestamp is manually entered, the same limitation as Chapters (see the technical note below).

Watch progress extras

    Mark as watched — a manual toggle on the video page sets or clears WatchProgress.completed directly, independent of the heartbeat approximation — useful when a member watched elsewhere, or the heartbeat missed the very end. Affects the same completion flag that gates sequential unlock and feeds the watch-through-rate analytics.

The profile area (/profile)

One account area, the same on the web and in the installed PWA, reachable from the navbar and from a Profile tab in the mobile bottom nav (with an unread badge). Five sections:

    Overview — what's waiting: unread count, active shared links, and shortcuts into favorites/playlists/watch later/recently played.
    Inbox (/profile/inbox) — every notification the site has sent this member, kept as Notification rows written alongside each push/email send. It fills up whether or not push was ever allowed, so it works as the catch-up record on a device that never got the notification: mark one or all read, open the linked content, delete individually or clear the lot. The push permission toggle sits at the top of this page.
    Shared links (/profile/shared-links) — every link this member has handed out, with its status, recipients, open count, and a Revoke button.
    Downloads (/profile/downloads) — the Wi-Fi-only vs Wi-Fi-or-mobile-data preference (stored per device, live now) and a placeholder download list; offline playback itself is still to come.
    Settings (/profile/settings) — two groups, in this order:
        This device (localStorage, works logged out, differs per device): Theme (System/Light/Dark), Language (English only for now, the selector is disabled), Autoplay, and Default playback speed.
        Account (applies wherever they log in): display name (Profiles plugin), notification frequency and email opt-in (Notifications plugin), phone number and announcement consent.
        Your data: Download my data, then Delete account — the two halves of the same decision, deliberately next to each other.

Notes on the device settings:

    Theme is a dark/light class on <html>, stamped by a blocking inline script (THEME_INIT_SCRIPT) before first paint so the page never flashes the wrong theme; a ThemeSync client component keeps "System" following the OS while the page is open, and picks up changes made in another tab. Tailwind's dark: variant is redefined to key off that class (@custom-variant in globals.css), with the old prefers-color-scheme media query kept as a no-JS fallback.
    Autoplay genuinely starts the video and drives the "Up next" roll-on — the toggle in the Up next panel is the same preference, not a second one.
    Default playback speed is stored and shown as a reminder under the player, but can't be applied automatically: Bunny's embed takes no playback-rate parameter, and Player.js's documented methods don't cover setting one either (see the technical notes).
    Delete account requires typing the account's own email address, then hard-deletes the User row — every relation cascades (comments, notes, playlists, favorites, watch progress, push subscriptions, share links they created) and the browser is sent to /auth/logout. The only trace left is the audit-log entry, which stores an email rather than a foreign key. The last remaining admin is refused, since that would leave nobody able to grant access again.

Download my data (GET /api/profile/export)

One JSON file holding everything the app knows about the member who asked for it, saved as marine-team-<name>-<date>.json. It is the counterpart to deleting an account: leaving shouldn't mean losing four years of sermon notes, and "what do you actually hold on me?" deserves an answer that isn't an admin running queries by hand.

Not behind a plugin. Every other member-facing feature here can be switched off by an admin; this one answers a question a member is entitled to ask, so there is no switch for it.

What's in it: the account row and its sign-in methods; favorites, watch later, follows, playlists, ratings, reactions and watch history; reading positions, highlights and bookmarks; sermon notes and outline answers; comments and the reports they filed; teams, rota assignments and unavailable dates; event sign-ups; form and connect-card submissions; prayer requests they wrote; small groups; notifications and announcements they were sent; live-chat messages; push and television devices; share links; and every access grant held against their name.

Three rules decide the rest, and they pull against each other:

    Completeness. If a row is keyed to the member, it's in the file. A partial export is worse than none, because it looks complete.
    Nobody else's data leaves with it. Most of what a member touches here is shared. So a comment carries isReply: true but not the comment above it; a prayer they interceded for is a request id and a date, not somebody's words; a group's address follows canSeeAddress from lib/groups.ts — the same function the group's own page uses, so being on the waiting list gets an area and no house, and the two rules can't drift apart. The moderator who hid a message and the staff member who sent an announcement aren't named.
    No live secret goes into a downloaded file. An export gets emailed to a laptop and forwarded to a solicitor. Web Push keys, television tokens and share-link password hashes are capabilities, not facts, and stay out. Push devices are listed by their push service (https://fcm.googleapis.com) rather than the endpoint that would let a reader push to that phone.

Two guards keep it that way. assertExportSafe walks the finished document — arrays included, since an export is almost all lists — and throws rather than filtering if a forbidden key appears anywhere: a credential reaching that point means a query changed, and stripping it silently would hide that. And a test reads data-export-query.ts as text, asserting every Prisma call is scoped to one userId and that the file never uses include: — a findMany that forgets its where exports the whole congregation, and does it quietly.

Rate-limited to two exports a minute per member (counted from the audit log, which also records each one), since it is the most expensive thing any logged-in member can ask for.
The read API (/api/v1)

Another system reading this one: a noticeboard in the foyer, a spreadsheet, the main church website, a migration off some other platform. Until now the answer to "can I get our own data out" was an admin running queries by hand.

Everything is a read. There is no way to change anything through the API in v1, and that is a deliberate order of work rather than an oversight: a token that can rewrite the diary is a much bigger decision than one that can read it, and shipping the read half first means nobody has to make both at once. /api/v1 says so in its own response.

    A key is a password a machine keeps in a config file, so it is treated like one. Only its SHA-256 is stored, it is shown once at /admin/api-keys and never again, and the list afterwards shows a prefix (mt_live_A1b2C3…), who made it and when it was last used — the three things anybody wants after deciding one has leaked. Revoking keeps the row, so the record survives the key.
    Scopes, with no hierarchy. events:read gives you "forty people are coming"; events:registrations gives you their phone numbers, and one does not follow from the other. The two scopes that carry personal data are badged as such on the form, because a form that describes them in the same tone as the rest is a form where both get ticked without thinking.
    A group's address has no scope at all. It is not that you need a special one — there is no combination of ticks that returns it, because an address travels only with a leader's yes and a machine cannot be given one. The member list is out for the same reason: a directory of groups is a different thing from a directory of who is in which home group.
    assertNoSecrets is the last thing before the bytes leave, and it is the same guard the member data export uses, from the same list. Both are places where a query quietly changing shape becomes a leak; one list is what stops the two drifting apart. It throws rather than filtering, because a credential reaching that point means a query changed.
    Content comes back including drafts and members-only items, with flags. A key is the organisation reading its own catalogue, and hiding half of it would make the API useless for the reporting and migration jobs it exists for. What a visitor may see is a different question, answered on the pages.
    Cursor paging, not offset. Rows are added while a caller reads, and ?offset=50 silently skips or repeats whatever moved across the boundary. A page asks for one row more than it needs, which is how "is there another page" gets answered without a COUNT over the whole table.
    120 requests a minute per key, counted in a single UPDATE rather than a read followed by a write. Under 30-way concurrency the atomic version serves exactly the number of requests that were left; the read-then-write version served 60 where 30 remained. A refused request still counts, since it still cost a lookup.
    GET /api/v1 needs no key and describes the whole thing — scopes, endpoints, filters, paging, limits. An API whose own documentation needs a credential wastes the first five minutes on the wrong problem.

Endpoints: /me, /categories, /series, /videos, /files, /events, /events/{id}/registrations, /schedules, /calendar-events, /groups, /analytics. Files filter on addedSince rather than updatedSince, because FileAsset has no updatedAt — a file is replaced rather than edited, and calling it the same thing would let a sync job believe it had seen every change.
Share links

A revocable, tracked link to one series or video, opened at /s/[token]. Unlike copying the page URL, the sharer keeps a list of what they've handed out, sees how often each link has been opened, and can switch any of them off.

    Who can share, and the members-only override. Overriding the gate is opt-in per link — a checkbox on the share form, not a consequence of who is sharing. Three outcomes, enforced in shareLinkPolicy:
        Content that is already public to anyone: any logged-in member can share it (with the Share links plugin on). There's nothing to override, so the checkbox doesn't appear.
        Gated content (memberOnly, or restricted to viewer groups/users) with no override: any logged-in member can share it. The link is a plain tracked link — it only opens for someone who already has access, which is what makes "here's the one I was watching" safe between two members.
        Gated content with the override ticked: only an admin or someone holding the share_content capability, and their link carries a real access grant. This is how one guest gets into a members-only series without loosening it for anyone else. The capability can be granted site-wide or scoped to a category/series, so a group can be given sharing rights over just their own section — and a scoped holder is still refused an override outside that scope.

    The capability is permission to override, never an automatic one: an admin who leaves the box unticked sends an ordinary link. Links carrying an override are badged "Grants access" in both listings.

    Optional password. Any sharer can add a passphrase to a link (at least 6 characters), independent of public vs private — so a public link can be "anyone with the link and the password". Opening it lands on /share/unlock/[token], which asks for the passphrase before the link redeems; getting it right is what sets the cookie, so nothing is granted and no open is recorded until then. Once unlocked, that browser isn't asked again. Stored as a salted scrypt hash (src/lib/share-password.ts), never returned to any client — not even to the sharer, who can't be shown it again — and never included in the recipient email, which only mentions that a password is needed. Ten wrong guesses inside 15 minutes and the link stops answering for 15 minutes; the tally is kept on the row (serverless has no shared memory, same reasoning as src/lib/rate-limit.ts) and clears itself on success or once the window passes. To change a password, revoke the link and make a new one.

    Public vs private. A public link opens for anyone holding it, logged in or not. A private link is addressed to specific emails: each recipient is emailed their link (and gets an inbox notification if they already have an account), and opening it requires being logged in as that address — so forwarding it on doesn't hand over access.

    How the grant works. /s/[token] validates the link, records the open, and stores the token in an httpOnly share_access cookie before redirecting to the content — so the recipient keeps access as they browse the rest of the series instead of losing it on the first click. The cookie holds tokens only, never a grant: every request re-checks each one against the DB (revoked? expired? right recipient?), so a revoke takes effect immediately even for a browser that already holds the link. canViewSeries/canViewVideo/getViewableVideoIds consult the resolved grants first, which is also what makes a shared link work for someone with no account at all.

    Where to share. A "Share a link" panel on any series/video page the member is allowed to share, which also lists their existing links for that content. /admin/share-links (visible to admins and share_content holders) lists every link on the site with its owner, filters by active/revoked, revokes any of them (audited), and can create a link for any series or video without navigating to its page.

    Expiry and revocation. Optional expiry of 1–365 days; revoking sets revokedAt rather than deleting the row, so a dead link stays visible in both lists. Recipients of a link that no longer works land on /share/unavailable, which says specifically whether it was revoked, expired, or meant for another account.

    Limits. Only published, visible content can be shared (an unpublished or trashed item wouldn't resolve on its own page either). Members are capped at 20 new links an hour. Withdrawing someone's share_content capability does not retroactively kill links they already created — that's what the admin list and its Revoke button are for. A link's password and expiry can't be edited after the fact; revoke and re-share instead.

Downloads

Offline viewing: a member saves a video to their device and it plays with no connection at all. Four independent controls decide whether the ⬇ Download button appears under a video, and all of them have to pass.

    The feature — the Downloads plugin, site-wide at /admin/plugins, with the usual per-category override (turn it off for one branch of the tree and everything under it loses downloads).
    The content — a three-way Downloads setting on every category, series, and video: Inherit, Allow, or Block. The most specific wins: video, then its series, then the nearest ancestor category that has an opinion, then allowed. Three states rather than a checkbox because "not set" has to differ from "off" — a series left inheriting follows its category later too, when that category changes. Set it on the category and series edit pages; on /admin/videos it's a per-row button that cycles Inherit → Allowed → Blocked.
    The people — /admin/downloads chooses between any member and only certain groups or people (permission groups and/or named individuals, the same shape as a restricted item's viewer grants). Admins can always download.
    The platform — the same page picks web and installed app, installed app only, or web only, so a church can keep offline files to the PWA where they belong.

None of this can widen access: /api/downloads/[videoId] calls canViewVideo first, so a member can only ever download something they could already watch. The platform is the one thing the client asserts (only the browser can see display-mode: standalone), which is why it's a placement rule rather than a security boundary — it never affects who or what, only where the button shows.

How a download actually works:

    The API hands back a short-lived signed MP4 URL (the same CDN token scheme the thumbnails use, 30-minute TTL) at the best resolution Bunny actually generated — never a guessed height. This needs MP4 Fallback enabled on the Bunny Stream library, and only for uploads made after it was turned on — HLS segments can't be handed to a <video> for offline playback, and Bunny doesn't retroactively generate MP4s for older videos. resolveMp4Source (src/lib/download-source.ts) reads Bunny's per-video hasMP4Fallback / availableResolutions (cached on the Video row, synced by the sync-status routes) rather than assuming a fixed height, and returns one of several specific reasons — no fallback generated yet, nothing at or under the configured resolution cap, the CDN rejected the request (403 — almost always a token/pull-zone setting, not a missing file), or the file really is missing (404) — so a misconfigured library reads differently from an unencoded rendition, which reads differently from a video nobody's re-uploaded since enabling the setting.
    The browser streams the file into Cache Storage with a progress bar, under a /offline-video/<id>.mp4 key on our own origin. The service worker answers those URLs from the cache — including range requests, so seeking works — which is what lets an ordinary <video> element play with the network off.
    The download cache is deliberately excluded from the service worker's activate-time cleanup, so shipping a new version never wipes someone's saved videos.
    Everything about what is downloaded is per device and never leaves it: the file list lives in the browser's own storage, so the server can't tell you what's on your phone, and downloads don't follow you to another device.
    Opening the app with no connection lands on the downloaded videos, not a browser error. The rest of the site is intentionally never cached (it's dynamic and often auth-gated), which means a plain page load with no network — including the installed PWA's own start_url on a cold launch — has nothing to serve and would otherwise hit the OS's own "you're offline" screen, with no way to reach a video already saved to the device. public/ offline.html is the one exception: a static, unauthenticated, data-free page precached at service-worker install time. It reads the same localStorage download index this feature already writes and plays straight out of Cache Storage — no server round trip. The service worker's fetch handler serves it for any navigation whose network request fails (event.request.mode === "navigate", caught and swapped for the cached fallback); everything else still goes to the network first, so this never makes a page look stale.

Members manage it all at /profile/downloads: whether downloads are available to them (and why not, if not), the Wi-Fi-only vs mobile-data preference, how much space is used against the admin's suggested cap — videos and books together, since they share the device and a hymnal is often the largest thing on it, with the browser's own quota shown beside it where the browser will report one — and per-video Play offline / Remove. The list self-heals — browsers evict caches silently under storage pressure, so entries whose file has vanished are dropped on load rather than offering playback of something that isn't there.
Books on the device

A hymnal can be saved the same way, and then read with no connection at all. Save for offline on a book's page (/books/[fileId]) stores it; the same page's Remove takes it back off, and /profile/downloads lists every book this device is holding. Gated by the same Downloads plugin as video, with the same per-category override, and then by the member choosing to save a particular book.

Saved books are checked against the live ones whenever you're on their page or on /profile/downloads: a book that has been replaced, or a hymnal whose lyrics have been corrected, is marked Update available, and one that is no longer available to your account says so. The check is deliberately cheap — a PDF is asked for a single byte with a conditional request, so an unchanged book answers with nothing at all — and nothing is ever removed automatically: a saved book leaves a device when you say so.

Saving stores three things, because reading a hymn offline needs all three:

    The file, streamed into Cache Storage under /offline-book/<id>.pdf, fetched through the app's own content route so access is checked exactly as it is for reading the book online.
    The contents list, read out of the bytes that were just downloaded rather than fetched all over again. Without it there is no way to find hymn 214 with no connection, which is most of the point.
    The reader itself. The offline screen is a static page with no application bundle behind it, so it has no way to render a book unless the library is already on the device: pdf.js for a PDF, epub.js (with JSZip, which its build expects as a global) for an EPUB. They're copied out of node_modules into public/ at install and build time (scripts/copy-offline-viewers.mjs, so they stay the versions package.json pins) and fetched once, when the first book that needs one is saved — a library of EPUBs never pulls pdf.js's 1.7MB.

Both kinds of book the reader opens can be saved — a PDF and an EPUB alike. What differs is the fallback when the library can't be loaded: a PDF can be handed to the browser's own viewer, while no browser renders an EPUB on its own, so that offers the file for whatever reading app the device has.

A hymn-per-file book — one whose hymns are separate files rather than one PDF — is saved from its own page, and stores something different: there is no document to keep, so what goes on the device is the list of hymns with their lyrics. Offline it reads as the book does online — hymns in printed-page order under their group headings, a find box that matches a number, a title or a line of the lyrics, and Back/Next stepping hymn to hymn. Two things follow from what it stores: hymns with no lyrics text aren't saved (offline they would be blank pages, since the file behind them isn't stored), and because lyrics get corrected long after a scan would have settled, the button offers Update as well as Remove.
Searching a hymnal section

The hymns of a scanned hymnal exist only in that PDF's embedded bookmarks, so they were invisible to search: a category with six books offered six books and no way to ask which one has the hymn you want.

An admin fixes that once per book, with the same pass that draws the covers (/admin/files → Index books): it opens each PDF, resolves its contents to page numbers and stores them. After that:

    The section's own page carries a search box across every indexed book in it — by name, by the number on the board, or by a line of the hymn — answering as you type, with each result opening the reader at that page.
    The site-wide search finds them too, listed with the hymns that have their own lyrics page under Hymns & books.
    Section headings ("Advent", "Communion") are searchable as well: they have a page like anything else, and are worth going to.

Two things follow from how it's stored. Pages are kept as PDF pages, like every other stored position in a book, so correcting a book's page offset relabels every result without reindexing. And a book that has never been indexed simply isn't in the results — the box doesn't appear at all in a section where nothing has been indexed, rather than looking broken.
When a PDF has no bookmarks

Most cheaply scanned hymnals have none: the file is six hundred images and nothing else, so the indexing pass finds nothing and the whole section stays without a search box. The contents are printed in the front of the book, so they can be typed instead — Type contents… in a file's Details panel in /admin/files.

One hymn a line: its label, then the page it starts on. 214 Amazing Grace | 230 — a tab or a pipe separates them, and so does the last number on the line, so a column pasted out of a spreadsheet works as it is. Indent two spaces to put hymns under a section heading. Pages are the ones printed in the book; the book's page offset is applied for you, and pdf:2 names a page of front matter, which has no printed number.

A line that can't be read is listed with its number rather than dropped — a silently missing hymn is the failure this exists to fix — and the box counts what it will save as you type. It opens an already-indexed book too, so a bookmark reading "214 Amazing Grac" can be corrected without re-scanning anything; what comes back is written exactly as it was stored, nesting included. The bookmark-reading pass will not overwrite a typed list with an empty one.
A hymn's credits

Five fields sit beside a hymn's words, in the same two places the words do — a file's Details panel for a hymn that is its own file, the Hymn lyrics… picker for one inside a book: CCLI number, words & music, copyright line, key and tempo.

    The copyright line is shown on the projector, small, at the foot of the screen, for as long as the words are up. Not with the controls, which fade after three seconds — a licence requires the line to be visible while the words are, and something that disappears on its own doesn't meet that.
    The key and tempo are for whoever is playing, and show on the hymn's page.
    The CCLI number is what the report below is for.

What we sang, for a licence return

/admin/services → What we sang counts every song in a service plan dated inside a window, and how many services it was sung in — the shape a licence return asks for — with its CCLI number, author and copyright beside it, and a CSV export.

Counted from the plans rather than from what anybody looked up: a plan is the record of what was actually sung, where a hymn opened on a phone on Tuesday isn't. Every plan in the window counts, published or not — a draft that never got published was still sung if it has a date, and under-reporting a licence return is the worse mistake. A song with no CCLI number shows an empty cell rather than being left out: that blank is the thing somebody has to go and look up before the return can be filed.
Words for a hymn inside a book

A hymn that is its own file keeps its lyrics on its row. A hymn inside a six-hundred-page scan has no row of its own, so its words had nowhere to live — and a service built from book numbers offered no Present button at all.

Hymn lyrics…, in the same Details panel, is a picker over the book's indexed contents: find the number, paste the words, move on. Typing six hundred hymns isn't the expectation; typing the twenty a congregation actually sings is.

The words are kept against the book and the hymn number, not against the contents row — so re-indexing the book, re-scanning it, or retyping its contents doesn't lose them. The trade is that an unnumbered entry has nothing to key on and can't have words stored. Once typed, a hymn:

    offers Present on the book's contents page and on every service plan row that names it, not only the first one — a hymn gets sung out of order;
    is found by a line of its words, in the section box and in the site-wide search, with the line that matched shown under it.

Reading a scanned book's text

Search-in-the-book and read-aloud both work off a PDF's text layer, and a scan has none — so on most hymnals they quietly did nothing. Read this book's text…, again in Details, reads every page: from the file's own text layer where there is one, and by OCR off the image where there isn't.

    A typeset book is read in seconds. A scan takes a few seconds a page, so a hymnal is the better part of an hour — leave the tab open.
    Stop whenever you like. Pages are stored in tens as they are read, and starting again carries on from the first page not yet held. A book only counts as finished when a run reaches its last page.
    Once read, searching inside the book answers from the stored text instead of parsing the open document — one request, and it works on a photograph. Results read off a scan say so, since OCR misreads a word here and there.
    The section search uses it too: a page that matches is attributed to the hymn it falls inside (the last contents entry at or before that page), so what comes back is still a hymn to open rather than a page number. This is the only way a hymn nobody has typed the words of is findable by its words.
    Replacing the file throws the reading away with the rest of what described the old bytes.

The OCR engine (tesseract.js) is served from this app's own /tesseract, not a public CDN — copied out of node_modules at install time like the offline readers, so it works on a filtered office connection.
Replacing a book

A re-scanned hymnal is the same book, so it keeps the same row. Replace the file, in a file's Details panel in /admin/files, points that row at new bytes: a small file uploaded straight from the panel, or — for anything past the app's 4MB upload cap, which a scan always is — an object uploaded to Bunny Storage and chosen from the same listing the importer uses.

Doing it this way rather than adding the new scan as a new file is the whole point. Everything that refers to a book refers to its row: where each member got to, their marks, the ?page= links on its contents list, its podcast episode, and every copy saved to a phone. A new row leaves all of that on the old book with nothing to say it has been superseded.

What it does and doesn't touch:

    The title, series or category, page offset and every other setting stay. Check the page offset if the new scan's front matter differs — the stored page numbers are unchanged, so the printed numbers shown beside them follow whatever the offset says.
    Saved places and marks stay, and they are page numbers: a scan with different pagination will move where they land.
    The cover, hymn count and indexed contents are cleared, because they described the old file — re-run Index books. Until then the book's hymns are absent from search rather than pointing at the wrong pages.
    A podcast episode is re-copied to the public zone from the new bytes.
    Devices holding this book offline show Update available the next time they're on its page or /profile/downloads.
    The replacement has to be the same kind of book — a PDF for a PDF, an EPUB for an EPUB — since every saved place is in that format's own terms.
    The old object stays in Bunny Storage, at its own path (the new bytes never overwrite the old ones, or the CDN would keep serving the old file). It turns up in the storage importer, where it can be dealt with once the new scan has been checked.

What the offline screen shows

public/offline.html is served for any navigation that can't reach the network, and it is now a small app of its own rather than a list of videos:

    The same bottom bar the app draws. The app leaves a snapshot of its tabs where this page can read it, so losing the connection no longer loses the icons. Sections holding something saved on this device are shown in full colour; the icons are the app's own.
    What is saved, under the icon you tapped. The page is served at the URL that was asked for, so it knows whether you tapped Hymnals or Home. Tapping Hymnals lists the hymnals — including books filed under a series inside that section — and a link straight to a book (/books/<id> or /read/<id>) opens that book.
    The hymn number, typed. The same Go to hymn box as online, above a saved book's contents — the one moment it matters most, since a service with no signal is exactly when nobody wants to scroll a list.
    A book's contents, then its pages. Entries are listed with the numbers printed in the book (the page offset is stored with it), and opening one draws that page with pdf.js: swipe left and right, arrow keys, zoom, and a bar naming the hymn you are on. Where a browser can't draw the pages itself, the book is handed to that browser's own PDF viewer at the right page rather than showing a blank sheet.
    Or an EPUB's chapters, rendered by epub.js exactly as the in-app reader does — scrolling rather than paginated, with the chapter you're in named in the bar. The arrow keys and the swipe are registered inside the frame epub.js owns, which is where the reading actually happens.
    Or a hymnal's hymns, then one hymn's lyrics, for a hymn-per-file book — searchable, grouped, and steppable with Back and Next. A link to a single hymn (/hymns/<id>) opens it directly when its book is on the device.
    A saved service's running order. The books are forty megabytes; the sheet saying which hymns to open is two kilobytes, and it is the thing you need first. Each hymn is listed with its number, its name and any note, and a hymn whose book is on this device opens that book at it. One whose book isn't says so on the row rather than offering a button that does nothing.
    Downloaded videos, exactly as before.

The in-app reader survives the connection dropping while it is open, too: the service worker answers /api/files/[id]/content from the saved copy — but only after the network has actually failed, and only for a book this device was deliberately given. A cached copy never stands in for an access check that said no.
Services

The hymns for a service, in the order they'll be sung. Staff build a plan in /admin/services — a title, a day, a note, and the hymns — and publish it; members open /services, pick the day, and tap straight through to each hymn. Gated by the Service plans plugin.

A plan holds the two shapes a hymn takes in this app (see the Hymnals notes above):

    A hymn that is its own file opens at its lyrics page.
    A number inside a whole-book hymnal is written down as the number that goes up on the board. The page it lands on is worked out from that book's own contents when a member opens it — the browser reading the PDF is the only thing that knows which page hymn 214 is on, so the plan links to the book's contents carrying the number and the contents page does the rest.

This is deliberately not a playlist: playlists are a member's own, hold videos and have no date. A plan is one copy that everyone in the building opens, and it stays a draft until somebody is happy with the order.

A hymn that has since been unpublished, or one a signed-out visitor can't open, still appears in the order rather than leaving a gap — it is being sung either way — and says why it doesn't open.

Each row is named by the hymn, not the book it is in. A plan item points at a file and a number, and the file's title is the book's — so an order built from one hymnal used to read "214 Church Hymn Book, 302 Church Hymn Book". The book's indexed contents know what 214 is called, so they are asked. A book nobody has indexed still falls back to its own title.
Taking the order with you

    Keep this order offline saves the running order to the device. It is a couple of kilobytes against a hymnal's forty megabytes, so it is worth doing on the way out of the house; the books themselves are saved separately, from their own pages, and the button says so rather than implying it took them too. With no connection, the plan is on the offline screen, and a hymn whose book is also saved opens straight to it.
    A running order gets reordered up to Saturday night, so a saved copy is checked against the server whenever the page is opened: a plan that has changed since says Order changed — update rather than being quietly wrong in somebody's hands.
    Print the order hands the whole thing to paper — the numbers, the names and the notes, with the app's chrome, its buttons and its "members only" badges left off. Gated by the same Downloads switch as saving anything else to a device.

Who is serving (the rota)

A running order says what is being sung; a rota says who is there to do it. Both live on the same plan.

    Teams (/admin/teams) are the groups you schedule from — musicians, welcome, sound, readers — each with its members and what each of them usually does, which is offered as the default when they're scheduled.
    Building the rota happens on the plan itself, under the hymns: pick a team, pick a person, name the job. Asking somebody sends them a notification on the same three channels as everything else (push, email if they opted in, and the profile inbox that works whatever they allowed).
    They answer. /profile/rota lists what a member has been asked to do, with Yes, I can and Can't make it — and a decline can carry a reason, because a "no" without one just moves the conversation to text message. That answer is what the person building the rota sees beside each name, and it is the whole difference between a rota and a list.
    When you're away. A member can record dates they can't serve. Whoever builds the rota is warned that somebody is away before they ask — it does not stop them asking, because sometimes a rota is a conversation.
    On the service page, the people serving are listed for everyone who opens it — but only those who have said yes. An outstanding ask is a conversation between two people, not a notice board.

An unanswered ask stays on a member's rota page even after its date has passed: it doesn't stop being unanswered because the day went by.
Schedules (/calendar)

The other kind of rota, brought over from the calendar app: any number of recurring schedules — Breakbread, Welcome, Sound, Senior Visit — read by people who never log in, and fed either from a Google Sheet somebody already maintains or from the admin interface here.

Deliberately separate from the service rota above. That one puts accounts against a service's running order. This one puts names against recurring rotas, and most of those names have no account and are not going to make one. Gated by the Schedules plugin.
For everybody — and what is only for members

    The dates are public; the names need a sign-in. Which rotas exist, on what days, with what notes and where — anyone with the URL. Who is on them — members. The calendar app this came from published every name to anyone, and ported as-is that sat oddly beside a directory that needs opt-in and sign-in before a name appears. Now the rule is the same as everywhere else here, and it is enforced the same way: a signed-out reader is handed events with nobody on them (lib/schedules/visibility.ts), not events with names to be hidden. /api/people is a 403 without a session; the event and snapshot endpoints strip people and refuse a personId filter.
    Choose your name once, signed in: it is a preference on that device, like the theme, so it differs between your phone and the church laptop. "Everyone" is a first-class answer.
    What's next, a list by day, or a month grid, filtered to one schedule with the chip row and to yourself with Only mine.
    The page is not indexed: even without names it says when the building is in use.

For whoever keeps the rota (/admin/schedules)

    A schedule is managed here or fed by a Google Sheet, and everything downstream — the calendar, the API, the reminders — cannot tell which. Switching one over later keeps the events already imported, as ordinary editable rows.
    Test connection shows the first few events exactly as the parser read them, plus every row it skipped and why, before anything is imported. A column mapping is guesswork until you can see what it made of the sheet.
    Two spreadsheet layouts are understood: Date | Names with everyone in one column, and Date | Devin | Cindy | … with a column each marked ×. A cell with other text in it doubles as the job ("Bread"). Columns obviously not people — Notes, Week, Location, Time — are skipped, so a sheet with a notes column doesn't acquire a person called Notes.
    Dates are read forgivingly: July 10, july 10th, Sunday, July 12, 7/10, 2026-07-10 and a real spreadsheet date all work. A cell nobody can read is skipped and reported, never guessed at — one bad row never aborts an import.
    A failed sync deletes nothing. If Google is unreachable, what was imported before stays exactly as it was, and a payload that hasn't changed upstream does no writes at all.
    People (/admin/people) are names, created automatically as they turn up. Spellings that differ only in case, spacing or accents are already one person; genuine near-duplicates ("Dave" and "Davey") are suggested for merging and never merged automatically. A merge moves the history onto one record and keeps the other spelling as an alias, so the next sync resolves to the right person instead of recreating the duplicate.

On the device

Keep the calendar on this device puts the whole year of rotas on the phone. It is the smallest thing this app can save — a few kilobytes of text against a hymnal's forty megabytes — and the only one that keeps itself current: once saved, opening the calendar with a connection quietly asks the server for what has changed since last time and folds it in. Nobody has to remember to press update to find out they are on for Sunday.

With no connection at all, it appears on the offline screen beside the saved books, videos and service orders: pick your name — the same name the app uses, so choosing it in one place settles it in both — and see what you are on for, with the day named the way the app names it. Saved while signed out, the copy holds the dates and no names; and a signed-out sync always fetches a full snapshot, so a copy saved on a shared laptop while somebody was signed in stops carrying their names the next time it updates.

Two things the payload never says, the device works out for itself, because getting either wrong means somebody turning up when they shouldn't:

    A schedule that was turned off takes its dates with it. Disabling a schedule doesn't touch one event row, so those dates are never reported as changed or deleted. They would otherwise sit on the phone for good.
    Days that fall out behind the window are dropped, for the same reason: nothing deletes them, they simply stop being sent.

Reminders, and what didn't come across

A daily job tells people what they are on for tomorrow, one message however many rotas they are on — through this app's existing push, email and profile inbox rather than a second notification stack.
When somebody can't make it

The rota models an ask and an answer. What it had no word for is the thing that happens most often after a yes: something comes up. Without one, the whole exchange goes to text message and the rota keeps saying somebody will be there who won't.

    "Ask someone to cover this" puts the slot in front of the rest of that team — and nobody else, because a cover request is addressed to the people who could do the job. Everyone on the team is told once.
    Somebody unanswered can ask too. "I've been asked and I can't — does anyone else want it?" is a real thing to say, and making them accept first in order to hand it on would be a step that exists only to satisfy a state machine. Somebody who has already declined can't: there is nothing to cover.
    The slot changes hands, once. Two people pressing "I'll take it" in the same second is not rare on a Sunday morning, so the write is conditional on the slot still being open and still being held by the person who asked — one winner, and the other is told somebody beat them rather than both being thanked.
    A volunteer who marked themselves away is warned, not refused. They have almost certainly changed their plans, and a rota that argues with the person offering to help is a rota nobody helps with. Being already on that service is a refusal, with the reason said plainly rather than a caught database constraint.
    The rota remembers whose it was. The organiser's page shows needs cover against an open request and covering for X once somebody has stepped in, so a swap reads as a swap rather than as the name quietly changing.
    The old note doesn't follow the slot. It was the previous person's aside to the organiser, and carrying it onto somebody else's acceptance would put words in their mouth.

That has a consequence worth stating plainly: somebody on a rota with no account gets no reminder. The calendar app reached them through anonymous per-device subscriptions; this app's push is keyed to an account. Linking a name to a member's account is what turns reminders on for them, and everyone else still has the calendar, which is the source of truth.
Events

What's on, at /events, and who is coming.

    A date, a place, a description, and optionally sign-up. Plenty of events are worth publishing with nothing to fill in — a carol service everybody simply comes to — so sign-up is a switch rather than an assumption.
    You don't need an account to sign up. The people a church most wants at a men's breakfast are the ones who have never made one. Members only is how an event closes that, and such an event is invisible — not refused — to anyone not signed in, because a title is a leak too.
    Places, and what happens when they run out. A guest counts as a place, because a guest sits somewhere. When it is full, sign-up becomes a waiting list, or refuses outright if the waiting list is turned off.
    The last place goes to one person. Everything that decides "is there room" happens under a lock on the event, so four people pressing the button in the same second get one yes and three places on the list — not four yeses and an overbooked hall.
    The waiting list moves by itself when somebody drops out, and again when an organiser raises the capacity. It stops at the first party that doesn't fit rather than skipping ahead to a smaller one: two places free and a family of four at the front means nobody moves, because passing over that family to seat the couple behind them is exactly what people notice and rightly resent. Whoever moves up is told.
    Members see their own sign-ups at /profile/events, and can cancel from there or from the event.
    Organisers get the list, on screen and as a CSV for the door, a mail-merge or a spreadsheet — with a column saying who is a member, which is the one thing the sign-up form didn't ask.

Something that repeats

A weekly Bible study used to be twelve events typed twelve times. Now it is a rule, set up at /admin/events, kept filled in six months ahead by a daily job.

A series is not itself an event. Every date it produces is an ordinary Event row with its own slug, capacity and sign-up list, because "is there a place for me on the 14th" is a different question from "is there a place on the 21st" and only a real row can answer both. The occurrence's URL says which week it is for — /events/prayer-meeting-2026-01-06 — so a link in a newsletter lands on the right one.

    The rule is real RRULE (FREQ=WEEKLY;BYDAY=TU), the syntax every calendar exports, but nobody types it: the form offers the five repeats a church diary actually contains — weekly on chosen days, daily, monthly on the same date, monthly on the nth weekday, monthly on the last weekday — and the monthly ones read their weekday off the first date rather than asking twice. What it comes to is shown back as a sentence ("Every month on the last Saturday") before it is saved, because FREQ=MONTHLY;BYDAY=-1SA is not something anybody should have to read to check they picked the right one.
    Times are a wall clock and a zone, not instants. "Tuesdays at 19:30" is 19:30 in January and in July; a series expanded as instants loses an hour every March. The hour that never happens on the morning the clocks go forward resolves forward, to when people actually arrive, and the hour that happens twice in October resolves to the first of them. See lib/recurrence.ts.
    The sign-up window moves with each date. Stored as "opens 14 days before, closes 2 days before" rather than as two instants — copying one pair onto every date would close December's sign-up in September.
    Removing one date sticks. The date goes onto the series' exclusion list in the same transaction as the event is deleted, so tonight's generator doesn't see the gap in the rule and put the cancelled meeting straight back.
    Editing never rewrites the past. A title corrected in March renames every date still to come and leaves February's alone — that is what happened, and a diary that revises it is a worse record than a paper one.
    Changing the timing leaves booked dates where they are. Empty future dates are cleared out and laid down again from the new rule; a date somebody has signed up for stays put, listed under the series, so the organiser can see which ones didn't move and ring round. Moving a meeting somebody has booked is a conversation, not a database write.
    Stopping a series never deletes somebody's place. Future dates with nobody down for them are removed; anything past, and anything with a sign-up on it, survives as an ordinary one-off event. A sign-up is a promise to a person, and unscheduling is not a way to break it.
    Generating is idempotent, so the daily job, a missed run and an admin pressing save all produce one diary. A unique index on (series, date) is the backstop; the generator asking what already exists is what stops it firing.
    Members see it on the event page: the series in words, and the next few dates as links — somebody who has just missed one wants to know when the next is more than anything else on that page.

In your own calendar (.ics)

Everything this app knows the date of can be read by Google Calendar, Outlook, Apple Calendar and every phone. Three feeds, each with a different answer to "who is this for":

    One event — Add to my calendar on its page, at /events/<slug>/event.ics. A members-only event refuses this outright rather than gating it on a session: the URL is opened by a calendar application, and there is nobody there to check.
    What's on — /events/calendar.ics, a public feed to subscribe to once. Member-only events are absent for the same reason.
    Your own diary — what you're serving at, what you've signed up for, and the dates a rota names you on, at /api/calendar/<token>/marine-team.ics. Made on request from /profile/settings, and the token in the URL is the whole of the authentication, because a calendar app cannot log in. So: nobody has one until they ask, it can be replaced (which stops every calendar following the old link) or stopped, it is never cached by a shared cache, it carries X-Robots-Tag: noindex, and it is on the export's forbidden-key list so it can never travel in a downloaded file.

Details that decide whether a calendar shows the right thing:

    A declined rota date is written as STATUS:CANCELLED, not left out. Omitting it would leave the entry sitting on the phone of the one person who already said no.
    An all-day event's DTEND is the following day, because DTEND is exclusive — writing the same day is what makes all-day events vanish from month view in some clients.
    A repeating event is one entry per date, not one RRULE. That matches how it is stored — each date is a real event with its own place count and its own page — and a cancelled week is simply absent, with no EXDATE list to keep in step. RELATED-TO groups them.
    Lines fold at 75 octets without splitting a character. Octets, not characters: cutting between the bytes of an em dash produces a black diamond halfway through a word, which a calendar shows rather than rejecting.
    A sign-up on the waiting list says so in its title, which is exactly the thing somebody forgets between signing up and the day.

Not yet: CalendarEvent (the rota side) still carries recurrenceRule and recurrenceEndDate columns that nothing reads. The core they need now exists; wiring it up means deciding how expanded dates travel through the offline snapshot's delta sync, which is a separate piece of work.
Forms and connect cards

A form is built at /admin/forms and filled in at /forms/<name>. The questions are rows somebody adds, not code somebody deploys — they change every term, and "add a box for dietary requirements" shouldn't need a release.

    Ten kinds of question: short and long answers, email, phone, number, date, a drop-down, choose-one, choose-any, and a single tick box.
    You don't need an account, which is the whole point of a connect card: the person it is for walked in twenty minutes ago. Members only closes that where it matters, and such a form is invisible rather than refused.
    Renaming a question doesn't rewrite history. An answer belongs to the question, not to the words it was asked in.
    Stopping a question keeps its answers. A question is retired rather than deleted, so the responses from March can still say what they were answering, and the export keeps that column — after the live ones.
    The server has the last word on what a valid answer is. A crafted request can't invent a fourth answer to a three-way question.
    Somebody is told, at the addresses the form itself names — different forms reach different people, and the person who knows which is the one editing it.
    Responses are marked dealt with, by name. The way follow-up fails is two people each assuming the other rang.

Prayer wall

Ask for prayer at /prayer, and pray for what others have asked.

    Nothing appears until somebody reads it. Every request waits in a queue. This is deliberately not a setting to turn off: an unmoderated prayer wall on a church website is a liability with a "post" button.
    Anonymous means anonymous. A request can be posted without a name. The row still knows whose it is — so the writer can take it down and a moderator can act if it is abusive — but no screen anywhere shows it, including the moderator's own queue, because that screen gets left open on an office laptop and a photograph of it is how an anonymous request stops being one.
    Three audiences: anyone who visits the site, members, or only the people who look after prayer — for the ones that shouldn't be a wall at all.
    You always see your own, even while it is waiting. Otherwise writing one and hearing nothing looks exactly like it having been thrown away.
    "I prayed for this" is a number, not a list of names. The number is an encouragement to whoever asked; the names would turn it into a scoreboard. It needs an account only so that pressing it twice isn't two.
    Answered requests stay up, with a line saying what happened — which is the reason to keep a prayer wall rather than a suggestion box.
    The page is never indexed, and the request text never reaches the audit log.

Small groups

The home groups and studies that meet during the week, at /groups.

    Where it meets is two questions, not one. A district ("North side, near the station") is on the page for anybody. The address is given only to people actually in the group. Most of these meet in somebody's living room, and an address that has been public once stays somewhere for good.
    Asking to join is a request the leader answers, and that is what keeps the address safe rather than being politeness: somebody who has only asked is not given it, or anyone with an account could learn where a leader lives by pressing a button.
    A leader is not staff. Whoever hosts the Tuesday group answers the people who have asked, on the group's own page — no admin access, no capability to be granted, no ticket to whoever runs the website.
    A full group stays on the list and says so. The question somebody has is "can I come"; "not this one, it's full" answers it, and hiding the group doesn't.
    A group with no leader is flagged, because it looks perfectly fine on the list while having nobody to answer a request.
    Only a yes is a notification: a no is a conversation, and a push saying "you were turned down" is the wrong way for anybody to hear it.
    Members see their groups at /profile/groups, and can leave or withdraw a request at any time.

Who came

A leader writes the roll up on the night, at /api/groups/<slug>/meetings.

    The roll goes to the people whose job it is to ring round. A member sees their own evenings; other members see nothing, not even a count. That is enforced by handing a page a list of at most one row rather than a flag, so there is nothing there to total by accident.
    Apologies is a real answer, not a shade of absent. It is the distinction the whole exercise exists for: a group that can't tell "let us know" from "vanished" rings the wrong person.
    A quietly-missing list names people nobody has marked present for three meetings running who didn't send apologies for the last one — the prompt to pick up the phone, which is the only reason to keep a roll at all.
    A cancelled week counts against nobody, at either end. A roll can't be written for an evening that hasn't happened.
    Only people currently in the group can be marked, checked against the database rather than trusted from the form.

Discussion guides

The questions a group works through, at /guides, written once and used by every group.

    Leader notes are absent from what a member is given, not hidden in the markup. The shape handed to a member page has no leaderNotes on it at all, so a page cannot print an answer it was never given.
    Anybody who leads any group sees them — the person hosting Tuesday doesn't need a capability granted to read the notes for Tuesday.

The group's conversation

A thread on the group's own page, for the six days it isn't meeting.

    Only people actually in the group read or write it. Not somebody whose request is unanswered, not somebody on the waiting list — as private as the address, and for the same reason. Standing is re-read on every request rather than carried, so somebody removed on Monday has a tab that stops working on Tuesday.
    A site manager outside the group gets nothing. The one place this departs from the address rule: an address is an operational fact somebody running the site may need; a conversation isn't. Putting them in the group works, and leaves a row saying so.
    Taking a message down hides it rather than deleting it, so the same message can't be reposted past the leader who decided about it. Authors remove their own. Hidden is dropped in the query and in the filter, so a message hidden between two polls can't arrive in the second one.
    Mute keeps somebody in the group and stops the notifications — the thing people actually want when a thread gets busy. The alternative they otherwise reach for is leaving.
    Notifications carry the first line only. A group thread is exactly the place where the whole of a message shouldn't be sitting on a lock screen.
    Their own messages are in their data export, including ones a leader took down, labelled as such.

Member directory

Who else is here, at /directory — behind sign-in and noindex.

    Nobody is in it by default. A member puts themselves in from Profile → Settings, one field at a time: an email address or a phone number appears only because that person ticked that box. A church directory is the document most likely to be forwarded outside the church, so it holds only what people chose to put in it.
    A listing goes when the person does. Leaving or being unauthorized takes them out without anybody remembering to tidy up, because the listing is a view of live rows rather than a copy.

Announcements

One message to everybody, or to one group, from /admin/broadcasts.

    Choose an audience: everyone, a permission group, a small group, a service team, or everyone signed up to an event — most of whom have no account, and are reachable at the address they typed.
    Email, text, push, any combination.
    It tells you who it will reach before you send it, and why the rest it won't: "reaches 312 people — and 41 get nothing · 28 no mobile number, 13 turned off announcement emails". Without that number, "I told everyone" is false and the people who got it assume everybody did.
    Consent is three separate rules. Email is on unless somebody turned announcements off — a different switch from "email me when a sermon publishes", because turning that off is not asking to miss a cancellation. A text needs an explicit yes and a number the member typed in themselves; a number given on a public event form is never treated as consent. Push needs a device already signed up.
    Send yourself a test first. The one thing that makes a typo in a message to four hundred people survivable is having read it on a phone.
    It survives being interrupted. The list is frozen before anything goes out and each person is marked as they are sent, so closing the laptop half-way means the rest go later — not that everybody gets it twice.
    One bad address doesn't stop the rest, and the failures are listed afterwards with the reason the provider gave.
    Texting works through Twilio or your own gateway; with neither set up, the channel says exactly which settings are missing. The cost in message segments is shown as you type — a single curly apostrophe pasted from a word processor halves what fits in one text, which is worth knowing before it is multiplied by three hundred.

Languages

The app's own screens come in English and Spanish, and a church can add a third by adding one file.

    It follows the browser first. Somebody whose phone is in Spanish gets Spanish without finding a setting — Accept-Language is honoured with its quality weights, and es-ES or es-419 both mean Spanish. A language the app doesn't speak falls back to English rather than half-translating.
    A choice sticks, on that device, next to the theme.
    What is translated: the navigation, and the events, forms, prayer and small-group pages — the ones a visitor who doesn't read English most needs. The library, the reader and the admin screens are English only for now. Adding to a language is filling in one file; a key missing from it won't build, and a translation that has quietly dropped a number from a sentence fails a test.
    What language a sermon is in is a different question, and has its own setting on a video and on a series. An episode with no answer takes its series'; a series with no answer is the site's default. Deliberately not "any language": filing every untagged sermon under every language would make the filter useless in the church that most needs it, where most of the archive predates anybody thinking about this.

Importing from YouTube and Vimeo

A church that streams its service to YouTube every Sunday already has the sermon there. /admin/video-feeds points at a channel, playlist, Vimeo account or showcase and brings them in nightly.

    An imported video is an ordinary video. It goes in a series, gets a speaker and scripture references, appears in search, can be favourited and put in a playlist — it simply plays in the source's own frame, with a "Watch on YouTube" link for anyone who would rather.
    Editing an import sticks. Rename "Sunday Service 12/10/25 || FULL SERVICE" to "The Cost of Discipleship" and no later sync will undo it. Each field is compared against what the source said last time, so untouched fields keep updating and edited ones are left alone — one field at a time, so renaming the title doesn't stop the description importing.
    New imports arrive unpublished unless the feed says otherwise, because a church that streams its whole service doesn't want the twenty minutes of an empty stage appearing on its own site.
    What an imported video cannot do, and says so rather than failing: downloads (there is no file of ours), captions (they are the source's), automatic transcription (it needs a file), and thumbnail upload. The download button doesn't appear at all.
    A feed that hasn't changed costs one request, and removing a feed keeps the videos it brought in.
    Needs YOUTUBE_API_KEY or VIMEO_ACCESS_TOKEN; without one the screen says which is missing rather than importing nothing every night in silence.

Live chat

A chat beside a live stream, switched on per stream at /admin/live.

    Off unless somebody turns it on. A members' prayer meeting and a carol service streamed to the wider world are not the same decision.
    Open only while somebody is watching — half an hour before the start until an hour after the end. Early enough for people arriving to say hello, long enough that the conversation a service starts isn't cut off mid-sentence, and then closed. The messages stay readable; the box goes.
    Slow mode, in seconds, raised by a moderator when a stream gets busy. It counts from that person's last message, not the chat's — limiting the whole chat would let one fast typist silence everybody else.
    Anyone can take down their own message; a moderator can take down anybody's, and mute somebody for that stream, which also hides everything they have already written. A muted person is muted for the evening, not for ever — a site-wide ban is a different decision, made calmly on a different screen.
    A removed message never reappears, even in a browser tab that was a few seconds behind.
    The page asks for new messages every few seconds and stops entirely while the tab is hidden — a church leaves this open on a laptop all week.

On the television

Three separate things, and it is worth being plain about which is which.
Sign in with a code on the screen

You cannot type an email address and a password with a remote control. A television shows six characters; somebody opens /link on their phone, types them, and is asked — by name — whether to sign that television in. The television, which has been asking all along, gets a token a few seconds later.

The code on the screen is not the thing that signs anything in. It is visible to everybody in the room, so it only ever names a request; a separate secret that never leaves the television is what redeems it. The characters avoid every pair that looks alike on a screen, and typing the one you thought you saw still works.

Members see their signed-in televisions at /profile/devices and can sign one out at any time — worth doing for a set they no longer have, since the sign-in does not expire on its own.
A catalogue feed

/api/tv/feed.json is the shape Roku's Direct Publisher reads: point a channel at it and Roku builds and ships a real television channel, with no BrightScript and nothing to maintain. /api/tv/feed.xml is the same catalogue as MRSS, which most other platforms and search integrations take.

Only public content goes in a feed. A feed is fetched by somebody else's server with no session, cached by them, and republished to every television that installs the channel. There is no login to put in front of it, so anything members-only is excluded — including a public video inside a members-only series.
A screen for a remote

/tv is the app at arm's length: large type, margins that survive a television's overscan, and focus that moves with the four arrows. Nothing wraps at the end of a row and moving between rows keeps your column, because with no pointer, focus reappearing somewhere unexpected leaves you lost. It works today in the browsers built into Samsung and LG sets, and on anything with an HDMI stick.
What is not here

A native Roku, Apple TV or Android TV app. Each is a separate codebase in a different language, a developer account, a store review and hardware to test on — none of which lives in this repository, and none of which can honestly be built from it. What is here is everything such an app would need from this side: the sign-in, the catalogue, and a screen that already works.
Present mode

A hymn's words on the screen at the front of the room. Present sits on a hymn's page; Present this service sits on a service plan and starts at its first hymn with words, carrying on through the order — whoever is driving never goes back to a list between hymns.

    One verse at a time, as large as it will go, white on black with a light option for a bright room. Verses come from the shape the lyrics were typed in: a blank line separates them, and a block that names itself ("Chorus", "Refrain:") is that rather than a numbered verse, so the numbering skips it the way the printed book does.
    Driven from anywhere. A presenter's clicker is a keyboard, so PageDown/PageUp turn a verse, as do the arrows and the space bar; tapping the right of the screen moves on, the left goes back. The chrome fades after three seconds and returns on the first touch, key or nudge of the mouse.
    It stays awake and stays put — the same screen lock as the reader, and full screen is one button (or F).
    Text size and palette are remembered per device, because the projector in the hall and the phone in your hand want different answers.

Only words can be presented — a scanned page is a photograph, not text. They can be the lyrics on a hymn's own row, or words typed against a number inside a whole-book hymnal (see Words for a hymn inside a book above), so a plan built from book numbers is projectable once somebody has typed those hymns out. A hymn with nothing saved says so rather than showing an empty screen.
The bottom bar

In the installed app the row of icons along the bottom is the only navigation there is, so what belongs in it depends on why someone opened the app. This device → Bottom bar in /profile/settings adds, removes and reorders the destinations it holds: Home, Search, New, any section of the library (a Hymnals category included), your own lists, Profile, and Admin for staff.

    Five across, then it scrolls. Five is what fits on a phone before the labels stop being readable, so up to five share the width the way a tab bar normally does. Add more — up to ten — and the icons keep a thumb-sized width of their own and the row scrolls sideways instead of squeezing, with the section you are in scrolled into view. The picker marks the ones that sit past the fold.

    Stored per device, with the theme and playback preferences — the same member can have the hymnal on their phone and the default set on the church computer — and applied the moment it changes, with no reload.

    A device that has never touched it keeps exactly the bar it had.

    A choice is stored as destinations, not positions, and re-resolved against what that viewer may currently see: a category that gets unpublished, or a page whose plugin is switched off, drops out of the bar rather than sitting there leading nowhere. If nothing survives, the app's own suggestion is drawn, because an installed app with an empty bar has no way to get anywhere.

    The bar is snapshotted for the offline screen each time it renders, which is what lets those icons still be there with no connection.

Limits worth knowing: the Wi-Fi-only preference relies on the Network Information API, which only Chromium implements — where the connection type can't be read, downloads go ahead rather than being blocked everywhere. The storage cap is advisory (the browser's own quota is the real limit) and never interrupts a download in progress. And downloads are MP4 files in a normal browser cache: this is offline convenience, not DRM.
Book reader

PDF and EPUB files open in an in-app reader at /read/[fileId] instead of only being downloadable. A Read button appears next to Download on any file the reader can open, wherever files are listed (a series or a category). Gated by the Book reader plugin, which a category can turn off for its own section like any other.

    Reading — PDFs render page by page with zoom and a page jump box; EPUBs reflow as a scrolling document, which reads better on a phone.
    Swipe to turn the page — in a PDF, a swipe left or right turns the page, and the arrow keys do the same on a desktop. Scrolling still scrolls: the gesture decides which way it is going before it claims the touch, a second finger is a pinch-zoom, and once you have zoomed in past the width of the screen a sideways drag pans the page instead. Turn it off from the reader's toolbar or under Reading in /profile/settings — per device, like the other settings there.
    Contents — the PDF outline or the EPUB navigation document, nested, each entry jumping straight to its place. A contents entry whose destination doesn't resolve is shown greyed rather than dropped, so a half-broken outline doesn't look like an empty one.
    Go to hymn 214 — a Hymn box in the reader's contents bar takes the number on the board and opens that hymn, which in most books is not the page it is printed on. The same box sits on a book's contents page (it opens the reader there) and on the offline screen. The number is read from the front of each contents entry — "214", "1. Holy, Holy, Holy", "Hymn 45", "No. 12", "#7" — never from inside a title, since following a number that happens to end a title would look like it worked and be wrong. Books whose contents aren't numbered don't show the box.
    Back and Next, by hymn rather than by page — a bar along the bottom of the reader names the entry being read and steps to the one either side of it, using the book's own contents. Back goes to the start of the hymn being read before it goes to the hymn before it, which is what you want after paging past the first verse. A book with only one contents entry, or none that resolve, shows no bar.
        In a hymn-per-file book — one whose hymns are separate files rather than one PDF — the same arrows sit at the foot of each hymn's page, stepping in the order that book's list shows and skipping any hymn the viewer can't open.
    Search in the book — matches across every page (PDF) or spine section (EPUB), listed with a snippet of surrounding text. Where an admin has read the book's pages (see Reading a scanned book's text above), the search answers from that instead: one request rather than six hundred pages parsed in the browser, and it finds words on a scan, which searching the open document never could. Results read by OCR say so.
    Page offset — a scanned book whose printed page 1 sits behind a title page and ten pages of contents would otherwise be listed by its PDF page numbers, which match nothing in the paper copy. Setting Page offset on the file (Details, in the admin file list) to the number of front-matter pages makes the contents list, the page box and the search results quote the printed numbers instead. Everything stored — a member's place, a mark, a ?page= link — stays in PDF pages, so an offset can be corrected later without moving anyone's place; front matter itself shows no number, and the reader displays the PDF page alongside while an offset is set.
    When a browser can't draw the pages — pdf.js needs a fairly current browser engine, and an older phone can open a PDF perfectly well without being able to run the library that draws one. The reader says so plainly and offers the book in the browser's own PDF viewer, at the page you were on, rather than showing an error. The offline screen behaves the same way.
    Reading text size — A− and A+ beside a hymn's words and in the EPUB reader's bar, remembered per device. The moment anybody discovers a hymn is too small to read is while they are looking at it, in a pew, which is not when somebody goes hunting through a settings page — though it is under Reading in /profile/settings as well. An EPUB is scaled as a percentage so the book's own headings and verses stay in proportion rather than all collapsing to one size. A scanned PDF is left out: its pages are pictures, and the reader has zoomed them from the start. Present mode keeps its own separate size, because a projector across a hall and a phone in a hand are never the same answer.
        It works offline too. The offline screen reads the same setting and can change it, since a hall with no signal is exactly where the size matters and there is no settings page to reach.
    The screen stays on while a book or a hymn is open, so a phone doesn't dim halfway through the second verse. It's released as soon as you leave the page or switch away from the app — nothing here keeps a screen on in the background — and it can be turned off under Reading in /profile/settings. Some browsers don't offer this at all, and there the screen behaves as it always has.
    Read aloud — speaks the current page or section with a voice and speed picker, advancing through the book on its own. It stops when the app is minimised: browsers suspend speech for a backgrounded page, and no setting here can override that. A scanned PDF with no text layer has nothing to read and will simply do nothing.
    Marks — highlight selected text, bookmark a spot, and attach a note to either, all listed in a sidebar that jumps back to where each was made. Marks are per member and private to them.
    Your place is kept — reopening a book returns to where you stopped, stored per account (not per device), so it follows you between phone and desktop. Signed-out readers can still open a public book; nothing is saved.
    Opening a book a second time is cheap — a hymnal is tens of megabytes that never change, and it used to arrive again in full every time somebody looked up a hymn. Now the browser keeps its copy and only asks whether it is still current, which comes back as a few hundred bytes; the book itself is read off the device. Access is still checked on every open, so this costs nothing in control: a member who loses access is refused on their next open exactly as before. Its contents list — the hymn numbers and titles, which are read out of the PDF's bookmarks and are the slow part of a book's page — is remembered on the device too, for a month, and re-read from scratch whenever the file is replaced.

How file access actually works

Every file the app links — the reader, the Download button, audio players, podcast enclosures — is served by /api/files/[id]/content, which checks access on every request against the live session. Nothing links Bunny's CDN directly any more.

That matters because a CDN URL is permanent and unauthenticated. It can't be revoked, it works for anyone who has ever been sent it, and it can't express a rule this app relies on: whether a file is public depends on its series' memberOnly flag, which an admin can change at any time. Serving through the app means flipping that flag takes effect on the next request rather than never.

Two things still need doing in the Bunny dashboard, because code can't do them:

    Turn on Token Authentication for the storage pull zone (Pull Zone -> Security) and put the key in BUNNY_STORAGE_TOKEN_AUTH_KEY. Until then the pull zone still answers anyone who knows a file's URL — the app has stopped handing those URLs out, but ones already shared keep working.
    Optionally, set up the public podcast zone (all four BUNNY_PUBLIC_STORAGE_* / BUNNY_STORAGE_PUBLIC_PULL_ZONE_HOSTNAME vars). This is a separate storage zone, not an edge rule on the private one: a private file simply isn't in it, so there's no path to guess and no rule that can be quietly deleted later. Never point it at the same storage zone. Left unset — the default — podcast audio streams through the app route instead, which is safer but uses your app's bandwidth rather than Bunny's edge.

Publishing a podcast episode

Publishing to the podcast feed is per file and opt-in: a "Not in podcast" / "In podcast" button on audio files in /admin/files. Ticking it copies that file into the public zone; the feed lists an episode only once that copy has actually landed, so it can never advertise a URL that 404s. The button reads "Podcast pending" when the intent is set but the file isn't mirrored — either the copy hasn't finished, or something currently disqualifies it.

A file leaves the public zone automatically when it stops qualifying: marked members-only, unpublished, hidden, scheduled out, expired, trashed, or moved into a series that is itself members-only, unpublished, hidden or trashed. Flipping the cause back restores it without re-ticking anything, because the admin's intent is stored separately from the mirror's state.

Two things to be clear about:

    Publishing a podcast episode isn't reversible the way the rest of this app's access control is. Un-publishing removes it from the feed and deletes the public copy, which stops new downloads — but listeners' apps have already fetched the file, and nothing can recall that. This is inherent to podcasting, which is exactly why it's opt-in per file.
    Existing audio was not back-filled when this was introduced. Before, every audio file in a public series was implicitly a public episode with nobody opting in; those feeds will be empty until someone ticks the episodes they actually want published.

Limits worth knowing: a highlight of selected text only works in a PDF. An EPUB's pages live in an iframe the reader library owns, and the selection inside it isn't readable from the surrounding page — so marking in an EPUB saves a bookmark at the current position rather than pretending to capture text it can't see.

Reading still needs a connection. The caching above makes a re-open cheap, not free: the browser has to ask whether its copy is current before it may use it, which is what keeps access checks immediate — so with no signal at all a book won't open. That is a different thing from a downloaded video, which plays with the network off. A very large PDF (over 48 MB) also keeps streaming in pieces as it always did, since waiting for the whole file before the first page appears would be the worse trade.

Stepping by contents entry is as good as the book's own contents. A PDF whose bookmarks were never added has nothing to step through, and an EPUB that packs several hymns into one section file steps by section rather than by hymn — there is no ordering for anchors inside a document to do better with.
Auth

Access is decided from two independent checks — by default both must pass, but AUTHORIZATION_MODE can relax that to either one alone:

authenticated with Auth0
  ↓  member of an approved Auth0 organization   (org_id claim, ID token)
  ↓  email ACTIVE in AuthorizedEmail            (PostgreSQL, via Prisma)
  ↓  application access

Org member 	Authorized email 	Result under BOTH 	Result under EITHER
no 	no 	DENY 	DENY
no 	yes 	DENY 	ALLOW
yes 	no 	DENY 	ALLOW
yes 	yes 	ALLOW 	ALLOW

How the two checks combine is set by the AUTHORIZATION_MODE environment variable:
AUTHORIZATION_MODE 	Org member 	Authorized email 	Who gets in
BOTH (default) 	required 	required 	both, as above
ORGANIZATION 	required 	ignored 	any approved organization member
ALLOWLIST 	ignored 	required 	anyone on the list
EITHER 	sufficient alone 	sufficient alone 	either one, as above

Unset or unrecognised resolves to BOTH — a typo must never be the thing that opens a door, and there is no value that switches both checks off. In ALLOWLIST or EITHER mode the app also stops sending organization on the login request: in ALLOWLIST mode Auth0 would otherwise reject non-members before the app's own check ran, making the mode a no-op; in EITHER mode it would be worse, since it would block the personal-account path entirely before that person ever got a chance to be let in on their allowlist entry instead. Both results are recorded on every refusal regardless of mode, so an administrator can see what would happen under a stricter setting. A relaxed or reshaped mode is stated in a banner on /admin/authorized-emails rather than left to whoever remembers the variable.

EITHER is the "personal account or organization account" mode: someone who is a member of an approved organization signs in on that alone, and someone who isn't — a personal Google account, say — still gets in with an ACTIVE allowlist entry, with neither required of the other. It needs one additional Auth0 dashboard setting beyond what the other modes need: this Application's "Type of Users" set to "Both", under Application → Login Experience — without it, Auth0 itself still insists on an organization even when the app stops asking for one, and the personal-account path never becomes reachable.

Inviting a single guest without relaxing the mode for everyone — an AuthorizedEmail row can be individually flagged organizationExempt ("Guest" in /admin/authorized-emails, toggled with the "Make guest" / "Require organization" button). An ACTIVE, exempt row is checked before AUTHORIZATION_MODE's own rule and always lets that one address in, organization or not. This is the narrower fix for "I want BOTH for everyone, but need to let in one guest speaker who isn't in our organization" — EITHER mode answers a different question ("should anyone on the allowlist skip the organization check"); the exempt flag answers "should this specific person." A suspended exempt row is still refused — the flag waives the organization check, not the allowlist's own ACTIVE status.

A guest has to sign in through /auth/guest rather than the normal Log in button, and this is not optional: when an organization is required and configured, /auth/login names it on the authorization request, so Auth0 turns a non-member away at the identity provider — before the callback, and so before the allowlist (and their exempt row) is ever consulted. The guest route starts the identical login with the organization parameter omitted, which is the only way their request survives long enough to be judged on the exempt row. It grants nothing on its own: authorizeIdentity still decides, and an address without an ACTIVE exempt row is refused exactly as before. It 404s when no organization is required, since the normal login already omits the parameter in that case. Like EITHER mode, it needs the Auth0 Application's "Type of Users" set to "Both" (Login Experience tab).

The route also has its own master switch, closed by default: the "Guest sign-in link" toggle at the top of /admin/authorized-emails, backed by a one-row AuthSettings singleton (isGuestLoginEnabled() / setGuestLoginEnabled()) rather than an env var, so opening it for an invited guest and closing it again once they're done needs no redeploy — just a click. Closed, /auth/guest 404s identically to the "no organization required" case, so the response itself never reveals that a guest path exists; /access-denied only offers the link once it's actually open, so a guest who tried the normal button and lands there isn't pointed at a dead link.

    Organization — AUTH0_ORGANIZATION_ID is a comma-separated list of accepted organization ids, so a deployment isn't limited to one. With exactly one configured (and BOTH/ORGANIZATION/EITHER mode), the app sends it as organization on the authorization request, so Auth0 refuses non-members at the identity provider (a personal Google account never reaches the callback with a usable token). With two or more configured, that parameter is left out instead, which is what makes Auth0 show its own organization picker rather than assuming one (needs "Prompt for Organization" turned on for this Application in the Auth0 dashboard). Either way, isOrganizationMember() re-checks the org_id claim of the verified ID token server-side against the same list. The parameter we send — or the choice made at Auth0's prompt — is a request; the claim is the proof. Nothing about membership is ever taken from a query string, header, or anything else the browser controls.
    Allowlist — AuthorizedEmail in PostgreSQL, managed at /admin/authorized-emails. Emails are stored trimmed and lowercased behind a unique index, so casing and whitespace can't produce a second row or slip past a lookup.
    Where it's enforced — getCurrentUser(), which every server-rendered page and API request already funnels through. Both checks run there on every request, so removing an email takes effect on that person's next request; an already-issued session cookie buys nothing. User.authorized is kept in step with the answer so the existing queries that read it stay correct.
    Registration — an Auth0 Pre-User-Registration Action calls POST /api/auth/registration-check (bearer secret, 5s timeout, fails closed) so an unauthorized address can't create an account at all. The Action holds a URL and a secret, never database credentials, and the endpoint answers with nothing but {"allowed": true|false} — it never returns any part of the list. See auth0-actions/README.md.
    Refusals — every rejected login, signup, or request from a revoked session lands on /access-denied, which shows one plain message and never a raw 400, a CallbackHandlerError, an Auth0 stack trace, or a Prisma error. The two callback errors that look alarming — an organization rejection, and Missing state cookie — are expected when someone tries a personal account; they're caught by the SDK's onCallback hook and turned into that page. State, nonce, and CSRF validation are untouched.
    No session survives a refusal — the SDK writes its session cookie after the onCallback hook returns, so src/proxy.ts strips it from any response redirecting to /access-denied. That is what makes "no application session is created after a failed authorization" true rather than merely intended.
    Attempts are recorded — see Access attempts below.
    Emails listed in ADMIN_EMAILS are adopted into AuthorizedEmail on first use (as a visible, suspendable row rather than an invisible exception), so a brand-new deployment has a way in. They still have to be organization members: this is a source for the allowlist, not a bypass of the model.

Admin CMS (/admin)

    Content management — categories (with drag/position/type-to-reorder), series, videos (direct upload via TUS + import from an existing Bunny Stream library), and files (small uploads or link-by-URL for larger files hosted directly in Bunny).
        Files can be picked in bulk. Each one gets its own title box, pre-filled with the filename minus its extension and editable before uploading — only the extension is stripped, since reformatting underscores or capitalisation is guessing at what someone meant to call it. Rows can be removed before starting, and more files added to a queue already listed.
        They upload one at a time, not as one batch: the server's size cap is per request, so batching would make the limit worse, not better. A file the server rejects reports against its own row and the rest carry on; successful rows clear and failures stay put with the reason, so a partly-failed batch is retried without re-picking everything.

    Bulk actions & filtering — multi-select Publish/Unpublish/Delete and a title filter box on the series/video/file lists; series can be recategorized individually or in bulk. "Schedule publish…" sets a future publishAt across the whole selection in one prompt.

    Audit log (/admin/audit) — an append-only record of admin/editor actions, exportable as CSV or JSON.

    Plugins (/admin/plugins) — a WordPress-style list of the optional member features above, each with a site-wide Active/Inactive toggle plus per-category overrides (nearest-ancestor override wins, falls back to the site-wide default) — e.g. disable Comments just under "Kids".

    Permissions (/admin/permissions) — a phpBB/WordPress-style builder: define named groups as a custom bundle of capabilities (manage categories/series/videos/files, publish content, moderate comments, manage users/permissions/plugins, view audit log), then assign a group to a user site-wide or scoped to one category (and everything under it) or one series. This sits alongside a simpler built-in per-category/series "content-editor" grant in /admin/users. The real ADMIN role always has every capability and can only be granted by another ADMIN — a custom "manage_users" group can't be used to self-promote.

    Granular viewing permissions: a series or video's edit page can restrict viewing to specific permission groups ("roles") and/or specific people by email, layered on top of the plain "Members only" checkbox. As soon as any such grant exists for an item, "Members only" no longer gates it — only the granted roles/people (and admins) can view it. Files aren't covered — they stay governed by their own "Members only" flag.

    Draft mode for series edits: a series' edit page has a "Save as draft" action alongside "Publish now" — it stages the form's field values in a single pending DraftRevision row (upserted, not versioned) without touching the live series. A banner shows the pending draft with "Load into form" and "Discard" actions; publishing clears any staged draft. Scoped to series only — videos are edited inline in the video list rather than through a comparable multi-field form.

    Hide content: a series, video, or file can be marked hidden from its admin edit page, independent of published/memberOnly. Hidden content is excluded from every guest- and member-facing listing, search, RSS/podcast feed, and direct URL — it behaves like it doesn't exist for anyone except admins/editors managing it in /admin.

    Member content hidden from guests by default: memberOnly series and videos are excluded outright from all public listings (homepage, category pages, search, trending, related/up-next, RSS) for anyone not logged in — a guest browsing the site never sees that the content exists. Logged-in members see it normally. Visiting a member-only item's URL directly still shows a "log in to view" gate rather than a 404, so a shared link still invites sign-up.

    Closed captions — a "Captions" button on each row of the video list opens a panel for uploading a .vtt/.srt track (1MB cap), labelled by language code, and removing tracks later. Not a plugin and not stored locally: tracks live in Bunny Stream, keyed by srclang, and its embed player shows a CC toggle automatically once one exists. Distinct from the Transcripts plugin, which is a searchable text panel beside the video rather than subtitles on it.

    Automatic transcription — Transcribe it for me, beside the transcript box on a video. Transcripts have been hand-typed since they shipped, which in practice means most videos have none and the search that reads them finds nothing.
        It queues rather than transcribes: an hour of audio takes minutes, which is longer than a request may live. A scheduled job takes one video per run, so a backlog drains over successive runs instead of one request being killed halfway. An attempt that dies leaves the video in RUNNING; anything stuck there for half an hour is queued again by the next run.
        It needs a speech-to-text service, named by TRANSCRIBE_API_URL. Any service taking a multipart POST with a file field and answering { "text": ... } works — hosted, or a Whisper server on a machine in the office, in which case no audio leaves the building. Unset, the button is refused and says so.
        The audio sent is the video's MP4 rendition, passed through this server rather than the service being pointed at a media URL: those URLs are signed and short-lived, and handing a third party a key to the library is a different thing from handing it one file. A video with no MP4 rendition says so rather than failing obscurely, and a file over the service's size limit is refused here — with the size, in a sentence — rather than by somebody else's server.

    Comment moderation (/admin/comments, needs moderate_comments) — a queue of every reported and/or hidden comment, scoped to a moderator's own categories/series unless they hold a site-wide moderate_comments grant (or are ADMIN). "Hide" removes a comment from public view without deleting it; "Delete" is permanent, same as the existing per-comment delete action.

    Downloads (/admin/downloads, needs manage_plugins) — who may download (any member, or named groups/people), where the button appears (web, installed app, or both), and the suggested per-device storage cap. Which videos may be downloaded is set per category/series/video on their own edit pages. See Downloads above.

    Who can sign in (/admin/authorized-emails, needs manage_users) — the email allowlist: add, search, suspend/reinstate, and remove, with who added each address and when. Paginated. Refuses to remove the last active address, which would otherwise lock everyone out of the admin area. Named for the question it answers, to keep it distinct from Members & roles (/admin/users) beside it — that page is accounts, roles, editor grants, and pending login attempts, and its Grant/Revoke buttons write to this list, which is the one the app actually checks. Also shows the active AUTHORIZATION_MODE and its banner when relaxed or reshaped from the default BOTH.
        Any address can be flagged Guest (organizationExempt), letting that one person in on an ACTIVE entry alone, without organization membership — a "Make guest" / "Require organization" button per row. See Auth above.
        A "Guest sign-in link" card at the top toggles /auth/guest open or closed, off by default. It has to be open before a guest link is any use to anyone: the normal Log in button still names the organization and turns a non-member away before this list is ever checked.

    Access attempts (/admin/access-attempts, needs view_audit_log) — refused logins, signups, and requests from revoked sessions: when, email, provider, attempt type, which of the two checks failed, and the reason. Paginated server-side with search by email and filters by reason and date, a mark-reviewed action, and a prune button. No credential material of any kind is stored — no tokens, codes, or passwords — and records are pruned after 90 days by the daily cron.

    Share links (/admin/share-links, needs share_content) — every share link on the site, whoever created it: target, owner, public or private, recipients, whether it grants access, open count, and a Revoke button (audited). Filterable by active vs revoked/expired, and can create a link for any series or video from a picker. See Share links above.

    Homepage rows (/admin/home-rows, needs manage_plugins) — turn any of the homepage's built-in rows (Continue watching, Because you watched, Trending, Recently added) on/off and rename them, plus add curated rows pointing at a specific category or tag. Continue watching (when shown) always renders directly above the category/series browse list, which itself isn't reorderable; every other row reorders and appears below it, in the order configured here.

    Webhooks (/admin/webhooks) — admin-configured outgoing URLs that get a JSON POST whenever a series or video is published; optionally signed with a secret as an X-Webhook-Signature header (hex HMAC-SHA256). Needs the Webhooks plugin enabled in Plugins.

    Trash (/admin/trash) — deleting a category, series, video, or file moves it to trash instead of removing it, so a mistake is recoverable. Restore brings it back exactly as it was; permanent delete is irreversible and, for a video/file, is also the point its underlying Bunny Stream/Storage asset actually gets removed — trashing alone leaves it in place. Requires holding at least one of the four content-management capabilities (manage_categories/series/videos/files) site-wide, or being ADMIN; see the technical note below on what trashing a category or series does (and doesn't do) to what's inside it.

    Slug aliases — renaming a series or video's slug from its edit page records the old slug, so a link shared before the rename 301s to the current one instead of 404ing, automatically.

Admin analytics (/admin/analytics)

    Needs the view_analytics capability. Shows total views over a selectable window (7/30/90 days, ?days=) plus the top 10 series and top 10 videos by view count in that window, built from the same timestamped view log that powers the homepage Trending row.
    Each top video also shows a watch-through rate: the share of that window's watch-progress rows for the video that are marked completed. It reuses the existing heartbeat data rather than adding tracking, and is omitted entirely (not shown as 0%) for a video with no progress recorded in the window, so a stale view count can't be paired with a misleadingly precise 0%.
    Most looked-up hymns answers the question a hymn list can't: what does this congregation actually sing? Counted when a hymn is really opened — its own page, a book opened at its number, or put on the projector — and named by the book's indexed contents, so a whole-book hymn reads as itself rather than as its book.
        Counted in the browser, not when a page renders: hovering a link prefetches it, so a server-side count would largely be a count of mice. The honest cost is the other direction — a blocked request means an opening goes uncounted — which is the right way round for a number nothing depends on.
    Export CSV downloads the same top series, videos and hymns for the selected window as a CSV (or JSON) file, for pulling into a spreadsheet or a board report.

Scheduled jobs

    /api/cron/notification-digest (daily): batches queued daily-digest push notifications — see Notifications above.
    /api/cron/sync-video-status (daily): polls Bunny for every video still stuck in PROCESSING and applies the same status/duration/thumbnail update the admin's manual "Sync from Bunny" button does, so a video that finished encoding doesn't sit unprocessed until someone happens to click refresh. Never touches published — an admin still decides when to publish. Both crons share the same CRON_SECRET bearer-token guard.

Query Monitor (QUERY_MONITOR_ENABLED env var)

    A WordPress-Query-Monitor-style debug bar, fixed to the bottom of every page: request elapsed time, the number of Prisma queries run and their total time, a per-query breakdown (model.operation, a truncated args preview, duration), and process memory (heap/RSS).
    Two switches gate it, both required:
        The QUERY_MONITOR_ENABLED environment variable (must be "true", case-insensitive — TRUE/True work too; anything else, including unset, is off) — the deploy-level kill switch, matching WordPress's WP_DEBUG rather than a database-toggled Plugin. Flipping it requires a redeploy; /admin/query-monitor can only report its current value.
        A DB-backed admin switch, toggleable right on /admin/query-monitor (manage_plugins capability) with no redeploy needed — e.g. to hide the bar during a live demo and bring it back a minute later. Stored as a Plugin row with slug "query-monitor" (QUERY_MONITOR_ADMIN_SLUG in src/lib/query-monitor.ts) reusing the existing table/shape, but deliberately left out of PLUGIN_META — it's an ops tool with no per-category meaning, so it doesn't appear on /admin/plugins or get a "Category overrides" control (/api/admin/plugins explicitly filters to PLUGIN_META's own slugs to keep it out). Defaults to on (fails open) the first time, so setting the env var alone is enough to see the bar without a trip to this page first.
    Even when both switches are on, the bar only renders for logged-in ADMIN users — query text/args and timings can hint at internal schema and data shape, so (unlike WordPress's Query Monitor, which is itself also capability-gated) it's never shown to members or guests regardless of either switch.
    Query capture is a Prisma Client Extension (src/lib/db.ts) wrapping every model operation; it's a no-op passthrough unless the env flag is on — checking the admin switch too would mean a DB read on every single query, so recording is gated on the env flag alone and the admin switch only affects whether the bar renders. The per-request tally (src/lib/query-monitor.ts) uses React's cache() — the same request-scoping primitive getCurrentUser() already relies on — so concurrent requests never mix each other's counts. Raw $queryRaw/ $executeRaw calls (e.g. categoryChainIds) aren't model operations, so they aren't captured by this instrumentation.
    Next.js's App Router reuses the root layout's previous render across client-side (<Link>) navigations rather than re-executing it — "partial rendering" — so without help the bar would keep showing whichever page triggered the last full/hard load. QueryMonitorRefresher (src/components/query-monitor-refresher.tsx), rendered alongside the bar, forces a router.refresh() on every path change so the layout (and the bar with it) recomputes against each page's own request. Only mounted when the bar itself is — i.e. never for anyone but an enabled-and-ADMIN viewer — so it costs nothing for ordinary visitors.

Security

The decisions that hold, and where each one lives. An audit in September 2026 found no authorisation bypass, injection or leaked secret; what it found was the hardening below, all of which is now in place.

    Every response carries the headers a site should: X-Frame-Options: SAMEORIGIN and frame-ancestors 'self' (the reader and players frame this origin's own pages; nobody else may), X-Content-Type-Options: nosniff, Referrer-Policy: strict-origin-when-cross-origin, a Permissions-Policy. Set in next.config.ts; HSTS comes from the platform. There is no script-source CSP yet — the inline scripts in the layout need nonces first.
    The image optimizer is off (images.unoptimized: true). Every image already rendered unoptimized; the config flag is what removes the /_next/image route, which would otherwise fetch and decode any same-origin path — uploaded files included — through sharp.
    An upload is what its extension says, from a short list (lib/upload-types.ts). The browser's file.type is never stored or served. Reader formats and plain media are shown inline; documents are downloads; anything not on the list is an opaque download with nosniff and sandbox, whatever was recorded about it. SVG is not an image here. Objects are named files/<id>.<ext> and nothing of the client's name survives.
    Scheduled jobs fail closed (lib/cron-guard.ts): a production deployment without CRON_SECRET answers 503 to every /api/cron/* call rather than running the job for whoever asks.
    Push subscriptions go only to push services (lib/push-endpoint.ts): https: to a known browser push host, at most eight per member. The server POSTs to every stored endpoint on every notification, so a URL a member chose would have made it POST wherever they liked.
    Writes labelled cross-site by the browser are refused in the proxy (lib/cross-site.ts), as a second layer behind the session cookie's SameSite=Lax. Callers with no Sec-Fetch-Site header — Auth0's registration check, a television, an API key — are unaffected.
    View counts throttle on the server (lib/view-key.ts): an HMAC of the caller's address, never the address, kept for a day. A cookie alone was the throttle before, and a script doesn't send one.
    Webhook URLs must be public (lib/public-url.ts): loopback, private, link-local and bare names are refused when saved.
    Rota names are for members (lib/schedules/visibility.ts): the schedules and their dates are public, the people on them need a sign-in, and a signed-out reader is handed events with nobody on them rather than names to hide.
    Every member route answers through errorResponse, which maps validation and database errors to 400/404 and never echoes an upstream message.

Technical notes

    Bunny's Stream iframe embed does support postMessage control, via Player.js (play()/pause()/seek(), and play/pause/timeupdate/ ended events). Nothing in the app uses it yet — the items below still work the way they did before it was found.
    Android pausing playback on minimize can't be fixed from outside the iframe. The attempt: listen via Player.js for a pause while document.hidden, then call play() again. Tested on a real device — playback still stops. The likeliest cause is the browser refusing a play() that originates from a hidden document with no user activation, which is squarely what its autoplay policy blocks. It also fails silently, since Player.js's play() is a fire-and-forget postMessage and any rejection inside the iframe never reaches the parent page. Anything that could plausibly work has to own the media element rather than talk to someone else's iframe — i.e. play Bunny's MP4 (the signed URL the Downloads plugin already builds) through this app's own <audio>, which is how web audio players get background playback. That's a real architectural change and is still not guaranteed: the MP4 carries a video track, so the browser may treat it as video and suspend it anyway.
    Watch progress is a heartbeat-based approximation, not frame-accurate: progress is inferred from elapsed time while the page is open rather than a precise scrub position.
    Up next autoplay has the same shape: autoplay fires a timer based on the video's known duration rather than hooking a real "ended" event.
    Chapters too: clicking one reloads the iframe with a new t= start-time query param instead of calling Player.js's seek.
    Sermon notes' timestamp field is manually entered for the same reason (no live playback position read) — it's prefilled once from the heartbeat's elapsed-time approximation as a starting point to adjust from, not kept in sync afterward.
    The heartbeat never un-marks a video as watched: /api/watch-progress only ever sets completed to true, never back to false — a stray heartbeat reporting false (e.g. re-opening a finished video partway through) must not silently clear a completion that "Mark as watched" or an earlier heartbeat already recorded. Un-marking is only ever a deliberate action, via the mark-as-watched toggle or /api/watch-progress/mark-watched.
    Trashing a category or series doesn't cascade to what's inside it: its own row gets deletedAt set, but a child series/video/file keeps its existing categoryId/seriesId untouched — it just stops appearing anywhere the trashed parent would have listed it (the category/series browse tree), while still being directly reachable by its own URL. This is a deliberate scope trim for the first version of trash rather than full recursive soft-delete/restore.
    View counts are a simple per-page-load counter, not deduplicated or spam-resistant — a basic "how many hits" number, not analytics. Trending and the admin analytics dashboard use a separate timestamped view log for the same reason (recency-windowed counts need timestamps, the simple counter doesn't have any).
    Playback speed is handled by Bunny Stream's own player UI (the ⚙️ settings icon) — there's nothing to build server-side since the iframe embed already exposes it.
    Fuzzy search is a fallback, not the default path: the exact/substring query runs first and, when it matches anything, nothing else happens — so the common case pays no extra query. Only when a pass comes back empty does the fuzzy path run, ranking candidates by Postgres trigram similarity (similarity(), via the pg_trgm extension and GIN indexes added in the search_trigram_indexes migration) rather than pulling rows into memory — this scales with the database, not with an in-memory row cap.
    Closed captions live in Bunny, not here: there's no local copy and no new Video column — the admin route reads and writes Bunny's captions API directly, so every render path picks up a new track for free, the same pattern the custom-thumbnail work uses. Bunny is the source of truth, which also means the captions panel reflects whatever is set there even if it was uploaded from the Bunny dashboard.
    Daily digests need a scheduled job: a member set to "Daily digest" never gets an inline push — each notification is queued as a PendingNotification row and only leaves the system when /api/cron/notification-digest runs (scheduled in vercel.json, daily). Without that cron running, digest users' notifications accumulate and are never delivered. The route is guarded by CRON_SECRET when that env var is set, so it can't be hit externally to mass-send pushes.
    Rate limiting: comments (5/minute), ratings, and likes/dislikes (20/minute each) are capped per logged-in user via a DB-backed count over a rolling window (src/lib/rate-limit.ts), returning 429 once exceeded. /api/view-events isn't covered by this — see its own cookie-based throttle below, kept separate since it's unauthenticated and specifically designed to avoid a DB read/write per view.
    ViewEvent writes are throttled per browser per item (/api/view-events, fired client-side by ViewEventBeacon) using a 30-minute cookie rather than a DB check: a cookie read is free, so a throttled repeat view costs zero database operations, instead of trading a write for a read (which wouldn't actually save anything, since reads are billed too on a Postgres free tier). The plain viewCount counter is unaffected and still increments on every view like before.
    The PWA service worker deliberately does not cache pages or API responses. This site's content is dynamic and often member-gated, so an aggressive offline cache would risk showing stale or wrong-audience content; it only caches its own static shell (manifest + icons) and handles push notifications.
    Email notifications are a fetch to the Resend API (src/lib/email.ts), the same pattern as bunny.ts/webhooks.ts talking to their own REST APIs — no SDK dependency. It's a no-op if RESEND_API_KEY/EMAIL_FROM aren't set, same as push's VAPID-keys-optional behavior. Unlike push, email always sends immediately: it isn't queued into PendingNotification for DAILY users, since that preference only governs push's timing.
    The trigram GIN indexes have no schema.prisma representation (raw SQL migration, not the Prisma DSL) — the next prisma migrate dev will read them as drift and propose dropping them. Strip any such DROP INDEX ..._trgm_idx statements from a freshly generated migration before applying it (see the home_rows_comment_moderation_email migration for an example of this already happening once).

Tests & CI

npm test runs a vitest suite (src/lib/*.test.ts) over the logic that would fail quietly rather than loudly — access and capability checks, plugin override precedence, sequential unlock, list reordering, fuzzy matching, share-link sharing rules and link validity, download inheritance and audience/platform rules, and device-settings parsing. Prisma is mocked, so the suite needs no database.

GitHub Actions runs the type check, lint, that suite, and prisma validate / prisma format --check on every pull request and every push to main (.github/workflows/ci.yml). The schema check is why an unformatted schema.prisma fails CI — run npx prisma format before committing schema edits.
Appendix B — Data model (prisma/schema.prisma, verbatim)

The datasource block at the top is Postgres-specific and is superseded by Database above; everything from the first enum on is the model.

// Prisma schema for the media platform (series, videos, files, admin CMS)

generator client {
  provider = "prisma-client-js"
}

// Two connection strings, deliberately not interchangeable.
//
// `url` is what the generated PrismaClient uses for every runtime query, and
// must be POOLED: a serverless deployment spins up many concurrent function
// instances, each opening its own connection, and only a pooler survives
// that. On Prisma Postgres that's the `prisma+postgres://accelerate…` string
// Console labels "Prisma ORM" (Accelerate pools by default, and the protocol
// works natively with @prisma/client — no extension to install).
//
// `directUrl` is what `prisma migrate deploy`/`db push` use instead, and must
// be a DIRECT TCP connection, since poolers don't support the DDL statements
// migrations issue. On Prisma Postgres that's the `postgres://…@db.prisma.io`
// string Console labels "Any Client".
//
// Why the names are asymmetric: Vercel's Prisma Postgres marketplace
// integration injects `DATABASE_URL` itself and marks it integration-managed,
// which makes it read-only in the dashboard — "Rotate Integration Secrets" is
// the only control offered, and that just reissues the same kind of
// connection. Since what it injects is the direct connection, it's read here
// as `directUrl`, where a direct connection is exactly what's wanted, and the
// pooled string is supplied separately as `POOLED_DATABASE_URL` — a variable
// added by hand, which Vercel does allow. Pointing runtime queries at the
// direct connection is what produced "too many connections for role
// prisma_migration" (P2037) in production: that role's cap is sized for a
// migration's brief burst, not sustained concurrent app traffic.
//
// On a host with no such constraint, set both to whatever that provider calls
// its pooled and direct endpoints; `POOLED_DATABASE_URL` and `DATABASE_URL`
// may even be the same value where no separate pooled endpoint exists.
datasource db {
  provider  = "postgresql"
  url       = env("POOLED_DATABASE_URL")
  directUrl = env("DATABASE_URL")
}

enum Role {
  MEMBER
  ADMIN
}

enum NotificationFrequency {
  INSTANT
  DAILY
}

enum VideoStatus {
  PROCESSING
  READY
  FAILED
}

enum ReactionType {
  LIKE
  DISLIKE
}

enum AuthorizedEmailStatus {
  ACTIVE
  // Kept in the list but not honoured — a way to suspend someone without
  // losing the record of who added them and when.
  SUSPENDED
}

// Why an access attempt was refused. Both halves of the security model are
// recorded separately, because "not in the org" and "not on the allowlist"
// need different fixes from an administrator.
enum AccessDenialReason {
  NOT_ORG_MEMBER
  EMAIL_NOT_AUTHORIZED
  NOT_ORG_MEMBER_AND_EMAIL_NOT_AUTHORIZED
  // Auth0 itself refused before we ever saw an identity (organization
  // rejection at the callback, a failed state check, and so on).
  AUTH0_CALLBACK_ERROR
}

enum AccessAttemptType {
  LOGIN
  SIGNUP
  SESSION
}

// Where the download button is offered. Downloading is a very different
// proposition on an installed app (where the file lives in the app's cache
// and plays offline) than in a browser tab, so a church can enable one
// without the other.
enum DownloadPlatform {
  WEB
  PWA
  BOTH
}

// Who may download, on top of already being able to *view* the content.
enum DownloadAudience {
  // Any authorized member.
  ALL_MEMBERS
  // Only the permission groups and specific people listed on the policy.
  SPECIFIC
}

enum ShareVisibility {
  // Anyone holding the link can open it, no login needed.
  PUBLIC
  // Only the listed recipient emails can open it, and only after logging in
  // as that email — the link alone isn't enough.
  EMAIL
}

// Auth0 login only proves identity, not that we let someone in: anyone
// who attempts to log in gets a row here (so admins can see the attempt
// at /admin/users), but `authorized` starts false and getCurrentUser()
// only grants access once it's true. Admins can pre-authorize an email
// via /admin/users before the person ever logs in, or grant access to a
// pending row after seeing the attempt. ADMIN_EMAILS self-authorizes as
// ADMIN on first login so there's always a way in.
model User {
  id                    String                @id @default(cuid())
  auth0Id               String?               @unique
  email                 String                @unique
  name                  String?
  // Member-chosen display name, shown instead of the Auth0 `name` wherever
  // the Profiles plugin is on (comments, the navbar). Independent of `name`,
  // which getCurrentUser() keeps synced from the Auth0 session on every login.
  displayName           String?
  picture               String?
  role                  Role                  @default(MEMBER)
  authorized            Boolean               @default(false)
  // Instant (default) sends a push the moment content publishes, matching
  // every plugin's existing behavior. Daily batches the same events into
  // one push, sent by a scheduled job — see PendingNotification. Governs
  // push only; emailNotifications below is a separate, always-instant channel.
  notificationFrequency NotificationFrequency @default(INSTANT)
  // Opt-in email channel, independent of notificationFrequency (which only
  // governs push timing) — always sent immediately when a notification fires.
  emailNotifications    Boolean               @default(false)
  /// A mobile number, given by the member themselves. Never imported.
  phone                 String?
  /// Whether they agreed to be texted. Off unless somebody says yes: a text
  /// costs the church money and the recipient their attention, and in most
  /// places sending one without consent is also illegal.
  smsOptIn              Boolean               @default(false)
  /// Whether church-wide announcements reach them by email. On by default,
  /// and deliberately separate from `emailNotifications` above — somebody who
  /// turned off "a new sermon is up" has not asked to miss "no service
  /// tomorrow, the road is closed".
  broadcastEmails       Boolean               @default(true)
  /// Whether this member appears in the members' directory.
  ///
  /// Off, and off by default, and that default is the whole feature: a
  /// directory somebody is in because they never found the setting is a
  /// directory built without consent. Being listed publishes a name; each
  /// contact detail below is its own separate yes.
  directoryListed       Boolean               @default(false)
  directoryShowEmail    Boolean               @default(false)
  directoryShowPhone    Boolean               @default(false)
  /// A line they write themselves — "ask me about the youth group".
  directoryNote         String?

  /// The secret in this member's personal calendar-feed URL, or null until
  /// they ask for one. A calendar app cannot log in, so the URL is the
  /// credential — which is why it is generated on request, shown once in the
  /// place that generates it, and replaced rather than repaired if it leaks.
  calendarToken         String?               @unique
  createdAt             DateTime              @default(now())
  updatedAt             DateTime              @updatedAt
  pendingNotifications  PendingNotification[]
  categoryEditors       CategoryEditor[]
  seriesEditors         SeriesEditor[]
  watchProgress         WatchProgress[]
  seriesFavorites       SeriesFavorite[]
  videoFavorites        VideoFavorite[]
  fileFavorites         FileFavorite[]
  comments              Comment[]
  commentReports        CommentReport[]
  sermonNotes           SermonNote[]
  outlineAnswers        SermonOutlineAnswer[]
  serviceTeams          ServiceTeamMember[]
  serviceAssignments    ServiceAssignment[]
  coveredForAssignments ServiceAssignment[]   @relation("AssignmentCoveredFor")
  serviceBlockouts      ServiceBlockout[]
  /// The name this account appears under on a rota, when they are the same
  /// person — see Person.
  person                Person?
  groupAssignments      GroupAssignment[]
  ratings               Rating[]
  seriesWatchLater      SeriesWatchLater[]
  videoWatchLater       VideoWatchLater[]
  categoryWatchLater    CategoryWatchLater[]
  pushSubscriptions     PushSubscription[]
  subscriptions         Subscription[]
  playlists             Playlist[]
  reactions             Reaction[]
  seriesViewGrants      SeriesViewer[]
  videoViewGrants       VideoViewer[]
  shareLinks            ShareLink[]
  notifications         Notification[]
  downloadGrants        DownloadPolicyUser[]
  readingProgress       ReadingProgress[]
  readingMarks          ReadingMark[]
  identities            UserIdentity[]
  authorizedEmailsAdded AuthorizedEmail[]     @relation("AuthorizedEmailAddedBy")
  eventRegistrations    EventRegistration[]
  formSubmissions       FormSubmission[]
  prayerRequests        PrayerRequest[]
  prayers               PrayerIntercession[]
  smallGroups           SmallGroupMember[]
  groupAttendance       GroupAttendance[]
  groupMessages         GroupMessage[]
  broadcastsReceived    BroadcastRecipient[]
  liveChatMessages      LiveChatMessage[]
  liveChatMutes         LiveChatMute[]
  tvDevices             TvDevice[]

  @@index([directoryListed, authorized])
}

/**
 * Every Auth0 identity that has signed in as this member — Google, Microsoft,
 * a database password, and so on.
 * `User.auth0Id` records only whichever was used most recently, which makes
 * it useless for answering "which sign-in methods does this person have?"
 * and unsafe to key anything on. These rows are the actual record; auth0Id
 * is kept in step for the existing queries that read it.
 * `sub` is the strong identifier: Auth0 issues it and it never changes, even
 * when the person changes their email at the provider. Resolving a login by
 * sub first (before falling back to email) is what keeps an email change from
 * stranding someone's history on an orphaned row.
 */
model UserIdentity {
  id            String   @id @default(cuid())
  user          User     @relation(fields: [userId], references: [id], onDelete: Cascade)
  userId        String
  /**
   * The Auth0 `sub`, e.g. "google-oauth2|1234567890". Globally unique by construction.
   */
  sub           String   @unique
  /**
   * Connection/strategy parsed from the sub, e.g. "google-oauth2", for display.
   */
  provider      String
  /**
   * The email this identity presented, which can differ per provider and can change.
   */
  email         String
  /**
   * Whether the provider asserted the email was verified. An unverified
   * identity is never allowed to attach itself to an existing member — that
   * is the account-takeover path email-based linking is known for.
   */
  emailVerified Boolean  @default(false)
  lastLoginAt   DateTime @default(now())
  createdAt     DateTime @default(now())

  @@index([userId])
  @@index([email])
}

// Grants a non-admin user editor access to one category (and everything
// under it) without making them a site-wide admin.
model CategoryEditor {
  id         String   @id @default(cuid())
  user       User     @relation(fields: [userId], references: [id], onDelete: Cascade)
  userId     String
  category   Category @relation(fields: [categoryId], references: [id], onDelete: Cascade)
  categoryId String
  createdAt  DateTime @default(now())

  @@unique([userId, categoryId])
}

// Grants a non-admin user editor access to one specific series only.
model SeriesEditor {
  id        String   @id @default(cuid())
  user      User     @relation(fields: [userId], references: [id], onDelete: Cascade)
  userId    String
  series    Series   @relation(fields: [seriesId], references: [id], onDelete: Cascade)
  seriesId  String
  createdAt DateTime @default(now())

  @@unique([userId, seriesId])
}

// Categories can nest arbitrarily deep (a category's children can themselves
// have children), matching sites like Subsplash where browsing goes
// Home -> category -> sub-category -> ... -> series -> videos/files.
model Category {
  id                String                   @id @default(cuid())
  name              String
  slug              String                   @unique
  description       String?
  coverImageUrl     String?
  tags              String[]                 @default([])
  memberOnly        Boolean                  @default(false)
  // Tri-state download override: null inherits (from the nearest ancestor
  // category, then the site default), true allows, false blocks. See
  // resolveDownloadEnabled in src/lib/downloads.ts.
  downloadEnabled   Boolean?
  hidden            Boolean                  @default(false)
  published         Boolean                  @default(true)
  publishAt         DateTime?
  unpublishAt       DateTime?
  // Soft delete: set from "Delete" in the admin UI instead of removing the
  // row, so it can be restored from /admin/trash. Excluded from every
  // public query (via publishedNow()) and from the normal admin list;
  // permanently removed only when purged from the trash.
  deletedAt         DateTime?
  featured          Boolean                  @default(false)
  // Applies only to this category's own direct videos (see Video.categoryId), not to child
  // categories/series — mirrors Series.requireSequential one level down.
  requireSequential Boolean                  @default(false)
  // Renders every PDF in this category (its own, and its series') as a grid
  // of book covers drawn from each PDF's first page, each opening a contents
  // list built from that PDF's embedded bookmarks — see hymnal-book-grid.tsx
  // and book-contents.tsx. Meant for categories holding hymnal-style book
  // collections rather than video series.
  hymnalStyle       Boolean                  @default(false)
  position          Int                      @default(0)
  pinned            Boolean                  @default(false)
  parentId          String?
  parent            Category?                @relation("CategoryChildren", fields: [parentId], references: [id], onDelete: SetNull)
  children          Category[]               @relation("CategoryChildren")
  series            Series[]
  videos            Video[]
  files             FileAsset[]
  editors           CategoryEditor[]
  pluginOverrides   PluginCategoryOverride[]
  groupAssignments  GroupAssignment[]
  subscriptions     Subscription[]
  watchLaterBy      CategoryWatchLater[]
  homeRows          HomeRow[]
  createdAt         DateTime                 @default(now())
  updatedAt         DateTime                 @default(now()) @updatedAt

  @@index([parentId])
}

model Series {
  id                String              @id @default(cuid())
  title             String
  slug              String              @unique
  description       String?
  /// The language this series is in, as a BCP-47 tag. See Video.language —
  /// a video without its own inherits the series' answer rather than the
  /// site's, because a Spanish series' episodes are Spanish.
  language          String?
  coverImageUrl     String?
  // Short badge shown on this book's cover in a hymnalStyle category's grid
  // (e.g. "GSFH1"). Unused outside that grid.
  abbreviation      String?
  // This series is one book whose files are its individual hymns (one file
  // per hymn, each with its own pageNumber), rather than one or more
  // complete book PDFs. Can't be inferred: "several files in a series" looks
  // identical either way, so the admin says which. See lib/hymnal.ts.
  hymnPerFile       Boolean             @default(false)
  memberOnly        Boolean             @default(false)
  // Tri-state download override — see Category.downloadEnabled. Null inherits
  // from this series' category chain.
  downloadEnabled   Boolean?
  hidden            Boolean             @default(false)
  published         Boolean             @default(true)
  publishAt         DateTime?
  unpublishAt       DateTime?
  // Soft delete — see Category.deletedAt.
  deletedAt         DateTime?
  featured          Boolean             @default(false)
  pinned            Boolean             @default(false)
  tags              String[]            @default([])
  position          Int                 @default(0)
  category          Category?           @relation(fields: [categoryId], references: [id])
  categoryId        String?
  videos            Video[]
  files             FileAsset[]
  editors           SeriesEditor[]
  favoritedBy       SeriesFavorite[]
  comments          Comment[]
  groupAssignments  GroupAssignment[]
  ratings           Rating[]
  watchLaterBy      SeriesWatchLater[]
  viewCount         Int                 @default(0)
  requireSequential Boolean             @default(false)
  subscriptions     Subscription[]
  reactions         Reaction[]
  viewEvents        ViewEvent[]
  viewerGroups      SeriesViewerGroup[]
  viewers           SeriesViewer[]
  shareLinks        ShareLink[]
  createdAt         DateTime            @default(now())
  updatedAt         DateTime            @updatedAt
  discussionGuides  DiscussionGuide[]

  @@index([language])
}

model Video {
  id                   String                @id @default(cuid())
  title                String
  slug                 String                @unique
  description          String?
  /// Where the video actually lives. BUNNY is the library this app uploads
  /// to; the others are somebody else's player in an iframe.
  source               VideoSource           @default(BUNNY)
  /// Null for a video that isn't in Bunny at all — see `source`. Everything
  /// that reads this (downloads, captions, MP4 renditions, the encode-status
  /// sync) is a Bunny-only capability, so a null here is the honest way to
  /// say "that doesn't apply", rather than an empty string every caller has
  /// to remember to check.
  bunnyVideoId         String?
  bunnyLibraryId       String?
  /// The id at YouTube or Vimeo. Unique per source, which is what stops a
  /// re-sync importing the same video twice.
  externalId           String?
  /// The page a viewer would land on at the source, for a "watch on YouTube"
  /// link and for an admin checking what was imported.
  externalUrl          String?
  /// The thumbnail the source gave us. Bunny computes its own from the video.
  externalThumbnailUrl String?
  /// The title and description as the source had them at the last sync.
  ///
  /// Kept so a re-sync can tell "nobody has touched this, take the new
  /// wording" from "somebody rewrote it here, leave it alone" — comparing the
  /// live field against this rather than overwriting blind. Without it, every
  /// sync undoes the edits an admin made after the last one.
  importedTitle        String?
  importedDescription  String?
  /// Which feed imported it, so removing a feed can say what it brought in.
  feed                 VideoFeed?            @relation(fields: [feedId], references: [id], onDelete: SetNull)
  feedId               String?
  // Bunny stores the thumbnail under a per-video file name, which is only
  // "thumbnail.jpg" for auto-generated ones — setting a custom thumbnail
  // (via our admin UI or Bunny's dashboard) changes it. Synced from the
  // Bunny API; null falls back to the "thumbnail.jpg" default.
  thumbnailFileName    String?
  // Bunny's MP4-fallback state for this video, cached from the Stream API by
  // the sync routes so the download endpoint reads Postgres instead of
  // calling Bunny on every tap. Null means "never synced" — the download
  // endpoint then fetches once and writes the answer back.
  //
  // Per-video, not per-library: enabling MP4 Fallback in Bunny's encoding
  // settings only affects later uploads, so an older video sits at false
  // until it's re-uploaded or repackaged.
  hasMp4Fallback       Boolean?
  // Bunny's `availableResolutions`, verbatim (e.g. "240p,360p,480p"). Kept as
  // the raw string so it survives Bunny adding renditions we don't know yet;
  // parseBunnyResolutions is what interprets it.
  mp4Resolutions       String?
  // Full text transcript, admin-entered. Shown in a collapsible panel by
  // the Transcripts plugin and matched by search when that plugin is on.
  /// The language this was recorded in, as a BCP-47 tag ("en", "es").
  ///
  /// Content, not chrome: a member's language setting changes the app's own
  /// screens, and this says what a sermon is actually in. Null means nobody
  /// has said, which is treated as the site's default rather than as "any" —
  /// guessing would file every unlabelled sermon under every language.
  language             String?
  transcript           String?
  /// Where an automatic transcription of this video has got to: null for a
  /// video nobody has asked to transcribe, then QUEUED → RUNNING → DONE, or
  /// FAILED with the reason in `transcriptError`.
  ///
  /// A column rather than a job table because there is at most one of these
  /// per video and the video is what anybody looks at to find out. See
  /// /api/cron/transcribe for why a queue exists at all: transcribing an hour
  /// of audio takes minutes, which is longer than a request may live.
  transcriptStatus     String?
  transcriptError      String?
  /// When the current attempt started, so one that died mid-run — a function
  /// killed at its timeout writes nothing — can be noticed and retried rather
  /// than sitting in RUNNING for ever.
  transcriptStartedAt  DateTime?
  // A fill-in-the-blank note outline for this talk, written by an admin as
  // plain text with three-or-more underscores marking each gap. Held here
  // rather than in a table of its own for the same reason as the transcript:
  // it is one piece of text belonging to one video. See lib/outline.ts.
  noteOutline          String?
  // Free-form Bible references entered by the admin (e.g. "John 3:16-18"),
  // shown as chips on the video page and matched by search; also powers
  // /scripture/[book] listings, matching Series.tags' book-of-a-verse-first
  // token as the "book" for that route.
  scriptureRefs        String[]              @default([])
  durationSeconds      Int?
  status               VideoStatus           @default(PROCESSING)
  memberOnly           Boolean               @default(false)
  // Tri-state download override — see Category.downloadEnabled. The most
  // specific level, so this wins over its series and category.
  downloadEnabled      Boolean?
  hidden               Boolean               @default(false)
  published            Boolean               @default(false)
  publishAt            DateTime?
  unpublishAt          DateTime?
  // Soft delete — see Category.deletedAt. The Bunny Stream asset itself
  // isn't removed until the trash entry is permanently purged.
  deletedAt            DateTime?
  isPremiere           Boolean               @default(false)
  position             Int                   @default(0)
  series               Series?               @relation(fields: [seriesId], references: [id], onDelete: SetNull)
  seriesId             String?
  // Set only when seriesId is null: a video attached straight to a category,
  // skipping the series layer for categories that don't need it (see FileAsset.categoryId too).
  category             Category?             @relation(fields: [categoryId], references: [id], onDelete: SetNull)
  categoryId           String?
  // Who preached/presented this video; optional since not every video is a sermon.
  speaker              Speaker?              @relation(fields: [speakerId], references: [id], onDelete: SetNull)
  speakerId            String?
  watchProgress        WatchProgress[]
  favoritedBy          VideoFavorite[]
  comments             Comment[]
  ratings              Rating[]
  watchLaterBy         VideoWatchLater[]
  viewCount            Int                   @default(0)
  reactions            Reaction[]
  viewEvents           ViewEvent[]
  playlistItems        PlaylistItem[]
  viewerGroups         VideoViewerGroup[]
  viewers              VideoViewer[]
  chapters             Chapter[]
  sermonNotes          SermonNote[]
  outlineAnswers       SermonOutlineAnswer[]
  shareLinks           ShareLink[]
  createdAt            DateTime              @default(now())
  updatedAt            DateTime              @updatedAt
  discussionGuides     DiscussionGuide[]

  /// One row per video per source: a re-sync updates rather than duplicates.
  @@unique([source, externalId])
  @@index([seriesId])
  @@index([categoryId])
  @@index([speakerId])
  @@index([language])
  @@index([feedId])
}

// A named timestamp within a video (e.g. "Intro", "Q&A"), for the Chapters
// plugin's jump-to-section list on the video page.
model Chapter {
  id               String   @id @default(cuid())
  video            Video    @relation(fields: [videoId], references: [id], onDelete: Cascade)
  videoId          String
  title            String
  timestampSeconds Int
  position         Int      @default(0)
  createdAt        DateTime @default(now())

  @@index([videoId])
}

// A preacher/presenter, browsable at /speakers/[slug] alongside their videos.
// Not a plugin: like Chapters/Transcripts, it's admin-managed content rather
// than a toggleable site feature.
model Speaker {
  id        String   @id @default(cuid())
  name      String
  slug      String   @unique
  bio       String?
  photoUrl  String?
  position  Int      @default(0)
  videos    Video[]
  createdAt DateTime @default(now())
  updatedAt DateTime @updatedAt
}

// A member's bookmark of a series, for a "My Favorites" page.
model SeriesFavorite {
  id        String   @id @default(cuid())
  user      User     @relation(fields: [userId], references: [id], onDelete: Cascade)
  userId    String
  series    Series   @relation(fields: [seriesId], references: [id], onDelete: Cascade)
  seriesId  String
  createdAt DateTime @default(now())

  @@unique([userId, seriesId])
  @@index([seriesId])
}

// A member's bookmark of a video, for a "My Favorites" page.
model VideoFavorite {
  id        String   @id @default(cuid())
  user      User     @relation(fields: [userId], references: [id], onDelete: Cascade)
  userId    String
  video     Video    @relation(fields: [videoId], references: [id], onDelete: Cascade)
  videoId   String
  createdAt DateTime @default(now())

  @@unique([userId, videoId])
  @@index([videoId])
}

/// One line of a book's own contents — a hymn in a scanned hymnal — resolved
/// once and stored, so it can be searched.
///
/// Every browser could read these itself (and `/books/[id]` still does, from
/// the PDF's embedded bookmarks) but only for a book it has already opened,
/// after seconds of resolving destinations. A hymnal category holding six
/// books can't be searched that way at all: nobody waits for six PDFs to be
/// parsed to find out which one has "It Is Well". So an admin resolves them
/// once — in a browser, since that is where pdf.js runs — and the result
/// lives here, where a query can reach every book at once.
///
/// `page` is a PDF page, like everything else stored about a book's position
/// (see lib/page-offset.ts): the printed number is worked out for display, so
/// correcting a book's offset relabels these without reindexing.
model BookHymn {
  id       String    @id @default(cuid())
  file     FileAsset @relation(fields: [fileId], references: [id], onDelete: Cascade)
  fileId   String
  /// The label exactly as the book's contents print it.
  title    String
  /// The number in front of that label, when it has one — what goes up on the
  /// board, and what somebody types. See hymnNumberOf in lib/toc-nav.ts.
  number   Int?
  page     Int
  /// Nesting in the outline, so a section heading can be told from a hymn.
  depth    Int       @default(0)
  position Int

  @@index([fileId])
  @@index([number])
  @@index([title])
}

/// The words on one page of a book, so a scan can be searched.
///
/// A PDF made by a typesetter carries its text; a PDF made by a scanner is
/// photographs, and everything the reader offers over text — search in the
/// book, read aloud — silently does nothing on one. Which is most hymnals.
///
/// So the text is read once, by an admin's browser, and kept: from the file's
/// own text layer where there is one, and off the image with OCR where there
/// isn't. Per page rather than per book because a page is what a result has
/// to send somebody to, and because reading six hundred pages is a job that
/// gets interrupted — stored a page at a time, it resumes where it stopped.
model BookPage {
  id     String    @id @default(cuid())
  file   FileAsset @relation(fields: [fileId], references: [id], onDelete: Cascade)
  fileId String
  /// A PDF page, like everything else stored about a position in a book.
  page   Int
  text   String
  /// How it was read: "text" from the file's own layer, "ocr" off the image.
  /// Kept because they are not equally trustworthy — OCR of a tight scan
  /// misreads letters, and a search that finds nothing on such a book should
  /// be explicable rather than mysterious.
  source String

  @@unique([fileId, page])
  @@index([fileId])
}

/// What a person has typed about a hymn that lives inside a whole-book PDF:
/// its words, and the credits a licence return and a projector both need.
///
/// Kept apart from BookHymn on purpose. Those rows are the book's contents,
/// replaced whole every time the book is indexed — deleted and written again
/// from the PDF's bookmarks. This is typed by hand, an evening at a time, and
/// cannot live somewhere a reindex sweeps away.
///
/// So it is keyed by the number on the board rather than by a row id: hymn
/// 214 of this book is still hymn 214 after the book is re-scanned,
/// re-indexed, or has its contents retyped. The trade is that an unnumbered
/// entry — a section heading, a hymn a book prints without a number — has
/// nothing to key on and can't be described here.
///
/// The same credits sit on FileAsset for a hymn that *is* a file, which is
/// the split lyrics already make. Two homes rather than one table with a
/// nullable number, because a unique index over a nullable column constrains
/// nothing in Postgres — two "the file itself" rows would both be allowed.
model BookHymnDetail {
  id         String    @id @default(cuid())
  file       FileAsset @relation(fields: [fileId], references: [id], onDelete: Cascade)
  fileId     String
  /// The hymn number as printed in this book. See hymnNumberOf in lib/toc-nav.ts.
  number     Int
  /// Null for a hymn whose credits are known but whose words nobody has typed.
  lyricsText String?
  /// The CCLI song number, for the licence return. Free text: it is an
  /// identifier printed on a page, not a number to do arithmetic with.
  ccliNumber String?
  /// "Words: John Newton. Music: Traditional." — as the book prints it.
  author     String?
  /// The copyright line. A licence requires this *on the screen* while the
  /// words are projected, which is why it travels with them.
  copyright  String?
  /// The key it is sung in here ("G", "Eb"), for whoever is playing.
  musicalKey String?
  tempoBpm   Int?
  updatedAt  DateTime  @updatedAt

  @@unique([fileId, number])
  @@index([fileId])
}

// A member's bookmark of a file — a hymn, or a whole book. Series and videos
// have had this from the start; a hymnal's hymns are the thing this app gets
// looked things up in most, and had no way to be kept in a list.
model FileFavorite {
  id        String    @id @default(cuid())
  user      User      @relation(fields: [userId], references: [id], onDelete: Cascade)
  userId    String
  file      FileAsset @relation(fields: [fileId], references: [id], onDelete: Cascade)
  fileId    String
  createdAt DateTime  @default(now())

  @@unique([userId, fileId])
  @@index([fileId])
}

/// The hymns for one service, in the order they will be sung.
///
/// Deliberately not a Playlist: those are a member's own, hold videos, and
/// have no date. This is a staff-published running order that everyone in the
/// building opens the same copy of — closer to the board at the front of the
/// room than to anybody's library.
model ServicePlan {
  id          String              @id @default(cuid())
  title       String
  /// The day it is for. Nullable because a plan is often drafted before the
  /// date is settled, and the list sorts undated plans by when they were made.
  serviceDate DateTime?
  notes       String?
  /// Drafts stay off the members' list until someone is happy with the order.
  published   Boolean             @default(false)
  createdAt   DateTime            @default(now())
  updatedAt   DateTime            @updatedAt
  items       ServicePlanItem[]
  assignments ServiceAssignment[]

  @@index([serviceDate])
}

/// A team that serves at a service: musicians, welcome, sound, readers.
///
/// Teams are the unit somebody is scheduled *on*; the job they do that week
/// is free text on the assignment ("Piano", "Second reading"), because every
/// church names those differently and a table of positions would be a second
/// thing to maintain for no extra answer.
model ServiceTeam {
  id        String              @id @default(cuid())
  name      String
  /// Ordering on the admin page and on a plan, so the list reads the way the
  /// church thinks of it rather than alphabetically.
  position  Int                 @default(0)
  createdAt DateTime            @default(now())
  members   ServiceTeamMember[]
  roles     ServiceAssignment[]
}

/// Somebody who can be scheduled on a team.
///
/// Membership is separate from the assignment so the scheduler has a list to
/// pick from — the point of a rota is not typing names — and so that leaving
/// a team doesn't erase the record of having served.
model ServiceTeamMember {
  id       String      @id @default(cuid())
  team     ServiceTeam @relation(fields: [teamId], references: [id], onDelete: Cascade)
  teamId   String
  user     User        @relation(fields: [userId], references: [id], onDelete: Cascade)
  userId   String
  /// What they usually do, offered as the default when scheduling them.
  position String?
  joinedAt DateTime    @default(now())

  @@unique([teamId, userId])
  @@index([userId])
}

/// One person, on for one job, at one service.
///
/// The status is the whole point of a rota over a list: somebody is asked,
/// and they say yes or no, and the person building it can see which. A
/// decline carries its reason, because "no" without one just means the same
/// conversation happens by text message instead.
model ServiceAssignment {
  id          String           @id @default(cuid())
  plan        ServicePlan      @relation(fields: [planId], references: [id], onDelete: Cascade)
  planId      String
  team        ServiceTeam      @relation(fields: [teamId], references: [id], onDelete: Cascade)
  teamId      String
  user        User             @relation(fields: [userId], references: [id], onDelete: Cascade)
  userId      String
  /// Empty rather than null when unspecified: a unique index over a nullable
  /// column constrains nothing in Postgres, and this one is what stops the
  /// same person being asked twice for the same job.
  position    String           @default("")
  status      AssignmentStatus @default(INVITED)
  /// Why they said no, in their words.
  note        String?
  respondedAt DateTime?

  /// Set when whoever is on has asked somebody else to take it.
  ///
  /// A flag on the assignment rather than a swap record of its own: what is
  /// being asked for is *this slot*, and covering it means the slot changes
  /// hands. A separate table would have to be kept in step with the thing it
  /// describes, and the state it would hold is one boolean.
  coverWanted  Boolean   @default(false)
  /// What they said when they asked — "away that weekend", "will know Friday".
  coverNote    String?
  coverAskedAt DateTime?
  /// Who had it before somebody covered, kept so the rota still shows what
  /// happened rather than quietly reading as though they were never on.
  coveredForId String?
  coveredFor   User?     @relation("AssignmentCoveredFor", fields: [coveredForId], references: [id], onDelete: SetNull)
  coveredAt    DateTime?

  createdAt DateTime @default(now())

  @@unique([planId, userId, position])
  @@index([teamId, coverWanted])
  @@index([planId])
  @@index([userId, status])
}

enum AssignmentStatus {
  INVITED
  ACCEPTED
  DECLINED
}

/// Days somebody has said they can't serve.
///
/// Stored as a range because that is how people say it — "we're away the
/// first two weeks of August" — and checked when scheduling so the person
/// building a rota is told before they ask rather than after. It is a warning
/// and not a bar: a rota is a conversation, and sometimes you ask anyway.
model ServiceBlockout {
  id        String   @id @default(cuid())
  user      User     @relation(fields: [userId], references: [id], onDelete: Cascade)
  userId    String
  /// Inclusive, both ends: somebody away "the 3rd to the 5th" means all three.
  startDate DateTime
  endDate   DateTime
  reason    String?
  createdAt DateTime @default(now())

  @@index([userId, startDate])
}

/// One hymn in a service, which is one of the two shapes a hymn takes here
/// (see lib/hymnal.ts): a file of its own, or a number inside a whole-book
/// PDF. `hymnNumber` is set for the second — the number that goes up on the
/// board — and resolved to a page against the book's own contents when
/// someone opens it, so an admin never has to know which PDF page that is.
model ServicePlanItem {
  id         String      @id @default(cuid())
  plan       ServicePlan @relation(fields: [planId], references: [id], onDelete: Cascade)
  planId     String
  file       FileAsset   @relation(fields: [fileId], references: [id], onDelete: Cascade)
  fileId     String
  hymnNumber Int?
  /// A line for the congregation: "after the reading", "verses 1 and 4 only".
  note       String?
  position   Int         @default(0)
  createdAt  DateTime    @default(now())

  @@index([planId])
  @@index([fileId])
}

// A member's comment on a series or a video (exactly one of seriesId/videoId is set).
// Threading is one level deep: a reply's parentId always points at a
// top-level comment (see the flattening logic in the comments API), so
// `replies` below never itself has replies.
model Comment {
  id        String          @id @default(cuid())
  user      User            @relation(fields: [userId], references: [id], onDelete: Cascade)
  userId    String
  series    Series?         @relation(fields: [seriesId], references: [id], onDelete: Cascade)
  seriesId  String?
  video     Video?          @relation(fields: [videoId], references: [id], onDelete: Cascade)
  videoId   String?
  body      String
  parent    Comment?        @relation("CommentReplies", fields: [parentId], references: [id], onDelete: Cascade)
  parentId  String?
  replies   Comment[]       @relation("CommentReplies")
  // Set by a moderator from the /admin/comments queue; hidden comments are
  // excluded from getComments() (public reads) but still visible there for review.
  hidden    Boolean         @default(false)
  reports   CommentReport[]
  createdAt DateTime        @default(now())

  @@index([seriesId])
  @@index([videoId])
  @@index([parentId])
}

// A member flagging a comment for moderator attention. One report per
// member per comment (repeat clicks don't inflate the count); the count
// itself just prioritizes the /admin/comments queue — reporting doesn't
// hide anything by itself.
model CommentReport {
  id        String   @id @default(cuid())
  comment   Comment  @relation(fields: [commentId], references: [id], onDelete: Cascade)
  commentId String
  user      User     @relation(fields: [userId], references: [id], onDelete: Cascade)
  userId    String
  createdAt DateTime @default(now())

  @@unique([commentId, userId])
  @@index([commentId])
}

// Per-user resume position for a video, approximated from periodic
// heartbeats sent while the video page is open (the Bunny Stream iframe
// embed doesn't expose a documented postMessage API for exact play/pause/seek
// events, so this tracks elapsed watch time rather than a precise scrub position).
model WatchProgress {
  id              String   @id @default(cuid())
  user            User     @relation(fields: [userId], references: [id], onDelete: Cascade)
  userId          String
  video           Video    @relation(fields: [videoId], references: [id], onDelete: Cascade)
  videoId         String
  positionSeconds Int      @default(0)
  completed       Boolean  @default(false)
  updatedAt       DateTime @updatedAt

  @@unique([userId, videoId])
  @@index([userId])
}

model FileAsset {
  id                String    @id @default(cuid())
  title             String
  bunnyPath         String
  url               String
  sizeBytes         Int?
  mimeType          String?
  memberOnly        Boolean   @default(false)
  hidden            Boolean   @default(false)
  published         Boolean   @default(true)
  publishAt         DateTime?
  unpublishAt       DateTime?
  // Soft delete — see Category.deletedAt. The Bunny Storage object itself
  // isn't removed until the trash entry is permanently purged.
  deletedAt         DateTime?
  position          Int       @default(0)
  // The three below apply only inside a hymnPerFile series, where this file
  // is one hymn rather than a whole book.
  //
  // The printed page number in the book, shown as the leading number in
  // hymn-list.tsx and driving its "Page" sort — distinct from `position`
  // above, which drives drag-reorder and isn't necessarily the same
  // sequence (printed page numbers can skip).
  pageNumber        Int?
  // Free-text topical grouping (e.g. "Praise", "Prayer") for hymn-list's
  // "Category" sort. Unrelated to the Category model.
  groupLabel        String?
  // Formatted lyrics shown on this hymn's page; when null that page falls
  // back to the PDF reader/download as its primary view.
  lyricsText        String?
  // The credits for a hymn that *is* a file — the same ones BookHymnDetail
  // holds for a hymn inside a book. A licence return needs the CCLI number
  // and the copyright line; a projector is required to show the copyright
  // line while the words are up; the key and tempo are for whoever plays.
  ccliNumber        String?
  songAuthor        String?
  songCopyright     String?
  musicalKey        String?
  tempoBpm          Int?
  // How many PDF pages sit in front of this book's printed page 1 — its
  // front matter. Applies to a whole-book PDF, unlike the three above.
  //
  // Everything stored stays in PDF pages (ReadingProgress.location, a
  // ?page= link, a bookmark's resolved destination); this converts only
  // where a number is shown to or typed by a person, so a hymn on the
  // book's page 45 is listed and reached as 45 rather than as 55. See
  // lib/page-offset.ts.
  pageOffset        Int       @default(0)
  // A small JPEG data URL of this book's first page, and how many hymns its
  // bookmarks list — both derived from the PDF once by an admin (see
  // "Generate covers" in the file manager) rather than by every visitor's
  // browser opening the PDF to work them out again.
  //
  // Held inline rather than as another storage object so they can't outlive
  // the row: a deleted file takes its thumbnail with it, with no orphan
  // left in Bunny for a later purge to miss. Kept small for the same reason
  // — these travel with every listing that renders a book grid.
  coverDataUrl      String?
  hymnCount         Int?
  /// When this book's own contents were last read into BookHymn rows, or
  /// null for a book nobody has indexed yet. Shown in the admin so it is
  /// obvious which books are searchable.
  contentsIndexedAt DateTime?
  /// When this book's pages were last read for their text, or null for a book
  /// nobody has read. Set when a pass reaches the last page, so a run stopped
  /// halfway leaves it null and the button still offers to finish.
  textIndexedAt     DateTime?
  // The admin's *intent* to publish this as a podcast episode, set from the
  // admin file list. Deliberately separate from publicPath below, which is
  // the *state*: a file can be intended for the podcast while temporarily
  // not mirrored (its series was flipped members-only, it was unpublished),
  // and flipping that back restores it without the admin re-ticking anything.
  podcastPublished  Boolean   @default(false)
  // Where this file's public copy lives in the separate public storage zone,
  // or null when it isn't mirrored. Never derived from bunnyPath — a
  // podcast enclosure URL is only ever built from a value actually written
  // here after a successful copy, so a file that failed to mirror is absent
  // from the feed rather than advertised at a URL that 404s.
  publicPath        String?
  series            Series?   @relation(fields: [seriesId], references: [id], onDelete: SetNull)
  seriesId          String?
  // Set only when seriesId is null: a file attached straight to a category (see Video.categoryId).
  category          Category? @relation(fields: [categoryId], references: [id], onDelete: SetNull)
  categoryId        String?
  createdAt         DateTime  @default(now())

  readingProgress ReadingProgress[]
  readingMarks    ReadingMark[]
  favoritedBy     FileFavorite[]
  servicePlanned  ServicePlanItem[]
  hymns           BookHymn[]
  hymnDetails     BookHymnDetail[]
  pages           BookPage[]
  lookups         HymnLookup[]

  @@index([seriesId])
  @@index([categoryId])
}

// What a ReadingMark is. Kept as one table rather than three because all
// three anchor to a location in the same way and are listed together in the
// reader's sidebar; only the UI treatment differs.
enum ReadingMarkKind {
  HIGHLIGHT
  BOOKMARK
  NOTE
}

/**
 * Where a member last was in a book, so the reader can reopen there.
 * `location` is deliberately an opaque string rather than a page number:
 * a PDF's position is a page index ("12"), an EPUB's is a CFI
 * ("epubcfi(/6/14[chap]!/4/2/1:0)"), and only the reader that wrote it
 * needs to understand it. `percent` is the format-independent copy, for
 * showing progress without parsing either.
 */
model ReadingProgress {
  id        String    @id @default(cuid())
  user      User      @relation(fields: [userId], references: [id], onDelete: Cascade)
  userId    String
  file      FileAsset @relation(fields: [fileId], references: [id], onDelete: Cascade)
  fileId    String
  location  String
  percent   Int       @default(0)
  updatedAt DateTime  @updatedAt

  @@unique([userId, fileId])
  @@index([userId])
}

/**
 * A highlight, bookmark, or note a member left in a book. `location` follows
 * the same opaque-string rule as ReadingProgress; `endLocation` is set only
 * for a range (a highlight), null for a point (a bookmark).
 * `excerpt` stores the selected text as it read at the time. That's
 * deliberate duplication: it keeps the marks sidebar readable without
 * re-parsing the book, and keeps a highlight legible even if the underlying
 * file is later replaced with a re-paginated edition whose locations no
 * longer resolve.
 */
model ReadingMark {
  id          String          @id @default(cuid())
  user        User            @relation(fields: [userId], references: [id], onDelete: Cascade)
  userId      String
  file        FileAsset       @relation(fields: [fileId], references: [id], onDelete: Cascade)
  fileId      String
  kind        ReadingMarkKind @default(HIGHLIGHT)
  location    String
  endLocation String?
  excerpt     String?
  note        String?
  /**
   * Tailwind-ish colour key resolved in the UI, not a raw CSS colour.
   */
  color       String          @default("yellow")
  createdAt   DateTime        @default(now())
  updatedAt   DateTime        @updatedAt

  @@index([userId, fileId])
  @@index([fileId])
}

/// A machine's way in: a long-lived key an admin creates so another system can
/// read this one.
///
/// The key itself is never stored. Only its SHA-256 is, the same way a password
/// would be, because a database that leaks must not hand somebody a working key
/// — and because "show it to me again" is a request that should be impossible
/// to satisfy rather than merely refused. What is stored beside the hash is the
/// first few characters, so a list of keys is legible to the person who made
/// them without any of them being usable.
model ApiKey {
  id String @id @default(cuid())

  /// What it is for, in the admin's words: "the noticeboard in the foyer".
  name      String
  /// SHA-256 of the whole key, hex. The key is shown once, at creation.
  hashedKey String @unique
  /// The first characters of the key, for telling one row from another.
  prefix    String

  /// What this key may read. Never write — see lib/api-keys.ts.
  scopes String[] @default([])

  /// The admin who made it, so a key has somebody's name against it.
  createdByEmail String

  /// Optional expiry. A key with a date on it is one somebody has thought
  /// about; a key without one lives until it is revoked.
  expiresAt DateTime?
  revokedAt DateTime?

  lastUsedAt DateTime?

  /// A fixed-window rate limit, counted on the row itself. In-memory counters
  /// are useless here for the same reason as everywhere else in this app: this
  /// runs on serverless functions with no shared process state.
  windowStartedAt DateTime @default(now())
  windowCount     Int      @default(0)

  createdAt DateTime @default(now())

  @@index([revokedAt])
}

// Append-only record of admin/editor actions, kept even if the acting user
// or the entity they touched is later deleted.
model AuditLog {
  id         String   @id @default(cuid())
  actorEmail String
  action     String
  entityType String
  entityId   String?
  detail     String?
  createdAt  DateTime @default(now())

  @@index([createdAt])
}

// A WordPress-style toggleable feature. Site-wide `enabled` is the default;
// PluginCategoryOverride lets a category (or one of its ancestors) flip that
// default for everything under it. Known slugs live in src/lib/plugins.ts.
model Plugin {
  id          String                   @id @default(cuid())
  slug        String                   @unique
  name        String
  description String?
  enabled     Boolean                  @default(true)
  overrides   PluginCategoryOverride[]
  createdAt   DateTime                 @default(now())
}

model PluginCategoryOverride {
  id         String   @id @default(cuid())
  plugin     Plugin   @relation(fields: [pluginId], references: [id], onDelete: Cascade)
  pluginId   String
  category   Category @relation(fields: [categoryId], references: [id], onDelete: Cascade)
  categoryId String
  enabled    Boolean

  @@unique([pluginId, categoryId])
}

// A phpBB/WordPress-style named permission group: an admin-defined bundle of
// capabilities (see src/lib/capabilities.ts for the fixed capability list).
// Assigning a group to a user grants those capabilities, either site-wide or
// scoped to one category (and everything under it) or one series.
model PermissionGroup {
  id               String                @id @default(cuid())
  name             String                @unique
  description      String?
  capabilities     String[]              @default([])
  assignments      GroupAssignment[]
  seriesViewGrants SeriesViewerGroup[]
  videoViewGrants  VideoViewerGroup[]
  downloadGrants   DownloadPolicyGroup[]
  createdAt        DateTime              @default(now())
}

model GroupAssignment {
  id         String          @id @default(cuid())
  user       User            @relation(fields: [userId], references: [id], onDelete: Cascade)
  userId     String
  group      PermissionGroup @relation(fields: [groupId], references: [id], onDelete: Cascade)
  groupId    String
  category   Category?       @relation(fields: [categoryId], references: [id], onDelete: Cascade)
  categoryId String?
  series     Series?         @relation(fields: [seriesId], references: [id], onDelete: Cascade)
  seriesId   String?
  createdAt  DateTime        @default(now())

  @@index([userId])
}

// A 1-5 star rating on a series or video (exactly one of seriesId/videoId is set).
model Rating {
  id        String   @id @default(cuid())
  user      User     @relation(fields: [userId], references: [id], onDelete: Cascade)
  userId    String
  series    Series?  @relation(fields: [seriesId], references: [id], onDelete: Cascade)
  seriesId  String?
  video     Video?   @relation(fields: [videoId], references: [id], onDelete: Cascade)
  videoId   String?
  value     Int
  createdAt DateTime @default(now())
  updatedAt DateTime @updatedAt

  @@unique([userId, seriesId])
  @@unique([userId, videoId])
  @@index([seriesId])
  @@index([videoId])
}

// A member's "watch later" queue entry for a series, distinct from Favorites.
model SeriesWatchLater {
  id        String   @id @default(cuid())
  user      User     @relation(fields: [userId], references: [id], onDelete: Cascade)
  userId    String
  series    Series   @relation(fields: [seriesId], references: [id], onDelete: Cascade)
  seriesId  String
  createdAt DateTime @default(now())

  @@unique([userId, seriesId])
  @@index([seriesId])
}

// A member's "watch later" queue entry for a whole category, for categories
// that hold content directly (see Video.categoryId) rather than via a series.
model CategoryWatchLater {
  id         String   @id @default(cuid())
  user       User     @relation(fields: [userId], references: [id], onDelete: Cascade)
  userId     String
  category   Category @relation(fields: [categoryId], references: [id], onDelete: Cascade)
  categoryId String
  createdAt  DateTime @default(now())

  @@unique([userId, categoryId])
  @@index([categoryId])
}

// A member's "watch later" queue entry for a video, distinct from Favorites.
model VideoWatchLater {
  id        String   @id @default(cuid())
  user      User     @relation(fields: [userId], references: [id], onDelete: Cascade)
  userId    String
  video     Video    @relation(fields: [videoId], references: [id], onDelete: Cascade)
  videoId   String
  createdAt DateTime @default(now())

  @@unique([userId, videoId])
  @@index([videoId])
}

// A browser's Web Push subscription for a logged-in user, used to notify
// them when new content is published (see src/lib/push.ts).
model PushSubscription {
  id        String   @id @default(cuid())
  user      User     @relation(fields: [userId], references: [id], onDelete: Cascade)
  userId    String
  endpoint  String   @unique
  p256dh    String
  auth      String
  createdAt DateTime @default(now())

  @@index([userId])
}

// A single pending set of field edits for a series, staged separately from
// the live row so they can be reviewed/loaded or discarded before going
// live, without keeping full revision history. One row per series
// (upserted), not a version log.
model DraftRevision {
  id         String   @id @default(cuid())
  entityType String
  entityId   String
  data       Json
  createdAt  DateTime @default(now())
  updatedAt  DateTime @updatedAt

  @@unique([entityType, entityId])
}

// An outgoing webhook URL notified when the Webhooks plugin is on and a
// series/video is published (see src/lib/webhooks.ts). `secret`, if set,
// signs the payload as an X-Webhook-Signature header (hex HMAC-SHA256).
model Webhook {
  id        String   @id @default(cuid())
  url       String
  secret    String?
  active    Boolean  @default(true)
  createdAt DateTime @default(now())
}

// A dismissible site-wide banner message; only the most recent `active` one shows.
enum AnnouncementAudience {
  ALL
  GUESTS
  MEMBERS
}

model Announcement {
  id        String               @id @default(cuid())
  message   String
  active    Boolean              @default(true)
  // Optional scheduling window, on top of `active`: still requires active =
  // true, but also hides the banner outside [publishAt, expiresAt) when set.
  publishAt DateTime?
  expiresAt DateTime?
  audience  AnnouncementAudience @default(ALL)
  createdAt DateTime             @default(now())
}

// A live event: an embedded third-party player (YouTube/Boxcast/Resi/etc.),
// not video hosted by us — Bunny Stream has no live ingest, so this just
// points at wherever the stream already is. `published` gates visibility the
// same way Series/Video use it; `startAt`/`endAt` drive the "Live now" badge
// and the pre-stream countdown (endAt is a soft cutoff, not enforced against
// the embed itself, since we can't detect when the third party actually stops).
model LiveStream {
  id            String    @id @default(cuid())
  title         String
  description   String?
  embedUrl      String
  coverImageUrl String?
  published     Boolean   @default(false)
  startAt       DateTime
  endAt         DateTime?

  /// Whether the chat beside this stream is open at all. Off by default: a
  /// carol service streamed to the wider world is not automatically a place
  /// a church wants an unattended comment box.
  chatEnabled  Boolean @default(false)
  /// Seconds a member must wait between messages, on top of the flood limit.
  /// Zero is off; a moderator raises it when a stream gets busy.
  chatSlowMode Int     @default(0)

  chatMessages LiveChatMessage[]
  chatMutes    LiveChatMute[]

  createdAt DateTime @default(now())
  updatedAt DateTime @updatedAt

  @@index([startAt])
}

enum HomeRowType {
  CONTINUE_WATCHING
  RECOMMENDATIONS
  TRENDING
  RECENTLY_ADDED
  CATEGORY
  TAG
}

// Admin-configurable ordering/visibility of the homepage's rows. The four
// built-in types (seeded once, like Plugin rows) toggle/reorder the
// existing hardcoded sections; CATEGORY/TAG rows are curated additions
// pointing at a category or tag. `title` overrides each row's default label.
model HomeRow {
  id         String      @id @default(cuid())
  type       HomeRowType
  title      String?
  enabled    Boolean     @default(true)
  position   Int         @default(0)
  category   Category?   @relation(fields: [categoryId], references: [id], onDelete: Cascade)
  categoryId String?
  tag        String?
  createdAt  DateTime    @default(now())
  updatedAt  DateTime    @updatedAt

  @@index([categoryId])
}

// A member following a series or category (exactly one of seriesId/categoryId
// is set), so they can be notified when new content is published under it
// and see it on a "Subscriptions" page. Distinct from Favorites/Watch Later,
// which bookmark specific items rather than following a source going forward.
model Subscription {
  id         String    @id @default(cuid())
  user       User      @relation(fields: [userId], references: [id], onDelete: Cascade)
  userId     String
  series     Series?   @relation(fields: [seriesId], references: [id], onDelete: Cascade)
  seriesId   String?
  category   Category? @relation(fields: [categoryId], references: [id], onDelete: Cascade)
  categoryId String?
  // Keeps the follow (still listed on /subscriptions) but excludes this
  // subscriber from push notifications for new content under it.
  muted      Boolean   @default(false)
  createdAt  DateTime  @default(now())

  @@unique([userId, seriesId])
  @@unique([userId, categoryId])
  @@index([seriesId])
  @@index([categoryId])
}

// One row per notification a DAILY-frequency user would otherwise have
// received instantly. The digest cron (see /api/cron/notification-digest)
// batches every pending row per user into a single push, then deletes them.
model PendingNotification {
  id        String   @id @default(cuid())
  user      User     @relation(fields: [userId], references: [id], onDelete: Cascade)
  userId    String
  title     String
  body      String
  url       String?
  createdAt DateTime @default(now())

  @@index([userId])
}

// A member-created ordered collection of videos, distinct from the single
// site-wide "Watch later" queue.
model Playlist {
  id        String         @id @default(cuid())
  user      User           @relation(fields: [userId], references: [id], onDelete: Cascade)
  userId    String
  title     String
  // Lets anyone with the link view (read-only) this playlist at /playlists/[id], regardless of who's logged in.
  public    Boolean        @default(false)
  createdAt DateTime       @default(now())
  updatedAt DateTime       @updatedAt
  items     PlaylistItem[]

  @@index([userId])
}

model PlaylistItem {
  id         String   @id @default(cuid())
  playlist   Playlist @relation(fields: [playlistId], references: [id], onDelete: Cascade)
  playlistId String
  video      Video    @relation(fields: [videoId], references: [id], onDelete: Cascade)
  videoId    String
  position   Int      @default(0)
  createdAt  DateTime @default(now())

  @@unique([playlistId, videoId])
  @@index([videoId])
}

// A member's like/dislike on a series or video (exactly one of seriesId/videoId
// is set), separate from the 1-5 star Ratings plugin.
model Reaction {
  id        String       @id @default(cuid())
  user      User         @relation(fields: [userId], references: [id], onDelete: Cascade)
  userId    String
  series    Series?      @relation(fields: [seriesId], references: [id], onDelete: Cascade)
  seriesId  String?
  video     Video?       @relation(fields: [videoId], references: [id], onDelete: Cascade)
  videoId   String?
  type      ReactionType
  createdAt DateTime     @default(now())
  updatedAt DateTime     @updatedAt

  @@unique([userId, seriesId])
  @@unique([userId, videoId])
  @@index([seriesId])
  @@index([videoId])
}

// A timestamped view of a series or video (exactly one of seriesId/videoId is
// set), distinct from the simple all-time `viewCount` counter. Powers the
// homepage "Trending" row (views in a recent window) and the admin analytics
// dashboard. Logged for anonymous viewers too, with userId left null.
model ViewEvent {
  id        String   @id @default(cuid())
  series    Series?  @relation(fields: [seriesId], references: [id], onDelete: Cascade)
  seriesId  String?
  video     Video?   @relation(fields: [videoId], references: [id], onDelete: Cascade)
  videoId   String?
  userId    String?
  /// An HMAC of the viewer's address, so a repeat view from the same place
  /// within the throttle window is not counted twice. Not the address, and
  /// blanked after a day by the digest job — see lib/view-key.ts.
  ipHash    String?
  createdAt DateTime @default(now())

  @@index([seriesId, createdAt])
  @@index([videoId, createdAt])
  @@index([createdAt])
  @@index([ipHash, createdAt])
}

/// A hymn somebody actually opened.
///
/// Distinct from ViewEvent, which counts series and videos: a hymn is neither.
/// It is also two different things — a file of its own, or a number inside a
/// whole-book hymnal — so both are recorded here, `number` telling them apart
/// (see lib/hymnal.ts for the split).
///
/// Written from the browser rather than when a page renders, because Next
/// prefetches links on hover: a server-side count would mostly measure mice
/// moving over a list. What that costs is honesty about the failure — an ad
/// blocker or a closed tab means a lookup goes unrecorded — which is the
/// right trade for a number nothing depends on except an admin's sense of
/// what the congregation sings.
model HymnLookup {
  id        String    @id @default(cuid())
  file      FileAsset @relation(fields: [fileId], references: [id], onDelete: Cascade)
  fileId    String
  /// The number on the board, for a hymn inside a book; null for a hymn that
  /// is its own file, which the file itself identifies.
  number    Int?
  /// Where it was opened from — "hymn", "book", "reader", "present" — so a
  /// count can be read knowing what kind of opening it counts.
  source    String
  userId    String?
  createdAt DateTime  @default(now())

  @@index([createdAt])
  @@index([fileId, number, createdAt])
}

// Granular content-viewing access, layered on top of the plain `memberOnly`
// gate. As soon as a series/video has any SeriesViewerGroup/SeriesViewer (or
// Video equivalent) row, `memberOnly` stops being the gate for it: only
// members of one of the linked PermissionGroups (a "role"), or a
// specifically-granted user, can view it — see canViewSeries/canViewVideo in
// src/lib/content.ts. With no such rows, behavior is unchanged (public or
// any logged-in member per `memberOnly`).
model SeriesViewerGroup {
  id        String          @id @default(cuid())
  series    Series          @relation(fields: [seriesId], references: [id], onDelete: Cascade)
  seriesId  String
  group     PermissionGroup @relation(fields: [groupId], references: [id], onDelete: Cascade)
  groupId   String
  createdAt DateTime        @default(now())

  @@unique([seriesId, groupId])
  @@index([groupId])
}

// A specific user granted access to a restricted series regardless of role.
model SeriesViewer {
  id        String   @id @default(cuid())
  series    Series   @relation(fields: [seriesId], references: [id], onDelete: Cascade)
  seriesId  String
  user      User     @relation(fields: [userId], references: [id], onDelete: Cascade)
  userId    String
  createdAt DateTime @default(now())

  @@unique([seriesId, userId])
  @@index([userId])
}

model VideoViewerGroup {
  id        String          @id @default(cuid())
  video     Video           @relation(fields: [videoId], references: [id], onDelete: Cascade)
  videoId   String
  group     PermissionGroup @relation(fields: [groupId], references: [id], onDelete: Cascade)
  groupId   String
  createdAt DateTime        @default(now())

  @@unique([videoId, groupId])
  @@index([groupId])
}

// A specific user granted access to a restricted video regardless of role.
model VideoViewer {
  id        String   @id @default(cuid())
  video     Video    @relation(fields: [videoId], references: [id], onDelete: Cascade)
  videoId   String
  user      User     @relation(fields: [userId], references: [id], onDelete: Cascade)
  userId    String
  createdAt DateTime @default(now())

  @@unique([videoId, userId])
  @@index([userId])
}

// A member's private, timestamped note on a video (e.g. sermon notes taken
// while watching). The timestamp is manually entered, not synced to actual
// playback — the same limitation as Chapters, since Bunny's embed has no
// postMessage API to read real playback position from.
/// One member's answers to a video's fill-in-the-blank note outline.
///
/// The outline is on the Video (`noteOutline`), like the transcript: it is
/// one piece of text an admin writes. The answers are per member and stored
/// as a map from a gap's position to what they typed.
///
/// Position is the only identity a gap has, which is exactly the risk when an
/// outline is edited afterwards — insert a gap at the top and every answer
/// below belongs to the wrong one. `outlineVersion` records what the outline
/// said when these were written (see fingerprintOutline), so the page can say
/// the sheet changed rather than quietly shuffling somebody's notes.
model SermonOutlineAnswer {
  id             String   @id @default(cuid())
  user           User     @relation(fields: [userId], references: [id], onDelete: Cascade)
  userId         String
  video          Video    @relation(fields: [videoId], references: [id], onDelete: Cascade)
  videoId        String
  /// `{ "0": "unearned", "3": "free" }` — only the gaps actually filled in.
  answers        Json
  outlineVersion String
  updatedAt      DateTime @updatedAt

  @@unique([userId, videoId])
  @@index([videoId])
}

model SermonNote {
  id               String   @id @default(cuid())
  user             User     @relation(fields: [userId], references: [id], onDelete: Cascade)
  userId           String
  video            Video    @relation(fields: [videoId], references: [id], onDelete: Cascade)
  videoId          String
  timestampSeconds Int      @default(0)
  body             String
  createdAt        DateTime @default(now())
  updatedAt        DateTime @updatedAt

  @@index([userId, videoId])
}

enum SlugAliasType {
  SERIES
  VIDEO
}

// Remembers a series/video's previous slug after an admin renames it, so an
// old shared link 301s to the new one instead of 404ing. No FK to the
// target row (loose reference by id, like AuditLog) — if the row is later
// hard-deleted (trash purge), the alias just stops resolving to anything.
model SlugAlias {
  id        String        @id @default(cuid())
  type      SlugAliasType
  oldSlug   String
  targetId  String
  createdAt DateTime      @default(now())

  @@unique([type, oldSlug])
  @@index([targetId])
}

// A revocable, tracked link to one series or video (exactly one of
// seriesId/videoId is set, like Reaction/Subscription), opened at
// /s/[token]. Distinct from just copying the page URL: the sharer sees
// every link they've handed out, how often it's been opened, and can
// revoke it at any time.
//
// `grantsAccess` is what makes a link more than a URL — see
// src/lib/share-links.ts for who is allowed to set it. It's opt-in per
// link: a sharer holding the `share_content` capability (or an admin)
// chooses whether this particular link overrides the member-only/viewer
// restriction, which is how one guest can be let in without opening the
// content to anyone else. Left false, the link is an ordinary tracked
// link that anyone may create.
model ShareLink {
  id                   String               @id @default(cuid())
  // The unguessable secret in the URL. Long enough not to be brute-forced;
  // the row is the only place it's stored, so revoking really does kill it.
  token                String               @unique
  createdBy            User                 @relation(fields: [createdById], references: [id], onDelete: Cascade)
  createdById          String
  series               Series?              @relation(fields: [seriesId], references: [id], onDelete: Cascade)
  seriesId             String?
  video                Video?               @relation(fields: [videoId], references: [id], onDelete: Cascade)
  videoId              String?
  visibility           ShareVisibility      @default(PUBLIC)
  // Whether opening the link lets the recipient view content they couldn't
  // otherwise (member-only, or restricted to viewer groups/users). False for
  // links to content that was already public, which need no grant at all.
  grantsAccess         Boolean              @default(false)
  // Optional sharer-facing label ("sent to the elders"), so a long list of
  // links stays legible months later.
  note                 String?
  // Optional second factor the sharer can add: scrypt hash + salt of a
  // passphrase the recipient must type at /s/[token] before the link does
  // anything (see src/lib/share-password.ts). Never sent to a client — the
  // API strips it, and it can't be shown back to the sharer either.
  passwordHash         String?
  // Brute-force brake on that passphrase. Counted in the row rather than in
  // memory because this runs on serverless functions with no shared process
  // state — the same reasoning as src/lib/rate-limit.ts. Reset on success.
  failedUnlockAttempts Int                  @default(0)
  lastFailedUnlockAt   DateTime?
  expiresAt            DateTime?
  // Set instead of deleting the row, so a revoked link stays visible (and
  // auditable) in the sharer's list and the admin panel.
  revokedAt            DateTime?
  viewCount            Int                  @default(0)
  lastViewedAt         DateTime?
  createdAt            DateTime             @default(now())
  recipients           ShareLinkRecipient[]

  @@index([createdById])
  @@index([seriesId])
  @@index([videoId])
}

// One allowed recipient of an EMAIL-visibility share link. Stored lowercased,
// and matched against the logged-in session's email — so forwarding the link
// on doesn't hand access to whoever receives it.
model ShareLinkRecipient {
  id          String    @id @default(cuid())
  shareLink   ShareLink @relation(fields: [shareLinkId], references: [id], onDelete: Cascade)
  shareLinkId String
  email       String
  createdAt   DateTime  @default(now())

  @@unique([shareLinkId, email])
}

// Site-wide download settings: a single row, seeded on first read the same
// way Plugin and HomeRow rows are (see ensureDownloadPolicy). The Downloads
// plugin is the master on/off switch; this is everything else about *how*
// downloading works, kept in a row rather than env vars so it's editable at
// /admin/downloads without a redeploy.
//
// What may be downloaded is a separate question, answered by the tri-state
// `downloadEnabled` on Category/Series/Video — see resolveDownloadEnabled.
model DownloadPolicy {
  // Fixed id: there is only ever one of these.
  id          String                @id @default("singleton")
  platform    DownloadPlatform      @default(BOTH)
  audience    DownloadAudience      @default(ALL_MEMBERS)
  // Cap on how much one device is encouraged to hold, shown in the profile's
  // download manager. Advisory: the browser's own storage quota is the real
  // limit, and this never blocks a download that's already started.
  maxDeviceGb Int                   @default(8)
  groups      DownloadPolicyGroup[]
  users       DownloadPolicyUser[]
  updatedAt   DateTime              @updatedAt
}

// A permission group allowed to download, when audience is SPECIFIC.
model DownloadPolicyGroup {
  id       String          @id @default(cuid())
  policy   DownloadPolicy  @relation(fields: [policyId], references: [id], onDelete: Cascade)
  policyId String
  group    PermissionGroup @relation(fields: [groupId], references: [id], onDelete: Cascade)
  groupId  String

  @@unique([policyId, groupId])
  @@index([groupId])
}

// One person allowed to download regardless of group, when audience is
// SPECIFIC — the same "groups or named individuals" shape as the viewer
// grants on a restricted series/video.
model DownloadPolicyUser {
  id       String         @id @default(cuid())
  policy   DownloadPolicy @relation(fields: [policyId], references: [id], onDelete: Cascade)
  policyId String
  user     User           @relation(fields: [userId], references: [id], onDelete: Cascade)
  userId   String

  @@unique([policyId, userId])
  @@index([userId])
}

// Whether /auth/guest — the login path that skips the organization
// requirement for an organizationExempt AuthorizedEmail row — is reachable at
// all. Defaults closed: an admin has to deliberately turn it on, rather than
// the route being live from the moment a single row happens to be flagged
// exempt. Off, it 404s indistinguishably from "no organization is required
// here in the first place" — see src/app/auth/guest/route.ts. A DB-backed
// singleton rather than an env var, so switching it off again after a guest's
// visit is over needs no redeploy — see src/lib/authorization.ts.
model AuthSettings {
  id                String   @id @default("singleton")
  guestLoginEnabled Boolean  @default(false)
  updatedAt         DateTime @updatedAt
}

// The email allowlist: half of the security model, the other half being
// membership of the Auth0 organization. Both must pass — see
// src/lib/authorization.ts. A row here is not a user account and grants
// nothing on its own; it says "if this person authenticates as a member of the
// organization, let them in".
//
// `email` is always stored normalized (trimmed, lowercased) so the unique
// index is the actual guarantee against duplicates — "Alice@Example.com" and
// " alice@example.com " cannot both exist, and neither can be used to slip
// past a lookup. Never write to this table without normalizeEmail().
model AuthorizedEmail {
  id                 String                @id @default(cuid())
  email              String                @unique
  status             AuthorizedEmailStatus @default(ACTIVE)
  // When true, this specific address gets in on this row alone — organization
  // membership is not required of them, even under BOTH mode. The targeted
  // alternative to switching the whole deployment to EITHER mode: the default
  // stays "you must be an organization member," and an admin opts a named
  // guest out of that one check for that one address, rather than opting
  // every allowlisted address out of it at once.
  organizationExempt Boolean               @default(false)
  // Free-text reminder of why this person is on the list ("2026 elders").
  note               String?
  // Who added it. The relation is nullable and SetNull on delete so removing
  // an admin's account doesn't cascade away the access they granted, while
  // `addedByEmail` keeps the record legible after that relation is gone —
  // the same denormalization AuditLog uses.
  addedBy            User?                 @relation("AuthorizedEmailAddedBy", fields: [addedById], references: [id], onDelete: SetNull)
  addedById          String?
  addedByEmail       String?
  createdAt          DateTime              @default(now())
  updatedAt          DateTime              @updatedAt

  @@index([createdAt])
}

// A refused login, signup, or request from an existing session. Deliberately
// holds no credential material of any kind — no tokens, codes, or passwords —
// only the facts an administrator needs to decide whether someone should have
// been let in.
//
// Pruned by retention (see pruneAccessAttempts), so this can't grow forever on
// a site being probed.
model UnauthorizedAccessAttempt {
  id                 String             @id @default(cuid())
  createdAt          DateTime           @default(now())
  // Null when Auth0 refused before telling us who it was.
  email              String?
  auth0UserId        String?
  // The connection behind the attempt, e.g. "google-oauth2" — useful for
  // spotting someone repeatedly trying a personal Google account.
  provider           String?
  attemptType        AccessAttemptType
  organizationMember Boolean            @default(false)
  emailAuthorized    Boolean            @default(false)
  reason             AccessDenialReason
  // The Auth0 SDK's own error code/message for an AUTH0_CALLBACK_ERROR row —
  // e.g. "authorization_error: user does not belong to organization". Null
  // for every other reason, which is already fully explained by `reason`
  // itself. Never contains a token, code, or secret: the SDK's callback
  // errors are error classifications and human-readable descriptions, not
  // credential material — see SdkError in the Auth0 SDK.
  detail             String?
  ipAddress          String?
  userAgent          String?
  // Set once an administrator has been emailed about this attempt, which is
  // also what the notification cooldown is measured against.
  notifiedAt         DateTime?
  reviewedAt         DateTime?
  reviewedByEmail    String?

  @@index([createdAt])
  @@index([email])
  @@index([provider])
  @@index([reason])
  // The notification cooldown asks "was this email notified about recently?",
  // which is this pair rather than either column alone.
  @@index([email, notifiedAt])
}

// A member's kept copy of a notification, shown in their profile inbox.
// Written alongside every push/email send (see notifySubscribers), so the
// inbox has a full history even for members who never allowed push or who
// read it on a different device. Unlike PendingNotification — which is a
// short-lived queue the digest cron drains and deletes — these rows stay
// until the member deletes them.
model Notification {
  id        String    @id @default(cuid())
  user      User      @relation(fields: [userId], references: [id], onDelete: Cascade)
  userId    String
  title     String
  body      String
  url       String?
  readAt    DateTime?
  createdAt DateTime  @default(now())

  @@index([userId, createdAt])
}

// The deployment's own skin: the name shown in the header and the three accent
// colours everything else is derived from. A singleton, like AuthSettings.
//
// This lives in the database rather than in code or an env var because
// re-skinning is an admin's job, not a deploy: a congregation renaming itself
// or adopting new colours shouldn't need a developer. Only three colours are
// stored — every other token (hover tints, gradients, the dark-mode accent) is
// derived from them in src/lib/branding.ts, so the admin form stays three
// swatches rather than thirty.
model BrandSettings {
  id         String   @id @default("singleton")
  name       String   @default("Marine Team")
  shortName  String   @default("Marine Team")
  // Stored as #rrggbb. Validated on write; validated again on read, since a
  // bad value here would paint the whole site.
  brand      String   @default("#1a8fd1")
  brandDeep  String   @default("#0288d1")
  brandLight String   @default("#4fc3f7")
  logoUrl    String?
  updatedAt  DateTime @updatedAt
}

// ---------------------------------------------------------------------------
// Schedules — the rotas a group runs, from a spreadsheet or from the admin
// interface. Ported from the calendar app; see FEATURES.md → Schedules.
//
// Deliberately separate from ServicePlan and its rota. That one schedules
// *accounts* against a service's running order; this one schedules *names*,
// most of which never log in, against any number of recurring rotas — and
// takes them from a Google Sheet somebody is already maintaining. They answer
// different questions and are not worth forcing into one model.
// ---------------------------------------------------------------------------

enum ScheduleSourceType {
  WEB
  GOOGLE_SHEETS
}

/// Layout of the spreadsheet a Google Sheets schedule points at.
enum SheetFormat {
  /// `Date | Names` where Names is a delimited list ("Devin, Cindy").
  DATE_NAMES
  /// `Date | Devin | Cindy | ...` where each person is a column marked "x".
  NAME_COLUMNS
}

enum ScheduleSyncStatus {
  NEVER
  RUNNING
  SUCCESS
  PARTIAL
  FAILED
}

enum CalendarEventStatus {
  CONFIRMED
  TENTATIVE
  CANCELLED
}

model Schedule {
  id           String             @id @default(cuid())
  /// URL/filter friendly key, e.g. "breakbread". Stable across renames.
  slug         String             @unique
  name         String
  description  String?
  /// Emoji or short icon token rendered by the UI.
  icon         String             @default("calendar")
  /// Accent token resolved by src/lib/schedules/colors.ts.
  color        String             @default("slate")
  enabled      Boolean            @default(true)
  displayOrder Int                @default(0)
  sourceType   ScheduleSourceType @default(WEB)

  source ScheduleSource?
  events CalendarEvent[]

  /// Soft delete, so a device syncing incrementally is told to drop its
  /// cached rows rather than keeping a schedule that has gone.
  deletedAt DateTime?
  createdAt DateTime  @default(now())
  updatedAt DateTime  @updatedAt

  @@index([enabled, displayOrder])
  @@index([updatedAt])
}

/// Per-schedule source configuration, in its own table so a schedule can
/// switch source type without carrying dead columns around.
model ScheduleSource {
  id         String   @id @default(cuid())
  scheduleId String   @unique
  schedule   Schedule @relation(fields: [scheduleId], references: [id], onDelete: Cascade)

  type ScheduleSourceType

  // --- Google Sheets fields (null for WEB schedules) ---
  spreadsheetId String?
  sheetName     String?
  /// Optional A1 range within the sheet, e.g. "A1:F200".
  range         String?
  format        SheetFormat?

  /// Parser tuning: header row, column mapping, name delimiter, truthy
  /// markers, default year, timezone. Shape validated by Zod before it is
  /// ever read — see src/lib/sheets/config.ts.
  parserConfig Json @default("{}")

  /// 0 disables automatic sync for this schedule.
  syncIntervalMinutes Int @default(60)

  lastSyncedAt   DateTime?
  lastSyncStatus ScheduleSyncStatus @default(NEVER)
  lastSyncError  String?
  /// Hash of the last successfully imported payload, so a sync that finds
  /// nothing changed upstream does no writes.
  lastSyncHash   String?

  createdAt DateTime @default(now())
  updatedAt DateTime @updatedAt
}

/// Somebody who appears on a rota.
///
/// Not a User: most people on a church rota never log in, and the names come
/// out of a spreadsheet. `userId` links the two where they are the same
/// person, which is what lets a signed-in member see "only mine" without
/// being asked who they are.
model Person {
  id String @id @default(cuid())

  /// Lowercased, whitespace-collapsed, accent-folded. The matching key.
  normalizedName String  @unique
  /// Preferred spelling, shown in the UI.
  displayName    String
  active         Boolean @default(true)

  user   User?   @relation(fields: [userId], references: [id], onDelete: SetNull)
  userId String? @unique

  aliases PersonAlias[]
  events  CalendarEventPerson[]

  deletedAt DateTime?
  createdAt DateTime  @default(now())
  updatedAt DateTime  @updatedAt

  @@index([active, displayName])
  @@index([updatedAt])
}

/// Extra spellings that resolve to the same person, so renaming somebody in a
/// spreadsheet doesn't split their history across two records.
model PersonAlias {
  id             String @id @default(cuid())
  personId       String
  person         Person @relation(fields: [personId], references: [id], onDelete: Cascade)
  normalizedName String @unique

  createdAt DateTime @default(now())

  @@index([personId])
}

model CalendarEvent {
  id         String   @id @default(cuid())
  scheduleId String
  schedule   Schedule @relation(fields: [scheduleId], references: [id], onDelete: Cascade)

  /// Stable key derived from the source row, so re-syncing a sheet updates
  /// rows rather than duplicating them. Null for events added by hand.
  externalId String?

  /// A calendar day, stored without a time so it can't drift by a timezone.
  date      DateTime  @db.Date
  /// The last day, for something spanning more than one.
  endDate   DateTime? @db.Date
  allDay    Boolean   @default(true)
  /// "HH:mm" local to the schedule; null when allDay.
  startTime String?
  endTime   String?

  title    String?
  notes    String?
  location String?
  status   CalendarEventStatus @default(CONFIRMED)

  // --- recurrence: modelled, but the admin writes single occurrences ---
  /// An RFC 5545 RRULE, e.g. "FREQ=WEEKLY;BYDAY=SU". Null for a one-off.
  recurrenceRule    String?
  recurrenceEndDate DateTime?       @db.Date
  /// Set on an occurrence that overrides or cancels one instance of a series.
  parentEventId     String?
  parent            CalendarEvent?  @relation("CalendarEventSeries", fields: [parentEventId], references: [id], onDelete: Cascade)
  occurrences       CalendarEvent[] @relation("CalendarEventSeries")

  /// Where this row came from, recorded at write time so a sync never
  /// clobbers an event somebody added by hand.
  origin    ScheduleSourceType @default(WEB)
  /// 1-based row in the source sheet, for telling an admin where a problem is.
  sourceRow Int?

  people CalendarEventPerson[]

  deletedAt DateTime?
  createdAt DateTime  @default(now())
  updatedAt DateTime  @updatedAt

  @@unique([scheduleId, externalId])
  @@index([scheduleId, date])
  @@index([date])
  @@index([updatedAt])
  @@index([parentEventId])
}

model CalendarEventPerson {
  id       String        @id @default(cuid())
  eventId  String
  event    CalendarEvent @relation(fields: [eventId], references: [id], onDelete: Cascade)
  personId String
  person   Person        @relation(fields: [personId], references: [id], onDelete: Cascade)
  /// An optional job, e.g. "Bread", "Cup", "Driver".
  role     String?

  /// Order within the event, as the source listed them. On a bread-and-cup
  /// rota "Devin & Cindy" is not the same as "Cindy & Devin", so this is
  /// displayed order rather than a sort key.
  position Int @default(0)

  createdAt DateTime @default(now())

  @@unique([eventId, personId])
  @@index([personId])
  @@index([eventId, position])
}

/// An event people sign up for: a men's breakfast, a youth weekend, a carol
/// service with limited seats.
///
/// Separate from `CalendarEvent` (a rota date read off a spreadsheet) and from
/// `ServicePlan` (the running order of a Sunday) on purpose. Those two answer
/// "who is on" and "what is being sung"; this one answers "is there a place
/// for me", which is the only one of the three with a number that can run out.
model Event {
  id          String  @id @default(cuid())
  slug        String  @unique
  title       String
  description String?
  location    String?

  startsAt DateTime
  /// Null for something with no stated finish.
  endsAt   DateTime?
  allDay   Boolean   @default(false)

  /// Drafts stay off the members' list until somebody is happy with it.
  published  Boolean @default(false)
  /// Only signed-in members may see it, and so only they may register.
  memberOnly Boolean @default(false)

  /// Whether sign-up is offered at all. An event can be worth publishing
  /// with nothing to fill in — a carol service everyone simply comes to.
  registration Boolean   @default(false)
  /// Places, counting guests. Null is unlimited.
  capacity     Int?
  /// Whether a full event still takes names, in order, for a place that frees up.
  waitlist     Boolean   @default(true)
  /// The window sign-up is open in. Either end may be absent.
  opensAt      DateTime?
  closesAt     DateTime?
  /// How many extra people one registration may bring. 0 means just yourself.
  maxGuests    Int       @default(0)

  /// The repeating thing this is one date of, when it is one. Null for the
  /// one-off events that most of them are.
  ///
  /// `SetNull` rather than `Cascade`, deliberately: an occurrence is a real
  /// event with real names on it, and stopping a series repeating must never
  /// be a way to delete somebody's place. Detaching leaves what was booked
  /// exactly where it was.
  series         EventSeries? @relation(fields: [seriesId], references: [id], onDelete: SetNull)
  seriesId       String?
  /// Which of the series' dates this is. Kept as a plain calendar day, which
  /// is what the rule produces, so regenerating can tell what already exists
  /// without recomputing every timezone conversion.
  occurrenceDate DateTime?    @db.Date

  createdAt     DateTime            @default(now())
  updatedAt     DateTime            @updatedAt
  registrations EventRegistration[]

  @@unique([seriesId, occurrenceDate])
  @@index([published, startsAt])
  @@index([startsAt])
}

/// A repeating event: the rule, and the template each date is made from.
///
/// A series is not itself an event. That is the whole design: every date a
/// series produces is an ordinary `Event` row with its own slug, its own
/// capacity and its own sign-up list, because "is there a place for me on the
/// 14th" is a different question from "is there a place for me on the 21st".
/// Modelling the series as the first event instead — which the calendar port's
/// `CalendarEvent.parentEventId` does — makes deleting the first meeting of the
/// year an act that deletes the year.
///
/// Times are stored as a wall clock and a zone rather than as instants,
/// because "Tuesdays at 19:30" is a wall clock. See lib/recurrence.ts.
model EventSeries {
  id String @id @default(cuid())

  /// An RFC 5545 RRULE, e.g. "FREQ=WEEKLY;BYDAY=TU". Validated on write.
  rule            String
  /// The IANA zone the times below are read in.
  timeZone        String   @default("UTC")
  /// The first date the rule runs from, and always an occurrence itself.
  startDate       DateTime @db.Date
  /// "19:30". Null when the occurrences are all-day.
  startTime       String?
  /// How long one occurrence lasts. Null for something with no stated finish.
  durationMinutes Int?
  allDay          Boolean  @default(false)

  /// The template every generated occurrence is stamped from.
  title       String
  description String?
  location    String?
  published   Boolean @default(false)
  memberOnly  Boolean @default(false)

  registration     Boolean @default(false)
  capacity         Int?
  waitlist         Boolean @default(true)
  maxGuests        Int     @default(0)
  /// The sign-up window, relative to each occurrence rather than absolute:
  /// "opens two weeks before" is what a repeating event means, and copying one
  /// pair of instants onto every date would close December's sign-up in
  /// September.
  opensDaysBefore  Int?
  closesDaysBefore Int?

  /// The last day occurrences have been created up to, so extending the
  /// horizon is cheap and repeatable.
  generatedThrough DateTime?  @db.Date
  /// Dates an organiser took out of the series. Kept so that generating again
  /// doesn't quietly put them back.
  excludedDates    DateTime[] @db.Date

  events    Event[]
  createdAt DateTime @default(now())
  updatedAt DateTime @updatedAt

  @@index([generatedThrough])
}

enum RegistrationStatus {
  GOING
  WAITLIST
  CANCELLED
}

/// One sign-up.
///
/// `userId` is nullable because the people a church most wants at an event are
/// the ones who have never made an account. A registration always carries its
/// own name and email, even when an account is attached, so the list an
/// organiser prints is complete whether or not everyone on it is a member.
model EventRegistration {
  id      String  @id @default(cuid())
  event   Event   @relation(fields: [eventId], references: [id], onDelete: Cascade)
  eventId String
  user    User?   @relation(fields: [userId], references: [id], onDelete: SetNull)
  userId  String?

  name   String
  email  String
  phone  String?
  /// Extra people coming with them; a registration takes `1 + guests` places.
  guests Int     @default(0)
  note   String?

  status      RegistrationStatus @default(GOING)
  /// Set when a waitlisted place was promoted, so the confirmation can say so
  /// and an organiser can see it happened.
  promotedAt  DateTime?
  cancelledAt DateTime?

  createdAt DateTime @default(now())
  updatedAt DateTime @updatedAt

  /// One live registration per account per event. Cancelling keeps the row —
  /// it is the record that they were coming — so a re-registration reuses it.
  @@unique([eventId, userId])
  @@index([eventId, status, createdAt])
  @@index([userId])
}

/// A form somebody fills in: the connect card in the pew, a "would you like a
/// visit", a camp application.
///
/// Built by an admin rather than by a developer, because the questions change
/// every term and a deploy is the wrong unit of change for "add a box for
/// dietary requirements".
model Form {
  id          String  @id @default(cuid())
  slug        String  @unique
  title       String
  description String?

  published    Boolean @default(false)
  /// Only signed-in members may open it. Off by default: the whole point of a
  /// connect card is the person who has just walked in.
  memberOnly   Boolean @default(false)
  /// Whether the same person may send it more than once. A connect card, yes;
  /// a camp application, generally not.
  multiple     Boolean @default(true)
  /// Shown after sending, in place of the form.
  confirmation String?
  /// Addresses told about a submission, comma-separated. Kept on the form
  /// rather than in an env var: different forms reach different people, and
  /// the person who knows which is the one editing the form.
  notifyEmails String?

  fields      FormField[]
  submissions FormSubmission[]

  createdAt DateTime @default(now())
  updatedAt DateTime @updatedAt
}

enum FormFieldType {
  TEXT
  TEXTAREA
  EMAIL
  PHONE
  NUMBER
  DATE
  SELECT
  RADIO
  CHECKBOX
  CHECKBOXES
}

/// One question.
///
/// Soft-deleted rather than removed: an answer points at the field it answered,
/// so deleting the row outright would leave a year of submissions with a column
/// nobody can name. A deleted field simply stops being asked.
model FormField {
  id       String        @id @default(cuid())
  form     Form          @relation(fields: [formId], references: [id], onDelete: Cascade)
  formId   String
  label    String
  type     FormFieldType @default(TEXT)
  /// A line under the question, for the thing the label can't say briefly.
  help     String?
  required Boolean       @default(false)
  /// One option per line, for the types that offer a choice.
  options  String?
  position Int           @default(0)

  deletedAt DateTime?
  createdAt DateTime     @default(now())
  answers   FormAnswer[]

  @@index([formId, position])
}

model FormSubmission {
  id     String  @id @default(cuid())
  form   Form    @relation(fields: [formId], references: [id], onDelete: Cascade)
  formId String
  /// Null when a visitor sent it, which is most of them.
  user   User?   @relation(fields: [userId], references: [id], onDelete: SetNull)
  userId String?

  /// Whoever has read it, so a card doesn't get followed up twice or not at all.
  handledAt DateTime?
  handledBy String?

  answers   FormAnswer[]
  createdAt DateTime     @default(now())

  @@index([formId, createdAt])
  @@index([userId])
}

/// One answer, tied to the field it answered rather than to the label it was
/// asked under — so renaming a question doesn't rewrite history.
model FormAnswer {
  id           String         @id @default(cuid())
  submission   FormSubmission @relation(fields: [submissionId], references: [id], onDelete: Cascade)
  submissionId String
  field        FormField      @relation(fields: [fieldId], references: [id], onDelete: Cascade)
  fieldId      String
  /// Every answer is text. A number typed into a form is a number somebody
  /// typed, and a checkbox list is its chosen options joined by a newline —
  /// which keeps one shape to read, export and search.
  value        String

  @@unique([submissionId, fieldId])
  @@index([fieldId])
}

/// Who may read a prayer request.
enum PrayerVisibility {
  /// Anyone who can open the page, account or not.
  EVERYONE
  /// Signed-in members.
  MEMBERS
  /// Only whoever moderates the wall. For the ones that shouldn't be a wall.
  LEADERS
}

enum PrayerStatus {
  /// Written, not yet let through. The default, deliberately.
  PENDING
  APPROVED
  /// Approved, and since answered — the reason a prayer wall is worth keeping.
  ANSWERED
  /// Taken down by a moderator. Kept rather than deleted so the decision is a
  /// record, and so taking one down twice isn't a different outcome.
  HIDDEN
}

/// A prayer request.
///
/// The whole feature is a moderation problem wearing a list. Three things here
/// are load-bearing and are enforced in `lib/prayer.ts` rather than left to
/// whoever writes the next query:
///
///   1. Nothing is visible until somebody lets it through.
///   2. `anonymous` means the name never leaves the server — the row still
///      knows whose it is, so they can edit and delete it, and so a moderator
///      can act if it is abusive, but no read path a member can reach carries
///      it.
///   3. `visibility` is checked on every read, not only on the page that
///      happens to list them.
model PrayerRequest {
  id     String  @id @default(cuid())
  /// Null when a visitor wrote it. Present but withheld when `anonymous`.
  user   User?   @relation(fields: [userId], references: [id], onDelete: SetNull)
  userId String?
  /// The name to show, when there is one to show. A visitor types theirs;
  /// a member's is taken from their account at the time of writing, so
  /// changing a display name later doesn't rewrite old requests.
  name   String?
  body   String

  anonymous  Boolean          @default(false)
  visibility PrayerVisibility @default(MEMBERS)
  status     PrayerStatus     @default(PENDING)

  /// What happened, written when it is marked answered.
  answeredNote String?
  answeredAt   DateTime?
  /// Who let it through, so a decision has a name against it.
  moderatedBy  String?
  moderatedAt  DateTime?

  prayers   PrayerIntercession[]
  createdAt DateTime             @default(now())
  updatedAt DateTime             @updatedAt

  @@index([status, createdAt])
  @@index([userId])
}

/// "I prayed for this."
///
/// A count rather than a list of names, on the page and in the model's use: the
/// number is an encouragement to whoever wrote the request, and the names
/// would turn it into a scoreboard. Stored per person only so that pressing it
/// twice isn't two.
model PrayerIntercession {
  id        String        @id @default(cuid())
  request   PrayerRequest @relation(fields: [requestId], references: [id], onDelete: Cascade)
  requestId String
  user      User          @relation(fields: [userId], references: [id], onDelete: Cascade)
  userId    String
  createdAt DateTime      @default(now())

  @@unique([requestId, userId])
  @@index([requestId])
}

/// A small group: a home group, a youth group, a men's Bible study.
///
/// The thing this model is most careful about is the address. A group that
/// meets in somebody's living room must not publish where they live to the
/// open internet — so `address` is separate from `area`, and only somebody in
/// the group is ever given it. Getting that wrong once is not recoverable.
model SmallGroup {
  id          String  @id @default(cuid())
  slug        String  @unique
  name        String
  description String?

  /// When it meets, as free text: "Tuesdays, 7.30pm", "alternate Wednesdays".
  /// Every group says it differently and a schedule table would be a second
  /// thing to keep true.
  meetsWhen String?
  /// Roughly where, and safe to publish: "North side", "near the station".
  area      String?
  /// Exactly where. Shown only to people in the group — see lib/groups.ts.
  address   String?

  published  Boolean @default(false)
  /// Whether it is taking new people at all. A group that is full is still
  /// worth listing, because the answer to "can I come" is then a real one.
  openToJoin Boolean @default(true)
  /// Roughly how many it can hold, counting only the people actually in it.
  capacity   Int?
  /// Whether a full group still takes names, in order, for a place that frees
  /// up. On by default: "come back in September" is a better answer than a
  /// closed door, and it costs the leader nothing until a place appears.
  waitlist   Boolean @default(true)

  members            SmallGroupMember[]
  createdAt          DateTime            @default(now())
  updatedAt          DateTime            @updatedAt
  smallGroupMeetings SmallGroupMeeting[]
  groupMessages      GroupMessage[]

  @@index([published])
}

enum GroupRole {
  LEADER
  MEMBER
}

enum GroupMemberStatus {
  /// Asked to join; the leader hasn't answered.
  REQUESTED
  ACTIVE
  /// The group was full when they asked, so their name is down but the ask is
  /// not yet in front of the leader. A place opening moves the longest-waiting
  /// one to REQUESTED — the leader still decides, because that decision is
  /// what the address travels with.
  WAITLIST
  /// The leader said no, or somebody left. Kept so the same person asking
  /// again is a conversation rather than a surprise.
  DECLINED
}

model SmallGroupMember {
  id      String     @id @default(cuid())
  group   SmallGroup @relation(fields: [groupId], references: [id], onDelete: Cascade)
  groupId String
  user    User       @relation(fields: [userId], references: [id], onDelete: Cascade)
  userId  String

  role   GroupRole         @default(MEMBER)
  status GroupMemberStatus @default(REQUESTED)
  /// Keeps them in the group but stops the thread notifying them — the same
  /// shape as a muted subscription.
  muted  Boolean           @default(false)
  /// What they said when they asked.
  note   String?

  respondedAt DateTime?
  createdAt   DateTime  @default(now())

  @@unique([groupId, userId])
  @@index([userId, status])
  @@index([groupId, status])
}

/// A message in a small group's own thread.
///
/// Not live chat: that opens around a stream and closes after it. This is the
/// thread a group keeps between meetings — "running ten minutes late", "here's
/// the passage for Tuesday" — and it is as private as the address, for the
/// same reason. Only people actually in the group may read it.
model GroupMessage {
  id      String     @id @default(cuid())
  group   SmallGroup @relation(fields: [groupId], references: [id], onDelete: Cascade)
  groupId String
  user    User       @relation(fields: [userId], references: [id], onDelete: Cascade)
  userId  String

  /// The name as it stood when they wrote it, so a later change of display
  /// name doesn't rewrite what a conversation looked like.
  authorName String
  body       String

  /// Taken down by a leader. Kept rather than deleted, so the same message
  /// can't be reposted past them and the decision survives.
  hidden Boolean @default(false)

  createdAt DateTime @default(now())

  @@index([groupId, id])
  @@index([userId])
}

enum GuideItemKind {
  /// Something to talk about.
  QUESTION
  /// A passage to read together.
  SCRIPTURE
  /// A line of context for everybody.
  NOTE
  /// For whoever is leading, and for nobody else. See lib/guides.ts — the
  /// visible shape of a guide has no field these can be rendered from.
  LEADER_NOTE
}

/// Something for a group to work through: the questions after a sermon.
///
/// Attached to a series or a video when it follows one, and free-standing when
/// it doesn't — a study on prayer belongs to no sermon. A meeting points at the
/// guide it used, so "what did we do in June" has an answer.
model DiscussionGuide {
  id          String  @id @default(cuid())
  slug        String  @unique
  title       String
  description String?

  /// What it follows, when it follows something.
  series   Series? @relation(fields: [seriesId], references: [id], onDelete: SetNull)
  seriesId String?
  video    Video?  @relation(fields: [videoId], references: [id], onDelete: SetNull)
  videoId  String?

  /// Drafts stay off the members' list until somebody is happy with it.
  published Boolean @default(false)

  items    DiscussionGuideItem[]
  meetings SmallGroupMeeting[]

  createdAt DateTime @default(now())
  updatedAt DateTime @updatedAt

  @@index([published, updatedAt])
  @@index([seriesId])
  @@index([videoId])
}

/// One line of a guide, in the order it is worked through.
model DiscussionGuideItem {
  id      String          @id @default(cuid())
  guide   DiscussionGuide @relation(fields: [guideId], references: [id], onDelete: Cascade)
  guideId String

  kind      GuideItemKind @default(QUESTION)
  body      String
  /// A reference the item hangs on, e.g. "Romans 8:28-30".
  reference String?

  position Int @default(0)

  @@index([guideId, position])
}

/// One evening a small group met.
///
/// A row rather than a computed date, because a group's schedule is free text
/// ("alternate Wednesdays") and always will be — there is nothing to expand.
/// A meeting exists because somebody says it happened, which is also the only
/// honest source for whether it did.
model SmallGroupMeeting {
  id      String     @id @default(cuid())
  group   SmallGroup @relation(fields: [groupId], references: [id], onDelete: Cascade)
  groupId String

  /// The day it met. A calendar day, not an instant — see lib/dates.ts.
  date DateTime @db.Date

  /// What they looked at, in the leader's words.
  topic String?

  /// How many people came who are not members of the group.
  ///
  /// A number, never names. Somebody who came once to a house group has not
  /// consented to being on a list, and a leader who wants to follow up has a
  /// connect card for that — which does ask. A count answers "how did it go"
  /// without quietly building a directory of visitors.
  visitorCount Int @default(0)

  /// Set when the meeting didn't happen. Kept rather than deleted: "we didn't
  /// meet that week" is a different fact from "nobody recorded anything", and
  /// only one of them needs chasing.
  cancelled Boolean @default(false)

  /// The leader's own notes. Never shown to members — see lib/attendance.ts.
  leaderNotes String?

  /// What they worked through, when they used one.
  guide   DiscussionGuide? @relation(fields: [guideId], references: [id], onDelete: SetNull)
  guideId String?

  /// Who wrote it down, so a roll has somebody's name against it.
  recordedByEmail String?

  attendance GroupAttendance[]

  createdAt DateTime @default(now())
  updatedAt DateTime @updatedAt

  /// One meeting per group per day. Two leaders opening the form at once must
  /// not produce two rolls that each hold half the answers.
  @@unique([groupId, date])
  @@index([groupId, date])
  @@index([guideId])
}

enum AttendanceStatus {
  PRESENT
  /// They said they couldn't come. Deliberately not the same as ABSENT: a
  /// group that can't tell "let us know" from "vanished" chases the wrong
  /// person, and the whole point of keeping a roll is knowing who to ring.
  APOLOGIES
  ABSENT
}

/// One member, at one meeting.
model GroupAttendance {
  id        String            @id @default(cuid())
  meeting   SmallGroupMeeting @relation(fields: [meetingId], references: [id], onDelete: Cascade)
  meetingId String
  user      User              @relation(fields: [userId], references: [id], onDelete: Cascade)
  userId    String

  status AttendanceStatus @default(PRESENT)
  /// "Away with work" — what they said when they sent apologies.
  note   String?

  createdAt DateTime @default(now())
  updatedAt DateTime @updatedAt

  @@unique([meetingId, userId])
  @@index([userId])
}

/// Who a broadcast goes to.
enum BroadcastAudience {
  /// Every authorized member.
  EVERYONE
  /// Everyone assigned a particular permission group.
  PERMISSION_GROUP
  /// Everyone signed up to an event — including the ones with no account,
  /// who are reachable by email and nothing else.
  EVENT
  /// A small group's active members.
  SMALL_GROUP
  /// A service team's members.
  TEAM
}

enum BroadcastStatus {
  DRAFT
  /// Recipients resolved and being worked through.
  SENDING
  SENT
  /// Stopped by hand part-way.
  CANCELLED
}

enum BroadcastChannel {
  EMAIL
  SMS
  PUSH
}

enum DeliveryStatus {
  PENDING
  SENT
  FAILED
  /// Nothing was wrong; there was simply no way to reach them on this channel
  /// — no phone number, or they never opted in.
  SKIPPED
}

/// One message sent to many people: "no service tomorrow, the road is closed".
///
/// The recipients are resolved into rows *before* anything is sent, and each
/// is marked as it goes. That is what makes a send resumable: a serverless
/// function killed half way through 400 emails has already recorded the 180 it
/// managed, and the next run picks up at 181 rather than emailing everybody
/// twice.
model Broadcast {
  id      String @id @default(cuid())
  subject String
  body    String

  /// EMAIL / SMS / PUSH, any combination.
  channels BroadcastChannel[]

  audience     BroadcastAudience @default(EVERYONE)
  /// The group, event, small group or team, when the audience names one.
  audienceId   String?
  /// What the audience was called when it was chosen, so a list of past
  /// broadcasts still reads sensibly after the group is renamed or deleted.
  audienceName String?

  status BroadcastStatus @default(DRAFT)

  /// Who sent it, for the list and the audit trail.
  createdBy String
  createdAt DateTime  @default(now())
  sentAt    DateTime?

  recipients BroadcastRecipient[]

  @@index([status, createdAt])
}

/// One person, one channel, one message.
///
/// The address is copied here at resolve time rather than read back through
/// the user: somebody who changes their number between composing and sending
/// should not have half a broadcast go to their old one and half to the new.
model BroadcastRecipient {
  id          String    @id @default(cuid())
  broadcast   Broadcast @relation(fields: [broadcastId], references: [id], onDelete: Cascade)
  broadcastId String
  /// Null for somebody reachable by email who has no account — an event's
  /// sign-ups are full of them.
  user        User?     @relation(fields: [userId], references: [id], onDelete: SetNull)
  userId      String?

  channel BroadcastChannel
  /// The email address or phone number, as it stood when the audience was resolved.
  address String
  /// Shown in the send report, so a failure names a person rather than an id.
  name    String?

  status DeliveryStatus @default(PENDING)
  error  String?
  sentAt DateTime?

  /// One row per person per channel, so a resumed run can't send twice.
  @@unique([broadcastId, channel, address])
  @@index([broadcastId, status])
}

/// Where a video is played from.
enum VideoSource {
  BUNNY
  YOUTUBE
  VIMEO
}

enum VideoFeedKind {
  YOUTUBE_CHANNEL
  YOUTUBE_PLAYLIST
  VIMEO_USER
  VIMEO_SHOWCASE
}

/// A channel, playlist or account to import videos from.
///
/// The point of this over uploading twice is a church that already streams to
/// YouTube every Sunday: the sermon is already there, and re-uploading it to
/// Bunny costs storage, bandwidth and somebody's Sunday afternoon.
model VideoFeed {
  id         String        @id @default(cuid())
  kind       VideoFeedKind
  /// The channel id, playlist id, Vimeo user id or showcase id.
  externalId String
  name       String

  /// Where imported videos land. One of these, or neither for unfiled.
  seriesId   String?
  categoryId String?

  /// Whether an import is published straight away. Off by default: a church
  /// that streams its whole service to YouTube does not want the twenty
  /// minutes of an empty stage before it starts appearing on its own site.
  autoPublish Boolean @default(false)
  /// How many to look back over on each sync. Enough to catch a re-upload,
  /// small enough that a daily job stays cheap.
  lookBack    Int     @default(25)
  enabled     Boolean @default(true)

  lastSyncedAt   DateTime?
  lastSyncStatus String?
  lastError      String?
  /// The upstream payload's fingerprint, so an unchanged feed does no writes.
  fingerprint    String?

  videos    Video[]
  createdAt DateTime @default(now())
  updatedAt DateTime @updatedAt

  @@unique([kind, externalId])
}

/// A message in the chat beside a live stream.
///
/// Polling rather than sockets, and that is a deployment fact rather than a
/// preference: this app runs on serverless functions with no long-lived
/// process to hold a socket open. `GET ?since=<id>` is what a page asks every
/// few seconds, which is cheap because the query is one indexed range scan.
model LiveChatMessage {
  id         String     @id @default(cuid())
  stream     LiveStream @relation(fields: [streamId], references: [id], onDelete: Cascade)
  streamId   String
  user       User       @relation(fields: [userId], references: [id], onDelete: Cascade)
  userId     String
  /// The name as it stood when they wrote it, so a later change of display
  /// name doesn't rewrite what a conversation looked like.
  authorName String
  body       String

  /// Taken down by a moderator. Kept rather than deleted so the same message
  /// can't be re-sent past a moderator by reposting, and so the record of the
  /// decision survives.
  hidden    Boolean  @default(false)
  createdAt DateTime @default(now())

  /// The poll: every message on this stream after the last one seen.
  @@index([streamId, id])
  @@index([userId])
}

/// Somebody stopped from writing in a stream's chat.
///
/// Per stream rather than site-wide: silencing somebody for one evening is the
/// proportionate act, and a site-wide ban is a different decision made
/// somewhere else.
model LiveChatMute {
  id        String     @id @default(cuid())
  stream    LiveStream @relation(fields: [streamId], references: [id], onDelete: Cascade)
  streamId  String
  user      User       @relation(fields: [userId], references: [id], onDelete: Cascade)
  userId    String
  /// Who did it, for the record.
  mutedBy   String
  createdAt DateTime   @default(now())

  @@unique([streamId, userId])
}

enum TvDeviceStatus {
  /// A code is on a screen; nobody has approved it yet.
  PENDING
  /// A member said yes. The device may exchange its secret for a token.
  APPROVED
  /// A member said no. Kept briefly so the television can say so rather than
  /// timing out with no explanation.
  DENIED
  /// Signed in. `tokenHash` is live.
  LINKED
  /// Signed out, from the television or from the member's own settings.
  REVOKED
}

/// A television signed in to somebody's account.
///
/// You cannot type an email address and a password with a remote control, so
/// this is the device authorization grant (RFC 8628) in the shape this app
/// needs: the television shows a short code, a member types that code on their
/// phone, and the television — which has been polling all along — receives a
/// long-lived token.
///
/// The split between the two codes is the whole security design and is not
/// cosmetic:
///
///   - `userCode` is **public**. It is displayed on a screen in a room that
///     may have a hundred people in it. It is short because somebody has to
///     read it off a television and type it with a remote.
///   - `deviceCode` is **secret**. Only the television that asked for the
///     pairing ever sees it, and only it can exchange an approval for a token.
///
/// Without that split, anybody who could see the screen — or guess six
/// characters — could take the token meant for the television.
model TvDevice {
  id String @id @default(cuid())

  /// Shown on the television. Short, from an alphabet with no characters that
  /// look like each other on a screen across a room.
  userCode       String         @unique
  /// Held only by the television. Hashed, like a password: a leaked database
  /// must not hand somebody a working pairing.
  deviceCodeHash String         @unique
  status         TvDeviceStatus @default(PENDING)

  /// What the television calls itself, so the approval screen can name it.
  /// Supplied by the device and shown as it was given, never trusted.
  deviceName String
  /// Roughly what kind of thing it is, for the member's list of signed-in TVs.
  deviceKind String?

  /// Set when a member approves it.
  user   User?   @relation(fields: [userId], references: [id], onDelete: Cascade)
  userId String?

  /// The long-lived token, hashed. Present once linked.
  tokenHash String? @unique

  /// A pairing nobody completes is rubbish after a few minutes.
  expiresAt  DateTime
  approvedAt DateTime?
  linkedAt   DateTime?
  lastSeenAt DateTime?
  revokedAt  DateTime?
  createdAt  DateTime  @default(now())

  @@index([userId, status])
  @@index([expiresAt])
}

Appendix C — URL inventory

91 pages and 218 routes, from the original src/app tree. [x] is a path parameter. Layouts wrap /admin/* (the admin shell, which requires staff) and /profile/* (the profile shell, which requires a member); /sitemap.xml is generated from getSitemapData() as Appendix A describes. Every path here must answer in the port.
C.1 — Pages (HTML)

    /
    /access-denied
    /admin
    /admin/access-attempts
    /admin/analytics
    /admin/announcements
    /admin/api-keys
    /admin/audit
    /admin/authorized-emails
    /admin/branding
    /admin/broadcasts
    /admin/categories
    /admin/categories/[id]
    /admin/comments
    /admin/downloads
    /admin/events
    /admin/events/[id]
    /admin/files
    /admin/forms
    /admin/forms/[id]
    /admin/groups
    /admin/groups/[id]
    /admin/home-rows
    /admin/live
    /admin/media-check
    /admin/people
    /admin/permissions
    /admin/plugins
    /admin/prayer
    /admin/query-monitor
    /admin/schedules
    /admin/schedules/[id]
    /admin/series
    /admin/series/[id]
    /admin/services
    /admin/services/report
    /admin/share-links
    /admin/speakers
    /admin/teams
    /admin/trash
    /admin/users
    /admin/video-feeds
    /admin/videos
    /admin/webhooks
    /books/[fileId]
    /calendar
    /categories/[slug]
    /directory
    /events
    /events/[slug]
    /favorites
    /forms
    /forms/[slug]
    /groups
    /groups/[slug]
    /guides
    /guides/[slug]
    /hymns/[fileId]
    /link
    /live
    /playlists
    /playlists/[id]
    /prayer
    /present/[fileId]
    /profile
    /profile/devices
    /profile/downloads
    /profile/events
    /profile/groups
    /profile/inbox
    /profile/rota
    /profile/settings
    /profile/shared-links
    /read/[fileId]
    /recently-added
    /recently-played
    /scripture
    /scripture/[book]
    /search
    /series/[slug]
    /services
    /services/[id]
    /share/unavailable
    /share/unlock/[token]
    /speakers
    /speakers/[slug]
    /subscriptions
    /tags/[tag]
    /tv
    /videos/[slug]
    /watch-later

C.2 — Routes (JSON, feeds, files, redirects)
Path 	Methods
/api/admin/access-attempts 	GET POST
/api/admin/analytics/export 	GET
/api/admin/announcements/[id] 	PATCH DELETE
/api/admin/announcements 	GET POST
/api/admin/api-keys/[id] 	DELETE
/api/admin/api-keys 	GET POST
/api/admin/assignments 	POST DELETE
/api/admin/audit/export 	GET
/api/admin/audit 	GET
/api/admin/authorized-emails/[id] 	PATCH DELETE
/api/admin/authorized-emails 	GET POST
/api/admin/branding 	GET PUT DELETE
/api/admin/broadcasts/[id] 	GET DELETE
/api/admin/broadcasts/[id]/send 	POST
/api/admin/broadcasts/[id]/test 	POST
/api/admin/broadcasts 	GET POST
/api/admin/bunny-audit 	GET
/api/admin/calendar-events/[id] 	GET PATCH DELETE
/api/admin/categories/[id] 	PATCH DELETE
/api/admin/categories 	GET POST
/api/admin/comments/[id] 	PATCH
/api/admin/comments 	GET
/api/admin/downloads 	GET PATCH
/api/admin/editors/category/[id] 	DELETE
/api/admin/editors/category 	POST
/api/admin/editors 	GET
/api/admin/editors/series/[id] 	DELETE
/api/admin/editors/series 	POST
/api/admin/events/[id]/registrations/[registrationId] 	DELETE
/api/admin/events/[id]/registrations 	GET
/api/admin/events/[id] 	PATCH DELETE
/api/admin/events 	GET POST
/api/admin/events/series/[id] 	PATCH DELETE
/api/admin/events/series 	GET POST
/api/admin/files/[id]/contents 	GET PUT
/api/admin/files/[id]/lyrics 	GET PUT
/api/admin/files/[id]/replace 	POST
/api/admin/files/[id] 	PATCH DELETE
/api/admin/files/[id]/text 	GET POST DELETE
/api/admin/files/bulk 	POST
/api/admin/files/bunny-storage 	GET
/api/admin/files/import 	POST
/api/admin/files 	GET POST
/api/admin/forms/[id]/fields/[fieldId] 	PATCH DELETE
/api/admin/forms/[id]/fields 	POST
/api/admin/forms/[id] 	GET PATCH DELETE
/api/admin/forms/[id]/submissions/[submissionId] 	PATCH DELETE
/api/admin/forms/[id]/submissions 	GET
/api/admin/forms 	GET POST
/api/admin/group-assignments/[id] 	DELETE
/api/admin/group-assignments 	GET POST
/api/admin/groups/[id]/members/[memberId] 	DELETE
/api/admin/groups/[id]/members 	POST
/api/admin/groups/[id] 	GET PATCH DELETE
/api/admin/groups 	GET POST
/api/admin/guest-login 	GET PATCH
/api/admin/guides/[id] 	GET PATCH DELETE
/api/admin/guides 	GET POST
/api/admin/home-rows/[id] 	PATCH DELETE
/api/admin/home-rows 	GET POST
/api/admin/live/[id] 	PATCH DELETE
/api/admin/live 	GET POST
/api/admin/people/[id] 	PATCH DELETE
/api/admin/people/merge 	POST
/api/admin/people 	GET POST
/api/admin/permission-groups/[id] 	PATCH DELETE
/api/admin/permission-groups 	GET POST
/api/admin/plugins/[slug]/overrides 	POST
/api/admin/plugins/[slug] 	PATCH
/api/admin/plugins/overrides/[id] 	DELETE
/api/admin/plugins 	GET
/api/admin/prayer/[id] 	PATCH DELETE
/api/admin/prayer 	GET
/api/admin/query-monitor 	PATCH
/api/admin/schedules/[id]/events 	GET POST
/api/admin/schedules/[id] 	GET PATCH DELETE
/api/admin/schedules/[id]/sync 	POST
/api/admin/schedules/[id]/validate 	POST
/api/admin/schedules/reorder 	POST
/api/admin/schedules 	GET POST
/api/admin/series/[id]/draft 	GET PUT DELETE
/api/admin/series/[id] 	GET PATCH DELETE
/api/admin/series/[id]/viewer-groups 	GET POST
/api/admin/series/[id]/viewers 	GET POST
/api/admin/series 	GET POST
/api/admin/series/viewer-groups/[id] 	DELETE
/api/admin/series/viewers/[id] 	DELETE
/api/admin/services/[id]/rota 	GET
/api/admin/services/[id] 	PATCH DELETE
/api/admin/services/report 	GET
/api/admin/services 	GET POST
/api/admin/share-links/[id] 	PATCH DELETE
/api/admin/share-links 	GET POST
/api/admin/sheets/tabs 	GET
/api/admin/speakers/[id] 	PATCH DELETE
/api/admin/speakers 	GET POST
/api/admin/teams/[id] 	PATCH DELETE
/api/admin/teams 	GET POST
/api/admin/trash/[type]/[id] 	POST DELETE
/api/admin/trash 	GET
/api/admin/users/[id] 	PATCH DELETE
/api/admin/users 	GET POST
/api/admin/video-feeds/[id] 	PATCH DELETE
/api/admin/video-feeds/[id]/sync 	POST
/api/admin/video-feeds 	GET POST
/api/admin/videos/[id]/captions 	GET POST DELETE
/api/admin/videos/[id]/chapters 	GET POST
/api/admin/videos/[id] 	PATCH DELETE
/api/admin/videos/[id]/sync-status 	POST
/api/admin/videos/[id]/thumbnail 	POST
/api/admin/videos/[id]/transcribe 	POST
/api/admin/videos/[id]/viewer-groups 	GET POST
/api/admin/videos/[id]/viewers 	GET POST
/api/admin/videos/bulk 	POST
/api/admin/videos/bunny-library 	GET
/api/admin/videos/chapters/[id] 	PATCH DELETE
/api/admin/videos/import 	POST
/api/admin/videos 	GET POST
/api/admin/videos/viewer-groups/[id] 	DELETE
/api/admin/videos/viewers/[id] 	DELETE
/api/admin/webhooks/[id] 	PATCH DELETE
/api/admin/webhooks 	GET POST
/api/auth/registration-check 	POST
/api/calendar-events 	GET
/api/calendar/[token]/marine-team.ics 	GET
/api/comments/[id]/report 	POST
/api/comments/[id] 	DELETE
/api/comments 	GET POST
/api/cron/broadcasts 	GET
/api/cron/extend-events 	GET
/api/cron/notification-digest 	GET
/api/cron/schedule-reminders 	GET
/api/cron/sync-schedules 	GET POST
/api/cron/sync-video-feeds 	GET
/api/cron/sync-video-status 	GET
/api/cron/transcribe 	GET
/api/downloads/[videoId] 	GET
/api/events/[slug]/register 	POST DELETE
/api/favorites 	POST
/api/files/[id]/content 	GET
/api/files/[id]/search 	GET
/api/forms/[slug] 	POST
/api/groups/[slug]/join 	POST DELETE
/api/groups/[slug]/meetings 	GET POST
/api/groups/[slug]/messages/[messageId] 	DELETE
/api/groups/[slug]/messages 	GET POST PATCH
/api/groups/[slug]/requests/[memberId] 	PATCH
/api/groups/[slug]/requests 	GET
/api/groups 	GET
/api/hymnals/search 	GET
/api/hymns/lookup 	POST
/api/inbox 	GET PATCH DELETE
/api/live/[id]/chat/[messageId] 	DELETE
/api/live/[id]/chat/mute 	POST
/api/live/[id]/chat 	GET POST
/api/locale 	POST
/api/manifest 	GET
/api/notes/[id] 	PATCH DELETE
/api/notes 	GET POST
/api/offline/hymnal/[seriesId] 	GET
/api/offline/service/[id] 	GET
/api/people 	GET
/api/playlists/[id]/items 	POST PATCH DELETE
/api/playlists/[id] 	GET PATCH DELETE
/api/playlists/for-video 	GET
/api/playlists 	GET POST
/api/prayer/[id]/pray 	POST
/api/prayer/[id] 	DELETE
/api/prayer 	GET POST
/api/profile/calendar 	POST DELETE
/api/profile/devices/[id] 	DELETE
/api/profile/devices 	GET
/api/profile/export 	GET
/api/profile 	PATCH DELETE
/api/push/subscribe 	POST
/api/push/unsubscribe 	POST
/api/ratings 	GET POST
/api/reactions 	GET POST
/api/reading/marks/[id] 	PATCH DELETE
/api/reading/marks 	GET POST
/api/reading/progress 	POST
/api/rota 	POST DELETE
/api/schedules/[id]/events 	GET
/api/schedules 	GET
/api/share-links/[id] 	PATCH DELETE
/api/share-links 	GET POST
/api/share-links/unlock 	POST
/api/subscriptions 	POST PATCH
/api/sync/snapshot 	GET
/api/tv/approve 	POST
/api/tv/feed.json 	GET
/api/tv/feed.xml 	GET
/api/tv/lookup 	POST
/api/tv/pair 	POST
/api/tv/poll 	POST
/api/v1/analytics 	GET
/api/v1/calendar-events 	GET
/api/v1/categories 	GET
/api/v1/events/[id]/registrations 	GET
/api/v1/events 	GET
/api/v1/files 	GET
/api/v1/groups 	GET
/api/v1/me 	GET
/api/v1 	GET
/api/v1/schedules 	GET
/api/v1/series 	GET
/api/v1/videos 	GET
/api/videos/outline 	PUT
/api/view-events 	POST
/api/watch-later 	POST
/api/watch-progress/mark-watched 	POST
/api/watch-progress 	POST
/auth/guest 	GET
/events/[slug]/event.ics 	GET
/events/calendar.ics 	GET
/feed.xml 	GET
/s/[token] 	GET
/series/[slug]/podcast.xml 	GET
Appendix D — Rules pinned by tests

The describe and it titles of every file in the original test suite, in source order and nesting. Each is a case the port's PHPUnit suite must have under the same name. Where a title alone is ambiguous, the matching section of Appendix A says what the behaviour is.
lib/active-path.test.ts

    isActivePath
        matches a section and everything under it
        stops at a segment boundary
        only lights Home up on Home
        keeps an overview from staying lit on the pages under it

lib/admin-nav.test.ts

    adminGroupsFor
        gives an admin every section
        gives a staff member with no grants only what every staff member gets
        keeps the overview and categories admin-only, since /admin bounces everyone else
        drops a group whose every link is hidden, so no heading stands over nothing
        reveals sections one capability at a time
        lists no href twice
    currentAdminLabel
        names the open section
        stays on the section while inside it
        prefers the longest match, so a sub-section doesn't resolve to the overview
        falls back rather than showing nothing for a page not in the nav

lib/api-keys.test.ts

    newApiKey
        is recognisable and long
        is different every time
        is url-safe, so it survives a config file and a curl
    hashApiKey
        is stable and one-way
        differs for two keys that share a prefix
    keyPrefix
        keeps enough to tell two keys apart and no more
    sameHash
        compares equal hashes and rejects different ones
        does not throw on different lengths
    bearerFrom
        reads a well-formed header
        refuses anything that isn't one of ours before it reaches the database
    scopes
        knows its own and nothing else
        drops rubbish and duplicates when a key is made
        never lets one scope imply another
        is described for every scope, and marks the personal ones
        grants nothing at all by default
    keyState
        is ok for a live key
        is expired the moment it expires, not after
        says revoked ahead of expired
    pageSize
        clamps to something a database can serve
        answers a guess with the default rather than an error

lib/api-v1.test.ts

    ok
        wraps rows in one envelope
        never lets an answer be cached by something shared
        leaves nextCursor out on the last page rather than sending null
        refuses to answer with a credential in it
    fail
        has one shape with a code a program can branch on
        sends Retry-After only when there is something to wait for
    paging
        asks for one row more than the page, to know whether there is another
        skips the cursor row itself, so a page never repeats its predecessor's last row
        hands back the page and the cursor when there is more
        says there is no more when the extra row didn't come back
        reads limit and cursor off the query string
    updatedSince
        reads a timestamp
        ignores one it can't read rather than refusing the request

lib/attendance.test.ts

    canKeepRoll
        is the group's leaders and whoever keeps the group list
        is not an ordinary member
    visibleAttendance
        gives a leader the roll
        gives a member their own row and nothing else
        gives somebody outside the group nothing at all
        never lets a member infer the size of the room
    summariseRoll
        counts each answer, and everybody who was in the room
        keeps apologies out of both present and absent
        refuses a negative visitor count rather than subtracting from the room
    attendanceRate
        counts the last few meetings, most recent first
        ignores a week the group didn't meet, at both ends
        counts a member with no row at all as missed
        does not count apologies as coming
        honours the window
    quietlyMissing
        names somebody who has missed the last three without a word
        takes somebody off the list the moment they send apologies
        keeps somebody whose apologies were weeks ago and has said nothing since
        says nothing at all until there are enough meetings to judge
        skips cancelled meetings when counting the run
    canRecordFor
        allows the night itself and anything before it
        refuses a meeting that hasn't happened

lib/authorization.test.ts

    normalizeEmail
        lowercases and trims, so casing and stray spaces can't make a second identity
    isValidEmail
        accepts ordinary addresses
        rejects malformed input
        rejects anything that could be used for header, log, or SQL-ish injection
    isOrganizationMember
        accepts only the configured organization
        refuses a missing claim — a personal account carries no org_id
        fails closed when the organization isn't configured, rather than passing everyone
        accepts any organization in a comma-separated list, and rejects one that isn't listed
        tolerates whitespace around each id in the list
        fails closed on a whitespace- or comma-only value, not just an unset one
    allowedOrganizationIds
        parses a single value the same as before — the existing single-org deployment shape
        parses a comma-separated list, trimmed and with empties dropped
        is empty when unset
    isEmailAuthorized
        passes an ACTIVE row
        looks the address up normalized, whatever casing or spacing was given
        refuses a SUSPENDED row
        refuses an address with no row
        refuses empty or malformed input without querying at all
        adopts an ADMIN_EMAILS address with no row, creating a visible entry for it
        does not revive an ADMIN_EMAILS address an administrator suspended
    authorizationMode
        defaults to requiring both checks
        accepts the four modes, case-insensitively and untrimmed
        falls back to BOTH for anything unrecognised, rather than to something permissive
    isAuthorized — every mode against every combination
        ${label}: BOTH=${both} ORGANIZATION=${orgMode} ALLOWLIST=${allowlistMode} EITHER=${eitherMode}
        never lets any mode admit someone who failed every check
        EITHER is the one mode where org-only and email-only both admit — that's the whole point of it
    denialReasonFor
        names whichever halves failed, under BOTH
        only blames checks the mode actually enforces
        under EITHER, a denial always means both failed — isAuthorized only calls this once neither passed
    authorizeIdentity in a single-check mode
        ORGANIZATION: an org member gets in with no allowlist row
        ORGANIZATION: a non-member is still refused even when allowlisted
        ALLOWLIST: an allowlisted address gets in with no organization claim
        ALLOWLIST: an org member with no allowlist row is still refused
        ALLOWLIST: suspension still revokes
        an unrecognised mode is treated as BOTH, not as a bypass
        EITHER: a personal account (no org_id) gets in on an allowlist entry alone
        EITHER: an organization member gets in with no allowlist row
        EITHER: someone with neither is still refused
    authorizeIdentity — the whole truth table
        DENY: not in the organization, not on the allowlist
        DENY: not in the organization, but on the allowlist
        DENY: in the organization, but not on the allowlist
        ALLOW: in the organization and on the allowlist
        DENY: a different organization's id can't stand in for ours
        ALLOW is unaffected by casing or whitespace in the email
        DENY: suspending the allowlist entry revokes an existing organization member
        evaluates both halves even when the first fails, so the record says which
    authorizeIdentity — per-address organization exemption
        an exempt, ACTIVE address gets in under BOTH mode with no organization at all
        a non-exempt address still needs both checks under BOTH mode
        an exempt but SUSPENDED address is still refused — exemption isn't a bypass of status
        exemption is a no-op for someone who's also an organization member — they'd have gotten in anyway
        a bootstrap-adopted ADMIN_EMAILS address is never exempt
    isGuestLoginEnabled / setGuestLoginEnabled
        defaults closed on a never-touched deployment
        reflects true once an admin has opened it
        setGuestLoginEnabled writes both create and update with the same value

lib/book-contents.test.ts

    parseContentsText
        reads a contents page as it is printed: number, title, page
        leaves the hymn number on the label, where hymnNumberOf finds it
        takes a tab or a pipe as the separator, so a spreadsheet paste works
        keeps a title that ends in a number apart from the page
        converts the printed page a person types into the PDF page stored
        takes pdf:N as the PDF page itself, for front matter with no printed number
        skips blank lines without counting them as entries
        reports a line with no page rather than dropping the hymn
        points at the line the typist sees, counting blanks
        refuses a page in front of the book's own page 1 instead of storing a guess
        refuses a page with nothing to call it
        reads indentation as nesting, so a heading keeps the hymns under it
    formatContentsText
        round-trips what was parsed, offset, nesting and all
        writes front matter as a PDF page, having no printed one to write
        keeps an entry indexed from bookmarks readable when it has no depth of its own

lib/branding.test.ts

    isHexColor
        accepts three- and six-digit hex, in either case
        rejects anything that isn't one
    normalizeHex
        expands the short form and lowercases
    hexToRgbChannels
        splits a colour into channels for an rgba() literal
    normalizeBranding
        falls back to the defaults for junk
        keeps the fields it recognizes when others are bad
        drops a colour that isn't hex rather than letting it reach the stylesheet
        treats a blank or whitespace-only name as absent
        caps a name rather than letting it break the header
        accepts a same-origin path or an https logo, and nothing else
    brandingCss
        writes the chosen colours into both themes
        normalizes before interpolating, so a bad value can't reach the stylesheet

lib/broadcast.test.ts

    planDelivery
        reaches somebody on every channel they're set up for
        won't text somebody who never agreed to be texted
        emails by default, and stops for somebody who turned announcements off
        skips a number it can't make sense of rather than sending it anyway
        can't push to somebody with no account or no device
        still emails somebody with no account — an event's sign-ups are full of them
        writes to somebody once even when they're in the audience twice
        dedupes account-less people by address, since they have no id to match on
        counts somebody reached if any one channel works
        counts somebody missed when nothing works — the number that matters
    summariseSkips
        says why, commonest first
        is empty when everybody is reachable
    audienceLabel
        still reads sensibly after the group it named is gone
    progressOf
        counts anything not still pending as done
        is finished only when nothing is pending, failures included

lib/bunny.test.ts

    parseBunnyResolutions
        returns nothing for an empty or missing string
        parses a comma list, highest first, regardless of Bunny's order
        drops resolutions we don't have an MP4 constant for
        de-duplicates
    selectMp4Height
        picks the highest available at or under the default 720p cap
        steps down when the cap isn't available — the 480p-only case
        respects a configured maximum lower than what's available
        returns null when nothing available is at or under the cap
        returns null when there's nothing to choose from
    downloadHeight
        defaults to 720 when unset or invalid
        honors a valid configured height
    bunnyStreamMp4Url
        builds the correct Bunny MP4 fallback path for the selected height
        never defaults to 720p — height is required
        throws rather than returning a broken URL when the hostname isn't configured
    probeBunnyMp4
        classifies 403/401 as forbidden, not missing
        classifies 404/410 as missing
        classifies a 200/206 as ok
        classifies a network failure or 5xx as a generic error, not missing
        treats an empty URL as an error rather than fetching

lib/client-bundle.test.ts

    what ends up in the browser bundle
        finds the client components to check

lib/content-language.test.ts

    languageOf
        takes the item's own tag
        inherits the series' — a Spanish series' episodes are Spanish
        lets an episode override its series
        treats unlabelled as the site's default, not as 'any'
        ignores a tag for a language the site doesn't speak
    shouldLabelLanguage
        marks the odd one out and stays quiet about the rest

lib/content.test.ts

    canAccess
        allows anyone to a non-member-only item
        requires login for a member-only item
    categoryChainIds
        maps the recursive query's rows to a flat id list, root-most last
        returns just the category itself when it has no parent
    getSequentialLockedVideoIds
        locks nothing for an anonymous viewer, without querying progress
        locks nothing when the series doesn't require sequential viewing
        locks every video after the first one not yet completed
        locks everything past the first video when nothing is completed
        sorts by position before deriving locks, regardless of input order

lib/cover.test.ts

    canAskForCover
        lets whoever is on ask
        lets somebody who hasn't answered yet ask
        refuses somebody else's slot
        has nothing to cover once they've said no
        refuses a second ask on the same slot
        refuses a service that has been and gone
        counts the whole of the service's own day as not yet past
        treats an undated plan as still to come
        refuses a plan nobody can see yet
    coverState
        is open to somebody else on the team
        is not open when nobody asked
        says nothing to do about your own slot
        refuses somebody already on that service
        warns, but does not refuse, somebody who said they were away
        refuses a service that has already happened
        puts being already on ahead of being away
    takeMessage
        says something for every state, and nothing when it is simply open
    askerName
        prefers the name they chose
        never falls back to an email address

lib/cron-guard.test.ts

    cronVerdict
        lets the right bearer token through
        refuses a wrong token, a missing header, and a token of another length
        fails closed in production when no secret is configured
        stays open in development when no secret is configured
        still checks a secret that is set, in development too

lib/cron.test.ts

    the scheduled jobs
        has crons to check
        doesn't fire two jobs at the same minute

lib/cross-site.test.ts

    isCrossSiteWrite
        refuses a write to the API that the browser labels cross-site
        lets same-origin, same-site and typed-in requests through
        lets a caller with no such header through — servers and televisions
        never touches a read, wherever it came from
        covers only the API, not pages or the SDK's auth routes

lib/data-export.test.ts

    unsafeKeysIn
        finds a credential nested inside an array
        finds one buried several objects deep
        matches the whole key, not a prefix of it
        reports every offender, not just the first
        is quiet on a document that only holds facts
        covers every key it claims to
    assertExportSafe
        throws, naming the path, rather than quietly stripping it
        passes a clean document
    exportFilename
        names the file after the member and the day
        keeps the name safe for a Content-Disposition header
        falls back when there is nothing nameable in the address
    pushServiceOf
        keeps the service and drops the part that identifies the browser
        says unknown rather than passing an unparseable endpoint through whole
    totalRecords
        counts nested lists, not just top-level ones
        is zero for a document holding no lists
    the queries behind the export
        scopes every read to one member
        names every column it selects
        ignores prose, not code
        finds the calls it is checking

lib/device-settings.test.ts

    parseDeviceSettings
        falls back to the defaults for missing or unparseable storage
        reads a full, valid settings object back
        keeps the fields it recognizes when others are missing
        rejects a theme or language it doesn't know, per field
        rejects a playback speed that isn't one we offer
        rejects non-boolean flags rather than coercing them
        holds the screen on unless it was deliberately turned off
        pulls a present-mode text size back into range rather than resetting it
        does the same for the reading text size, which has its own range
        keeps the reading size and the present size apart
        treats a bottom bar that was never customised as unset
        keeps page swiping on unless it was deliberately turned off
    THEME_INIT_SCRIPT
        reads the same storage key parseDeviceSettings writes
        stamps one of the two classes the stylesheet keys off
        swallows its own errors, so a blocked localStorage can't halt the page

lib/directory.test.ts

    listed
        needs them to have asked
        drops somebody whose access was withdrawn
        drops somebody with no name to show
    directoryName
        prefers the name they chose
        never falls back to an email address
    presentMember
        publishes a name and nothing else by default
        treats each contact detail as its own separate yes
        leaves the field off rather than sending an empty one
        carries no other account field out with it
    visibleDirectory
        shows only those who asked and still have access, by name
        sorts without caring about case
    searchDirectory
        matches a name and a note
        never matches a contact detail, even a published one
        returns everybody for an empty query
    directoryStanding
        says plainly where somebody stands

lib/download-source.test.ts

    resolveMp4Source
        Test 1 — available up to and including the configured cap picks 720p
        Test 2 — only lower resolutions available picks 480p, not 720p
        Test 3 — hasMp4Fallback false returns mp4_unavailable without touching Bunny again
        Test 4 — Bunny/CDN 403 on the resolved URL returns mp4_forbidden, not mp4_unavailable
        Test 5 — Bunny/CDN 404 on the resolved URL returns mp4_missing
        returns resolution_unavailable when Bunny's resolutions are all above the configured cap
        fetches and caches metadata when the row has never been synced (hasMp4Fallback: null)
        does not re-fetch Bunny on every request once a video is cached as having no fallback
        reports bunny_error, not mp4_unavailable, when the metadata fetch itself fails
        a network error on the diagnostic probe does not block a download Bunny's API confirmed

lib/downloads.test.ts

    resolveDownloadEnabled
        allows by default when nothing anywhere has an opinion
        lets the video override its series and category
        falls to the series when the video is inheriting
        uses the nearest category with an opinion, not the root
        skips inheriting categories to reach an ancestor that decided
        treats an explicit false as a block, not as absent
    isPlatformAllowed
        allows everywhere on BOTH
        restricts to the installed app on PWA
        restricts to the browser on WEB
    isAudienceAllowed
        allows any member when the audience is everyone
        refuses a member outside the lists when the audience is specific
        allows a named individual
        allows a member of a listed group
        refuses a member whose groups aren't listed
        always allows an admin, whatever the lists say

lib/event-series.test.ts

    planOccurrences
        keeps the wall clock the same on both sides of a clock change
        ends each occurrence its own length later
        leaves the finish open when no length is given
        starts an all-day occurrence at local midnight, whatever the stored time says
        moves the sign-up window with each date rather than copying one pair of instants
        closes at the end of the event's own day when told zero days before
        leaves the window open at both ends when neither is set
    datesToCreate
        skips what already exists
        does not put back a date somebody took out
        is a no-op when everything is already there
    stopSeriesPlan
        never deletes an occurrence somebody signed up for
        counts today as still to come
        keeps the past even when nobody came
    seriesProblems
        passes a series that is fit to save
        catches a rule it cannot expand
        catches a time zone that doesn't exist
        wants a time unless it is all day
        catches a sign-up window that shuts before it opens
        reports everything wrong at once, not just the first
    describeSeries
        says it the way somebody would
        says something rather than throwing on a rule that can't be read
    horizonEnd
        looks half a year ahead
    ruleFromChoices
        falls back to the day the first date lands on
        orders the days it was given, whatever order they were clicked in
        reads the monthly shapes off the first date
        carries the interval and the ending
        only ever builds rules the parser accepts
    monthPositionOf
        knows which weekday of the month a date is

lib/events.test.ts

    seatsTaken
        counts a guest as a place, because a guest sits somewhere
        ignores the waiting list and the cancelled
        is zero for nobody
    placesLeft
        never goes negative, even when an organiser lowers the capacity below what is booked
        is null for an event with no limit
    registrationState
        offers sign-up when there is room and the window is open
        says nothing about sign-up for an event that doesn't take it
        leads with the event being over, not with sign-up having closed
        counts an event as over only once it has finished, not once it has started
        distinguishes not open yet from closed
        offers the waiting list when full, and refuses outright when there isn't one
        stays open for an event with no capacity however many have signed up
    registrationMessage
        counts places down and gets the singular right
        says nothing about a number an unlimited event doesn't have
    promotable
        moves whoever fits, in the order they joined
        stops at the first party that doesn't fit rather than skipping over them
        moves the whole queue when there is no limit at all
        moves nobody when nothing freed up
    eventWhen
        gives a day and a time
        gives one day for an all-day event rather than a midnight time
        runs two dates together for something spanning days

lib/filename.test.ts

    titleFromFilename
        drops the extension
        drops only the last extension
        keeps a name that has no extension
        treats a leading dot as part of the name, not a separator
        strips a directory path if one comes through
        leaves separators and capitalisation alone
        handles empty and whitespace input without producing junk

lib/forms.test.ts

    optionsOf
        reads one option per line and ignores the blank ones
        is empty for a field that offers no choices
    validateSubmission
        keeps what was given and trims it
        asks again for a required field left blank
        leaves an optional blank out rather than storing an empty answer
        checks an email, a number and a date
        refuses an answer to a choice question that was never offered
        drops uninvited options out of a multi-choice rather than storing them
        treats a lone unticked checkbox as an answer, not as silence
        won't let a required checkbox through unticked
        ignores anything sent for a field the form doesn't ask
        requires at least one box of a required multi-choice
    columnsFor
        keeps a retired question as a column, after the live ones
    submissionRow
        lays the answers out under their labels

lib/group-messages.test.ts

    inTheThread
        is people actually in the group
        is not people whose ask is unanswered, or who were turned down
    canModerate
        is this group's leaders
        is not a member
        is not a site manager who isn't in the group
        is a site manager who is in the group, because they lead by capability
    threadState
        names why somebody can't see it
        has a sentence for every state but open
    visibleThread
        gives members the messages, without the hidden one
        gives somebody outside the group nothing at all
        keeps a hidden message hidden from the leader who hid it too
        lets an author remove their own and nobody else's
        lets a leader remove anything
        carries no user ids out
    canRemoveMessage
        is the author, or a leader of the group
        is not another member
        is not the author once they've left the group
        is not a site manager outside the group
    cleanGroupMessage
        collapses the runs that turn one message into a screenful
        refuses an empty one
        takes a paragraph, which the stream chat wouldn't
        stops at the limit and says what it is
    notifiable
        is active members other than the author, minus the muted
        never tells somebody about their own message
        doesn't reach people who aren't in the group yet
    latest
        returns the newest page, in reading order
        copes with fewer messages than a page
        doesn't mutate what it was given
        breaks a tie on id so the order is stable

lib/groups.test.ts

    standingIn
        tells the four ways somebody can stand to a group
        remembers somebody who was turned down
    canSeeAddress
        gives it to people who are actually in the group
        withholds it from a visitor and from a stranger with an account
        withholds it from somebody who has only asked to join
        withholds it from somebody who was turned down
    presentGroup
        leaves the address out entirely rather than sending null
        still gives the district, which is what somebody is choosing between
        gives the address to a member, a leader and whoever keeps the list
        doesn't hand the address to somebody who has only asked
        names the leaders, because somebody has to be asked
        counts only people actually in it
    activeMembers
        leaves out requests and refusals
    joinState
        offers to a signed-in stranger
        asks a visitor to sign in rather than pretending they can join
        says so when the answer is already yes, or already asked
        says full rather than hiding a full group
        treats a place already offered to somebody as taken
        respects a group that has closed its doors even with room
        has a sentence for every state
    canLead
        is the group's own leader, and whoever keeps the list
    the waiting list
        counts an unanswered request as holding a place
        orders by when they asked, not by when the rows come back
        offers exactly as many places as there are
        takes everybody when the group has no stated size
        ignores anybody who isn't waiting
        still keeps the address from somebody who is only waiting
        tells somebody on the list where they stand
        says closed rather than full when the group isn't taking anybody

lib/guides.test.ts

    presentGuide
        gives a member the questions, the scripture and the notes
        never lets a leader note reach a member, in any field
        leaves the field off entirely rather than sending an empty one
        gives the notes to whoever is leading
        gives them to whoever keeps the group list
        withholds them from somebody who only asked to join, or is waiting
        withholds them from a signed-out reader
        omits the field when a leader opens a guide that has no notes
        reads in the order it was written, whatever order the rows arrive in
    isMemberKind
        names exactly the three anybody may read
    canSeeLeaderNotes
        is leading this group, or keeping the group list
    canOpenGuide
        lets anybody open a published guide
        keeps a draft to staff
    describeGuide
        counts questions, not items
        calls a guide with no questions a handout

lib/hymnal.test.ts

    hymnReadingOrder
        puts the book in printed page order
        keeps hymns with no printed number at the end, in the order they came in
        leaves two hymns printed on the same page in their given order
        doesn't disturb the array it was given
    fingerprintHymns
        is the same for the same book
        changes when a hymn's words are corrected
        changes when a hymn is added, renumbered, retitled or reordered
        counts the hymns in the token, so a hash collision still can't read as unchanged
        is the same function it has always been
    fileHref
        sends a hymn in a hymn-per-file book to its lyrics page
        sends a book to its contents
        has nowhere to send a file that isn't either

lib/i18n/i18n.test.ts

    the catalogues
        has more than one language to check
        names every language in its own language
    format
        fills a placeholder in
        leaves an unknown one standing rather than writing 'undefined'
        fills every occurrence
    pickLocale
        takes a language this app speaks
        matches a regional variant to its base language
        honours the quality weights rather than the order
        falls back to English for a language it doesn't speak
        falls back for a missing or unreadable header
        still honours a language whose weight is malformed
        ignores a language the browser explicitly refused
    the language list in device settings
        offers exactly the languages there are catalogues for
        labels each one the way the catalogue does

lib/ics.test.ts

    escapeText
        escapes the four characters the format reserves
        leaves a colon alone, so a URL in a description survives
        escapes the backslash first, so an escape isn't escaped twice
    foldLine
        leaves a short line alone
        folds at 75 octets, continuing with a space
        never cuts a character in half
    stamps
        writes an instant as UTC with no punctuation
        writes a day as eight digits
    icsCalendar
        writes a calendar a client will accept
        ends every line with CRLF, which the format requires
        ends an all-day event on the following day, because DTEND is exclusive
        keeps an explicit all-day finish
        leaves the finish out when there isn't one
        marks a cancellation rather than dropping it
        writes a URL unescaped
        escapes a description that would otherwise break the file
        offers a refresh interval to anything subscribing
        balances BEGIN and END for every event
    icsFilename
        makes a name a browser will save
        drops anything that could break the header it sits in

lib/identity-linking.test.ts

    decideLinking
        uses the sub's own user when the identity is already known
        prefers sub over email, so a provider-side email change is a rename not a new account
        still trusts a known sub even when its email is unverified
        links a new identity to an existing member when the email is verified
        refuses to link a new identity whose email isn't verified
        creates a member for a genuinely new identity
        creates for an unverified identity when nothing collides with it

lib/live-chat.test.ts

    chatState
        is open during the stream
        opens half an hour early, so people arriving can say hello
        stays open an hour after, so the conversation isn't cut off mid-sentence
        closes, rather than standing open on last year's carol service
        gives a stream with no end time a sensible one rather than for ever
        is off when the chat was never switched on
    cleanMessage
        keeps what somebody wrote
        collapses the shouting a length limit doesn't stop
        refuses nothing at all
        refuses an essay
    waitSeconds
        is nothing when slow mode is off
        is nothing for somebody who hasn't written yet
        counts down from their own last message, not the chat's
        is nothing once the wait has passed
    visibleMessages
        never delivers a hidden message, even to a poll that was behind
        carries no account id out to anybody
        says who may take a message down

lib/names.test.ts

    normalizeName
        folds the case and whitespace variants onto one key
        collapses internal whitespace
        folds accents so Jose and José match
        normalizes typographic apostrophes and hyphens
        returns an empty string for input with nothing usable
        keeps genuinely different people apart
    toDisplayName
        preserves a deliberate mixed-case spelling
        title-cases spreadsheet artefacts
        capitalizes after hyphens and apostrophes
        trims and collapses whitespace
        returns an empty string for empty input
    splitNames
        splits on the common separators
        handles a mix of separators
        drops blank fragments
        deduplicates on the normalized form
        respects a custom separator list
        does not split a name that merely contains the letters and
    isPlausibleName

lib/nav-tabs.test.ts

    parseTabHrefs
        reads a stored choice back
        tells no choice from an empty one
        drops entries that aren't hrefs, and repeats
        keeps more than fit across the screen — those scroll — but not without limit
    resolveTabs
        uses the app's suggestion when nothing was chosen
        keeps the chosen order, not the catalogue's
        carries the whole item through, badge included
        drops a destination this viewer no longer has
        falls back rather than leaving an installed app with no navigation
    toSnapshot
        keeps only what the offline shell can draw

lib/offline-calendar.test.ts

    mergeSnapshot
        replaces everything when the server says the snapshot is full
        takes the delta whole when the device holds nothing yet
        updates an event in place rather than duplicating it
        drops what the server reports as deleted
        drops a withdrawn schedule's events, which the server never lists
        prunes days that have fallen out behind the window
        keeps a multi-day event until the day it ends
        prunes days beyond the far edge too
        sorts a new schedule into its place rather than appending it
        carries the server's timestamp forward, so the next sync asks from there
        leaves the device's copy untouched

lib/offline-shell.test.ts

    the offline shell's copied constants
        opens the caches the app writes
        reads the storage keys the app writes
        stores the chosen name under the key the app reads
        clamps the reading size to the same range the app does
        answers for the paths saved media is stored under
        loads the reader libraries from where they are saved
    the offline shell's copied icon set
        can draw every icon a tab may carry
    the offline shell's copy of hymnNumberOf
        reads a number exactly as the app does
    the offline shell's copy of relativeDay
        names a day exactly as the app does

lib/outline.test.ts

    parseOutline
        splits a line into what is printed and what is filled in
        numbers the gaps across the whole sheet, not per line
        keeps blank lines, so the sheet has the shape it was typed in
        doesn't treat two underscores as a gap
        handles a gap at the very start and the very end
        reads Windows line endings as lines, not as text
    fingerprintOutline
        changes when a gap is added, which is when answers stop lining up
        is the same for the same outline, whatever its line endings
    outlineToText
        puts the answers back into the sentence
        leaves a gap that was never filled in

lib/page-offset.test.ts

    printedPage
        is the PDF page itself when a book has no front matter
        subtracts the front matter, so a ten-page contents puts printed 1 on PDF 11
        returns null inside the front matter rather than a zero or negative page
        handles a negative offset, for a scan that starts partway into a book
    pdfPageOf
        is the printed page itself when a book has no front matter
        adds the front matter back on
        leaves an out-of-range value alone for the reader to clamp
        inverts printedPage, so a round trip through the page box stays put

lib/permissions.test.ts

    hasCapability
        always passes for an admin, without querying assignments
        fails when the user has no matching assignment
        passes on a site-wide assignment (no category or series), even with no scope given
        fails a scoped-only assignment when no scope is given
        passes when the assignment's seriesId exactly matches the requested scope
        passes when the assignment's category is an ancestor of the scoped category
        fails when the assignment's category is outside the scoped category's chain
    descendantCategoryIds
        returns an empty list for no roots, without querying
        returns just the root when it has no children
        includes every descendant regardless of row order
        doesn't pull in a sibling subtree outside the given roots

lib/plugins.test.ts

    getPluginStates
        uses the site-wide value with no category context
        fails open for a plugin with no seeded row
        applies a direct override on the given category
        walks up to an ancestor's override when the category has none of its own
        prefers the nearest override over a more distant ancestor's
        falls back to the site-wide value when no override matches the chain

lib/podcast-mirror.test.ts

    isMirrorEligible
        accepts a published, public audio file in a public series
        requires the admin to have opted in
        refuses a members-only file
        refuses a file whose series is members-only
        refuses when the series is unpublished, hidden or trashed
        refuses when the file itself is unpublished, hidden or trashed
        respects a publish schedule that hasn't started
        respects an expiry that has passed
        only mirrors audio
        refuses a file with no series, which has no feed to appear in
    publicPathFor
        namespaces by file id so the zone's contents are self-describing
        falls back to the id when the source path has no filename

lib/prayer.test.ts

    canSee
        shows nothing to anybody until it has been let through
        still shows a waiting request to whoever wrote it
        shows the queue to a moderator, which is the job
        keeps a taken-down request away from everybody else
        honours the three audiences
        shows an answered request wherever an approved one would show
        doesn't hand a visitor somebody else's request because both have no account
    bylineFor
        gives the name when there is one to give
        never gives a name for an anonymous request
        doesn't leak a blank as a name
    presentPrayer
        carries no account id out, for anyone
        withholds the name even from the moderator's own list
        says whose it is to act on
    visibleTo
        drops what a member may not see, without them knowing it was there
        counts prayers and knows whether this reader is one of them
        gives a moderator the lot
    canPrayFor
        needs an account, because pressing it twice mustn't be two
        won't count a prayer for something nobody has been shown
    canDelete
        is the writer's, and the moderator's

lib/public-url.test.ts

    isPublicHttpUrl
        accepts ordinary public endpoints
        refuses loopback, private and link-local addresses
        refuses names that only mean something inside a network
        does not block a public range that merely neighbours a private one
        refuses other schemes, credentials, and non-URLs

lib/push-endpoint.test.ts

    isPushServiceEndpoint
        accepts the endpoints real browsers hand out
        refuses anything else — including hosts that merely contain a service's name
        refuses plain http even to a real service, and credentials in the URL
        refuses what isn't a URL at all
        lets a deployment add a host suffix by environment
    subscriptionsToEvict
        evicts nothing while there is room for one more
        evicts the oldest to leave room for exactly one more
        evicts as many as it takes when a member is already far over

lib/reader-cache.test.ts

    bookCacheTag
        changes when the file's bytes do
        has a value for a file whose size was never recorded
    parseCachedToc
        reads back what was written
        keeps an empty contents list, which is an answer like any other
        misses when the file has been replaced
        misses once the entry is too old, in either direction
        refuses anything it can't trust rather than rendering it
        refuses a list with an entry of the wrong shape

lib/reader.test.ts

    readerFormat
        recognizes the correct mime types
        falls back to the extension when the mime type is missing or wrong
        ignores a query string or fragment on the path
        returns null for anything it can't open
        trusts a correct mime type over a misleading extension
    clampPercent
        holds the value inside 0-100 as a whole number
        treats non-finite input as zero rather than writing NaN to the database
    toSpeechChunks
        splits on sentence endings, keeping the punctuation
        collapses whitespace, which extracted PDF text is full of
        returns nothing for empty or whitespace-only text
        breaks a runaway sentence on a space rather than emitting one huge utterance
        keeps a sentence with no terminal punctuation
    findMatches
        finds every case-insensitive occurrence
        finds overlapping matches
        returns nothing for an empty or whitespace query
        returns nothing when there's no hit
    contentDispositionFilename
        takes the extension from the path, not the title
        doesn't double up an extension the title already has
        strips CR/LF so an admin-entered title can't inject a header
        strips quotes and backslashes that would end the quoted string early
        keeps non-ASCII in filename* while falling back to ASCII in filename
        falls back to a usable name when the title is blank
        ignores a junk extension rather than appending it
    excerptAround
        ellipsizes only the ends it actually trimmed
        adds no ellipsis when the whole string already fits
    shouldFetchWholeBook
        fetches an ordinary book in one cacheable request
        leaves a very large scan streaming, so its first page still opens quickly
        treats an unrecorded size as too big rather than guessing
    etagMatches
        matches the tag the client already holds
        compares weakly, so a validator marked weak still counts as the same bytes
        accepts any tag in a list, and the wildcard
        is false when either side has nothing to compare
    isCompatibleReplacement
        allows a re-scan of the same kind of book
        refuses swapping one reader's format for the other's
        refuses turning a book into something no reader opens, and the reverse
        leaves files with no reader positions interchangeable

lib/recurrence.test.ts

    parseRule
        reads the parts a church diary uses
        accepts the RRULE: prefix an .ics line carries
        reads a numbered weekday
        drops the time of day from UNTIL, which is a day here
        refuses a part it cannot compute rather than ignoring it
        refuses the combinations that don't mean what they look like
        refuses rubbish
    formatRule
        round-trips every rule this app writes
    occurrencesBetween
        always counts the start, even when the rule wouldn't have picked it
        keeps the rest of the starting week
        counts intervals in whole weeks from the starting week
        does the first Sunday of the month
        does the last Saturday, whether the month has four or five
        skips a month too short for the day, rather than sliding to the 28th
        does the last day of every month
        does a yearly date, including one that only exists in leap years
        stops at COUNT, counting from the start and not from the window
        stops at UNTIL, inclusive
        answers only the window asked for
        gives nothing for a window that ends before it starts
        caps a rule that would otherwise answer with a decade of days
        gives up on a rule that can never land again
    describeRule
        says what a rule means, in words somebody can check
        gets the ordinal suffixes right
    zonedInstant
        keeps the wall clock across a daylight-saving change
        works west of Greenwich too
        moves an hour that never happens forward, not backward
        takes the first of an hour that happens twice
        handles a zone whose offset is not a whole hour
        treats a zone it doesn't know as UTC rather than throwing
        refuses a time of day it can't read
    dayInZone
        gives the local day, which need not be the UTC one
    isKnownTimeZone
        knows a real zone from a typo

lib/reorder.test.ts

    reorderArray
        moves an item later in the list, shifting items between
        moves an item earlier in the list, shifting items between
        returns the same array reference when the target index is unchanged
        clamps a target index past the end of the list
        clamps a negative target index to the start of the list
        does not mutate the input array

lib/rota.test.ts

    isBlockedOut
        covers both ends of the range, because that is how people say it
        doesn't cover the days either side
        says nothing about a service with no date
        is false for somebody with no blockouts
    assignmentRole
        uses the job where one was written down
        falls back to the team, so a row is never nameless
    personName
        prefers the name somebody chose for themselves

lib/schedules/duplicates.test.ts

    possibleDuplicates
        pairs a name with a longer one that starts the same way
        ignores casing and spacing, which are what made the duplicate
        doesn't pair two different short names
        won't pair on a prefix shorter than three characters
        won't pair names that diverge by more than a few characters
        stops at the limit rather than listing every pair in a large church
        says nothing about a list with no near-duplicates

lib/schedules/logic.test.ts

    involvesPerson
        matches on the person id, not the name
    filterEvents
        returns everything when no filter is given
        filters by person
        filters by schedule
        treats an empty schedule list as 'all'
        combines person and schedule filters
        can include cancelled events
    sortEvents
        sorts by date, then time, with all-day first
        is stable and deterministic for identical dates
    eventsOnDay
        returns only that day
        scopes to a person
        returns an empty array when nothing matches
        includes a multi-day event that spans the day
    coversDay
        matches the start date
        matches any day inside a span, inclusive
    upcomingEvents
        excludes today by default, since Today has its own section
        can include today
        never includes past events
        respects the horizon
        respects the limit
        filters by person
        returns an empty array when a person has nothing coming up
        keeps an in-progress multi-day event when today is included
    pastEvents
        returns past events most recent first
        does not treat today as past
        filters by person
    nextEventForPerson
        returns the soonest event, including today
        returns null when there is nothing
    groupByDay
        buckets events into ordered days
        returns an empty array for no events
    daysWithEvents
        includes every day of a multi-day event
    peopleInEvents
        dedupes by id and sorts by name
    describeParticipants
        phrases the participant list naturally
        preserves the order people were listed in
    event ordering does not disturb participant order
        keeps each event's people in their given order after sorting
    visibleSchedules
        hides disabled schedules and sorts by display order
    resolveSelectedPerson
        matches on id first
        falls back to the name when the id has changed
        matches the name case-insensitively
        returns null when nothing matches
    multiple schedules together
        shows one person their duties across every schedule
        narrows to selected schedules
        combines a person and a schedule filter
        keeps the schedules in the order an admin arranged

lib/schedules/visibility.test.ts

    canSeeNames
        is anybody signed in, and nobody else
    visibleEvent
        keeps everything for a member
        keeps the structure and drops the people for a stranger
        never leaves a name behind anywhere in the stripped shape
        does not mutate the event it was given
    visiblePeople
        is the list for a member and nothing for a stranger
        hands a member a copy, not the array itself
    visibleSnapshot
        is untouched for a member
        carries no people, no names on events, and no person ids for a stranger
        keeps everything that isn't a person
    visibleEvents
        applies the rule to every event
    NAMES_WITHHELD
        tells them what to do about it

lib/services.test.ts

    planItemHref
        opens a hymn that is its own file at its lyrics
        carries the number to a whole book's contents, which knows how to resolve it
        falls back to the contents when nobody wrote a number down
        keeps a number off a hymn's own page, which has nothing to resolve
        has nowhere to open a file that isn't a hymn or a book
    planItemNumber
        prefers the number written for this service
        falls back to the hymn's own printed number
    planItemReadable
        opens what is published and public
        closes a members-only hymn to a signed-out visitor, and opens it to a member
        closes a hymn unpublished, hidden or trashed since the plan was made
    planItemPresentable
        presents a hymn that is its own file, from the words on its row
        presents a number inside a book when somebody has typed that number's words
        doesn't present a number whose own words are missing
        doesn't present a scanned book nobody has typed anything out of
        doesn't present a scanned book whose hymn has credits but no words
        doesn't present a whole book listed without a number
    presentHref
        carries the hymn number, so the presenter knows which hymn of the book
        presents a hymn that is its own file with no number to carry
        leaves the plan out when the hymn isn't being presented as part of one

lib/share-links.test.ts

    shareLinkPolicy
        lets anyone share content that is already public, granting nothing
        lets a plain member share restricted content as a plain link
        refuses a plain member asking to override a restriction
        grants access when a permitted sharer asks for the override
        withholds the grant when a permitted sharer doesn't ask for it
        ignores an override asked for on content that isn't restricted
    shareLinkStatus
        reports an unknown token as invalid
        opens a public link for a visitor who isn't logged in
        reports a revoked link as revoked, ahead of any other check
        treats an expiry exactly now as expired
        still opens a link whose expiry is in the future
        sends an anonymous visitor to log in for a private link
        opens a private link for a listed recipient, whatever the case of their email
        refuses a private link for someone it wasn't shared with
        prefers revoked over the recipient check, so a revoked link leaks nothing about who it was for
    parseRecipientEmails
        splits on commas, semicolons, and whitespace alike
        lowercases and de-duplicates, so the unique index can't trip
        drops anything that isn't an email address
        returns nothing for empty input
    expiryFromDays
        treats null, undefined, and 0 as never expiring
        returns a date the given number of days out

lib/share-password.test.ts

    hashSharePassword / verifySharePassword
        accepts the right password
        rejects the wrong password
        salts each hash, so the same password stores differently every time
        treats equivalent unicode spellings as the same password
        returns false rather than throwing on a malformed stored value
    isWithinUnlockWindow
        is false when there's been no failure at all
        is true for a failure inside the window
        is false once the failure has aged out
    isUnlockLockedOut
        allows attempts below the threshold
        locks out at the threshold with a recent failure
        forgives itself once the lockout window passes, with no write needed
        doesn't lock out on a count with no recorded failure time

lib/sheets/dates.test.ts

    parseSheetDate
        month-name formats
            ignores a leading weekday
            parses day-first wording
        numeric formats
            defaults to month/day
            honours the dayFirst setting
            detects an unambiguous day/month even when configured month-first
            expands two-digit years
            parses ISO dates
        serial numbers (a cell formatted as a date)
            converts Google's serial epoch
            accepts a serial that arrived as a string
            rejects numbers outside the plausible range
        rejects things that are not dates
            reports empty separately from unrecognized
            rejects impossible calendar days
            rejects dates decades away, which are almost always typos
            rejects non-string, non-number cells
            rejects absurdly long input rather than trying to parse it
        year inference
            prefers the coming occurrence
            keeps a date that has only just passed in the current year
            respects an explicit defaultYear
            exposes the heuristic directly
    parseSheetTime

lib/sheets/parse.test.ts

    column resolution
        converts letters to indexes
        prefers a header match over a letter
        falls back to the letter when no header matches
    DATE_NAMES format
        parses the documented example
        splits names on every configured separator
        collapses duplicate names inside one cell
        skips blank rows silently
        reports a row that has content but no date
        reports an invalid date and keeps the surrounding rows
        reports rows with a valid date but nobody listed
        keeps unassigned rows when configured to
        rejects entries that are not names
        reports a missing date column rather than importing nothing silently
        reports an empty sheet
        reads optional title, notes and time columns
        works with no header row when columns are given by letter
        gives two events on the same day distinct external ids
        truncates a sheet that exceeds maxRows and says so
        skips dates outside the configured import window
    NAME_COLUMNS format
        parses the documented example
        accepts a variety of tick marks
        treats explicit negatives as unassigned
        uses free text in a person column as a role
        ignores configured non-person columns
        never treats an obvious non-person column as a person
        collapses two columns for the same person and reports it
        ignores a header that is not usable as a name
        requires a header row and says so when there is none
        reports a row where nobody is marked
        survives rows shorter than the header
    fingerprintEvents
        is stable for identical input
        is unchanged by name casing or ordering within a row
        changes when a person is added
        changes when a date moves

lib/slug.test.ts

    slugify
        makes a title into a URL
        keeps the letter when stripping its accent
        never ends in a dash, including after the length cap
        gives nothing back for a title with nothing in it
    uniqueSlug
        leaves a free slug alone
        counts up rather than randomising, so next year's reads like next year's
        falls back rather than returning an empty slug

lib/sms.test.ts

    normalizePhone
        keeps a number that is already international
        reads 00 as the other way of writing +
        adds a country code to a national number and drops the trunk zero
        refuses a national number with no country code to add rather than guessing
        refuses something that isn't a phone number
        refuses one longer than any real number
    smsSegments
        fits 160 plain characters in one message
        spills into two at 161, which are 153 each once split
        charges two places for the bracket-family characters, as the standard does
        halves the allowance for one character outside the 7-bit set
        counts an emoji as the two units it costs
        never reports zero messages, even for nothing

lib/toc-nav.test.ts

    currentTocIndex
        finds the entry a page falls inside, not the next one
        prefers the later of two entries starting in the same place
        has no answer before the first entry
        skips entries it can't place rather than putting them at the front
        has no answer when the reader itself can't be placed
    nextTocIndex
        moves to the first entry after here
        steps past every entry sharing this position, so it always moves
        stops at the last entry
        orders by position, not by the order entries are listed in
    previousTocIndex
        goes back to the start of the entry being read, the way a track skip does
        prefers the later of two entries starting in the same place
        stops at the first entry
        skips entries it can't place
    hymnNumberOf
        reads the number a hymnal's own bookmarks put in front
        ignores a number that is part of the title rather than in front of it
        takes the whole number, not the first digit of it
        refuses a zero, which no hymnal prints
    findHymnIndex
        finds the entry printed under that number
        has no answer for a number the book doesn't list
    countNumberedEntries
        counts only the entries a number can be typed for
    hymnLabelWithout
        takes the number off a contents label, so it isn't shown twice
        leaves a label alone when it starts with a different number
        keeps a label that is nothing but its number

lib/transcribe-worker.test.ts

    hasTimeForAnother
        always allows the first, since nothing has been timed yet
        allows another when the slowest so far would still fit
        stops when it wouldn't
        allows one that fits exactly
        stops once the budget is spent, whatever the estimate

lib/transcribe.test.ts

    transcribeConfig
        is null when no service is configured
        defaults the model and the size cap
        takes a raised cap, for a service that has no small limit
        ignores a cap that isn't a size
    transcribeAudio
        refuses a file past the cap without sending it
        sends the file and the model, and returns the text
        reports what the service said when it refuses
        treats an empty answer as a failure

lib/tv-feed.test.ts

    isFeedable
        takes an ordinary video
        leaves out one with no duration rather than having the feed rejected
        leaves out an imported video with nowhere to play it
    feedDuration
        is a whole number of seconds
    feedDate
        prefers the publish date to when the row happened to be made
    rokuFeed
        describes each video the way Direct Publisher expects
        points at the source's own URL for an imported video
        never sends an empty description, which fails their validation
        trims to their limits rather than being truncated mid-word by them
        carries the series and the speaker as tags, and drops the ones missing
        silently leaves out what it cannot describe
    mrssFeed
        is the same catalogue in the other shape
        escapes what a title can legally contain
        leaves out exactly what the JSON leaves out

lib/tv-nav.test.ts

    move
        walks along a row
        stops at the end of a row rather than wrapping
        stops at the top and the bottom
        keeps your column when it can, moving between rows
        lands on the last item when the row below is shorter, not the first
        goes back out to the column you came from, where the row is long enough
        skips an empty row rather than letting focus vanish into it
        has somewhere to be even with nothing on screen
    the remote's keys
        reads the four arrows
        takes OK however the platform spells it
        takes Back however the platform spells it
    isValid
        knows what is inside the grid
    firstFocusable
        finds the first row with anything in it

lib/tv-pairing.test.ts

    the code alphabet
        leaves out every character that looks like another on a screen
        is still big enough to be worth guessing at
        has no character twice, which would skew what random picks
    normalizeUserCode
        reads a code back however somebody typed it
        forgives a lookalike typed for a character the screen never showed
        stops at six, so one extra keypress does not fail the lookup
    isWellFormedUserCode
        accepts a real one and rejects the rest
    userCodeFromBytes
        only ever produces characters from the alphabet
    formatUserCode
        breaks it in half, which reads back better across a room
    pollAnswer
        tells a television to keep waiting, and how often to ask
        says ready once a member has approved it
        expires an approval nobody collected, rather than leaving it redeemable
        says denied rather than letting the screen time out with no explanation
        refuses to mint a second token for a pairing already used
        treats a revoked device as gone
    canApprove
        is only ever true for a pending code that has not run out
    approvalPrompt
        names the device and says what could go wrong
    cleanDeviceName
        keeps a real name
        will not let a device smuggle a second sentence into the approval
        falls back rather than printing an empty name
        caps a name long enough to fill the screen

lib/upload-types.test.ts

    extensionOf
        takes the last extension, lower-cased, without the dot
        ignores a query or fragment
        is empty for no extension or a dotfile
    uploadType
        knows the reader's formats and common media
        refuses anything that can run as a page
        refuses an unknown extension, and no extension
        is decided by the extension, not by a type the browser claims
    objectName
        keeps the id and the extension, and nothing of the client's name
        cannot be steered out of the files/ prefix
        is null for a refused type
    servePolicy
        shows a reader format or media inline, with nosniff
        forces a download for a document
        serves anything off the list as an opaque download that can't render
        never consults a stored MIME type
    the list itself
        has no type that a browser would render as a document with script
        only shows inline what cannot carry a script
        names the allowed extensions in the refusal

lib/validation/schemas.test.ts

    spreadsheet configuration
        accepts a real-looking spreadsheet id
        rejects ids containing path or query characters
        extracts an id from a pasted URL
        returns null for something that is not a spreadsheet reference
        rejects sheet names that would break A1 range syntax
        quotes the sheet name when building a range
    parser configuration
        fills in sensible defaults
        falls back to defaults for an unusable stored value
        bounds the row cap
    identifiers
        accepts cuid-shaped ids
        rejects anything with injection-shaped characters
    free text
        strips control characters from names
        requires a name to contain a letter
        stores markup verbatim rather than mangling it
        bounds the length of every text field
    event validation
        accepts a minimal event
        rejects a malformed date
        rejects an end date before the start
        rejects an end time before the start time
        requires each participant to have an id or a name
        caps the number of participants
    schedule validation
        requires Google settings for a Google Sheets schedule
        accepts a web-managed schedule with just a name
        rejects an unknown colour token
    push subscription validation
        accepts a well-formed subscription
        rejects a non-HTTPS endpoint
        rejects keys that are not base64url
        rejects a suspicious timezone string
        bounds the reminder hours

lib/verses.test.ts

    splitVerses
        splits on the blank line between verses
        numbers the verses and lets a chorus keep its name
        recognises the other names a hymnal prints, and nothing else
        survives what pasting from a document actually looks like
        has nothing to show for a hymn with no lyrics

lib/video-feed-sync.test.ts

    mayOverwrite
        overwrites a field nobody has touched
        leaves a field somebody rewrote alone
        treats an emptied field as an edit, not as an invitation
        leaves a row alone when there is no record of what was imported
        counts a null live field against an empty imported one as untouched
    mergeImported
        takes the new wording when nothing was edited here
        keeps a renamed title while still taking the new description
        always brings the record of what the source says up to date
    parseIsoDuration
        reads what YouTube reports
        gives null rather than zero for something it can't read
    bestThumbnail
        takes the widest offered, since a card is bigger than a favicon
        copes with no widths and with nothing at all
    fingerprintFeed
        is the same for the same payload and different for a changed one
        notices a video appearing, so a new upload always syncs
    sourceOf
        maps every feed kind to the player it imports into

lib/video-source.test.ts

    videoEmbedUrl
        uses YouTube's no-cookie player and turns off related videos
        carries a start time into each player's own way of taking one
        leaves the start off entirely at zero rather than sending start=0
        asks Vimeo not to track
        escapes an id rather than pasting it into a URL
        gives nothing for a source with no id, rather than a broken frame
    videoThumbnailUrl
        uses the one the source gave us
        returns an empty string rather than a broken image
    videoThumbnailUrl, for a video stored here
        asks Bunny rather than calling itself
    isBunnyVideo
        is what gates the Bunny-only features
    watchAtSourceUrl
        points at the page a person would land on
        has nowhere to send somebody for a video that lives here
    sourceName
        names the three

lib/view-key.test.ts

    viewKey
        is stable for the same address under the same secret
        differs by address and by secret
        is not the address, and is short enough to index
        is null with nothing to key on

Appendix E — Plugins and capabilities (verbatim from the original registries)
E.1 — The 31 bundled features

export const PLUGIN_META = [
  { slug: "favorites", name: "Favorites", description: "Lets members bookmark series and videos to a My Favorites page." },
  { slug: "comments", name: "Comments", description: "Lets members discuss a series or video underneath it." },
  { slug: "related-content", name: "Related content", description: "Shows \"More like this\" / \"You might also like\" rows." },
  { slug: "ratings", name: "Ratings", description: "Lets members leave a 1-5 star rating on a series or video." },
  { slug: "watch-later", name: "Watch later", description: "Lets members queue a series or video to a Watch Later page, separate from Favorites." },
  { slug: "notifications", name: "Notifications", description: "Sends a web push notification to subscribed members when new content is published." },
  { slug: "view-counts", name: "View counts", description: "Shows a play/view counter on series and video pages." },
  { slug: "social-share", name: "Social share", description: "Shows copy-link and share-to buttons on series and video pages." },
  { slug: "announcements", name: "Announcements", description: "Shows a dismissible site-wide banner message." },
  { slug: "subscriptions", name: "Subscriptions", description: "Lets members follow a series or category and get notified when it publishes new content." },
  { slug: "playlists", name: "Playlists", description: "Lets members build their own ordered video playlists." },
  { slug: "likes-dislikes", name: "Likes / dislikes", description: "Lets members like or dislike a series or video." },
  { slug: "up-next", name: "Up next", description: "Shows an \"Up next\" panel with the next video in a series, with an autoplay option." },
  { slug: "watch-history", name: "Watch history", description: "Shows a \"Recently Played\" page and nav tab of everything a member has watched." },
  { slug: "profiles", name: "Profiles", description: "Lets members set a display name shown instead of their Auth0 name in comments and the navbar." },
  { slug: "chapters", name: "Chapters", description: "Shows a jump-to-section chapter list under a video, admin-managed per video." },
  { slug: "transcripts", name: "Transcripts", description: "Shows a collapsible full-text transcript under a video and includes it in search results." },
  { slug: "recommendations", name: "Recommendations", description: "Shows a personalized \"Because you watched\" row on the homepage, based on the member's most recent watch." },
  { slug: "webhooks", name: "Webhooks", description: "Posts a JSON payload to admin-configured URLs whenever a series or video is published." },
  { slug: "live-streaming", name: "Live streaming", description: "Shows a \"Live now\" banner and /live page for admin-scheduled live streams, with a push notification when one goes live." },
  { slug: "sermon-notes", name: "Sermon notes", description: "Lets members keep their own timestamped notes on a video, exportable as a text file." },
  { slug: "share-links", name: "Share links", description: "Lets members create revocable share links to a series or video, public or emailed to specific people." },
  { slug: "downloads", name: "Downloads", description: "Lets members download videos to their device for offline viewing, with per-category/series/video control at /admin/downloads." },
  { slug: "service-plans", name: "Service plans", description: "Lets staff publish the running order of hymns for a service, which members open as one list at /services." },
  { slug: "book-reader", name: "Book reader", description: "Opens PDF and EPUB files in an in-app reader with contents, search, highlights and read-aloud, instead of only offering them as downloads." },
  { slug: "schedules", name: "Schedules", description: "Rotas anyone can read at /calendar — fed from a Google Sheet or managed here. Names rather than accounts, for the people who never log in." },
  { slug: "events", name: "Events", description: "Published events at /events with sign-up: capacity, a waiting list that moves when somebody drops out, and guests." },
  { slug: "tv", name: "Television", description: "A remote-friendly /tv screen, a catalogue feed a Roku channel can be built from, and sign-in by a code on the screen so nobody types a password with a remote." },
  { slug: "groups", name: "Small groups", description: "A directory of home groups at /groups, with join requests a leader answers. The address is given only to people in the group." },
  { slug: "prayer", name: "Prayer wall", description: "A moderated wall of prayer requests at /prayer, with an anonymous option and an \"I prayed for this\" count. Nothing appears until it is let through." },
  { slug: "forms", name: "Forms", description: "Connect cards and sign-up forms built here rather than in code, filled in at /forms, with the responses kept and exportable." },
] as const;

E.2 — Capabilities

/** Fixed set of grantable capabilities, phpBB/WordPress-style. Custom permission groups pick a subset of these. */
export const CAPABILITIES = [
  { key: "manage_categories", label: "Manage categories", hint: "Create, reorder, and delete categories" },
  { key: "manage_series", label: "Manage series", hint: "Create, edit, and delete series" },
  { key: "manage_videos", label: "Manage videos", hint: "Upload, edit, and delete videos" },
  { key: "manage_files", label: "Manage files", hint: "Upload, edit, and delete files" },
  { key: "publish_content", label: "Publish content", hint: "Publish/unpublish, feature, and pin content" },
  { key: "moderate_comments", label: "Moderate comments", hint: "Delete or hide any comment, not just your own" },
  { key: "share_content", label: "Share restricted content", hint: "Create share links that grant access to member-only or restricted content" },
  { key: "manage_users", label: "Manage users", hint: "Grant access and change roles" },
  { key: "manage_permissions", label: "Manage permissions", hint: "Create groups and assign them to users" },
  { key: "manage_plugins", label: "Manage plugins", hint: "Enable or disable optional features" },
  // Church life: the diary, the sign-up sheets and the group list are one
  // job, usually one person's. Prayer is deliberately not in with them —
  // approving a request somebody wrote about their marriage is pastoral work,
  // and often not the person who books the hall.
  { key: "manage_events", label: "Manage events, forms and groups", hint: "Publish events and see who signed up, build forms, and keep the small-group list" },
  { key: "moderate_prayer", label: "Moderate the prayer wall", hint: "Approve, hide and mark answered the requests members post" },
  { key: "view_audit_log", label: "View audit log", hint: "See the history of admin/editor actions" },
  // Its own capability rather than folded into managing users or plugins: a
  // key is standing machine access to the catalogue and, if the scope is
  // ticked, to people's names and phone numbers. Granting that is a different
  // decision from either of those, and it should have to be made on purpose.
  { key: "manage_api_keys", label: "Manage API keys", hint: "Create and revoke keys that let another system read this one" },
  { key: "view_analytics", label: "View analytics", hint: "See the views dashboard and trending content" },
] as const;

export type CapabilityKey = (typeof CAPABILITIES)[number]["key"];

export const CAPABILITY_KEYS: CapabilityKey[] = CAPABILITIES.map((c) => c.key);

/** Capabilities that only make sense as a site-wide grant, not scoped to a category/series. */
export const SITE_WIDE_ONLY_CAPABILITIES: CapabilityKey[] = [
  "manage_users",
  "manage_permissions",
  "manage_plugins",
  "view_audit_log",
  "view_analytics",
  "manage_api_keys",
  "manage_categories",
  "manage_events",
  "moderate_prayer",
];

Appendix F — Scheduled jobs (vercel.json, verbatim)

Times are UTC and daily only because of the original host's limit; Scheduled jobs above sets the port's intervals. What each job does is in Appendix A under "Scheduled jobs".

{
  "$schema": "https://openapi.vercel.sh/vercel.json",
  "buildCommand": "npx prisma migrate deploy && node scripts/copy-offline-viewers.mjs && next build",
  "crons": [
    {
      "path": "/api/cron/sync-schedules",
      "schedule": "30 5 * * *"
    },
    {
      "path": "/api/cron/sync-video-status",
      "schedule": "0 6 * * *"
    },
    {
      "path": "/api/cron/notification-digest",
      "schedule": "0 13 * * *"
    },
    {
      "path": "/api/cron/schedule-reminders",
      "schedule": "0 18 * * *"
    },
    {
      "path": "/api/cron/transcribe",
      "schedule": "0 2 * * *"
    },
    {
      "path": "/api/cron/broadcasts",
      "schedule": "45 3 * * *"
    },
    {
      "path": "/api/cron/sync-video-feeds",
      "schedule": "15 7 * * *"
    },
    {
      "path": "/api/cron/extend-events",
      "schedule": "0 4 * * *"
    }
  ]
}

Appendix G — Configuration reference (.env.example, verbatim)

Every variable here becomes an installer question or an Admin → Services setting, as The services layer describes; the comments are the reference for what each integration needs and why.

# Postgres connection strings. Two are required, and they are NOT
# interchangeable:
#
#   POOLED_DATABASE_URL  what the running app queries through. Must be POOLED
#                        — a serverless deployment opens one connection per
#                        concurrent function instance, and only a pooler
#                        survives that.
#   DATABASE_URL         what `prisma migrate deploy`/`db push` use. Must be a
#                        DIRECT TCP connection: poolers don't support the DDL
#                        statements migrations issue.
#
# Yes, DATABASE_URL is the *direct* one here — that asymmetry is deliberate.
# Vercel's Prisma Postgres marketplace integration injects DATABASE_URL and
# marks it integration-managed, which makes it read-only in the dashboard
# (only "Rotate Integration Secrets" is offered). What it injects is the
# direct connection, so the schema reads it as `directUrl`, where a direct
# connection is what's wanted anyway, and the pooled string is added by hand
# as POOLED_DATABASE_URL — which Vercel does allow.
#
# Prisma Postgres — Console (Connect to your database) labels the two strings
# by client rather than by pooling, so match them by protocol:
#   "Prisma ORM"  -> prisma+postgres://accelerate.prisma-data.net/?api_key=...
#                    This IS the pooled connection (Accelerate has pooling
#                    built in). Works natively with @prisma/client 6.x — no
#                    extension to install. Put it in POOLED_DATABASE_URL.
#   "Any Client"  -> postgres://...@db.prisma.io:5432/...?sslmode=require
#                    The direct connection. Put it in DATABASE_URL.
# Pointing runtime queries at the direct connection is what produces "too many
# connections for role" once real traffic arrives: that role's cap is sized
# for a migration's brief burst, not sustained app load.
#
# Neon/Supabase/plain Postgres: use their pooled endpoint (Neon's -pooler
# host, Supabase's port 6543) for POOLED_DATABASE_URL and the direct one for
# DATABASE_URL. With no separate pooled endpoint at all, both can be the same.
POOLED_DATABASE_URL="prisma+postgres://accelerate.prisma-data.net/?api_key=..."
DATABASE_URL="postgres://user:password@db.prisma.io:5432/postgres?sslmode=require"

# Auth0 (Application: Regular Web Application)
AUTH0_DOMAIN="your-tenant.us.auth0.com"
AUTH0_CLIENT_ID="..."
AUTH0_CLIENT_SECRET="..."
# Generate with: openssl rand -hex 32
AUTH0_SECRET="..."
APP_BASE_URL="http://localhost:3000"

# Comma-separated list of emails that should be granted the ADMIN role on login
ADMIN_EMAILS="you@example.com"


# --- Authorization; see auth0-actions/README.md ---

# How the two authorization checks (Auth0 organization membership, and an
# authorized email below) combine:
#   BOTH          (default) organization membership AND an authorized email
#   ORGANIZATION  organization membership only — the email list is not enforced
#   ALLOWLIST     the email list only — organization membership is not enforced
#   EITHER        organization membership OR an authorized email — either is
#                 enough on its own. This is the "personal account or
#                 organization account" mode: someone in an approved
#                 organization gets in without needing an allowlist entry, and
#                 someone with no organization (a personal Google account, say)
#                 still gets in with an ACTIVE entry below. Also requires this
#                 Application's "Type of Users" set to "Both" (Application ->
#                 Login Experience tab in the Auth0 dashboard) — without it,
#                 Auth0 itself still insists on an organization before our own
#                 check ever runs, and the personal-account path never becomes
#                 reachable.
# Anything unrecognised (or unset) means BOTH: a typo here must never be what
# opens a door, and there is deliberately no value that disables both checks.
# In ALLOWLIST or EITHER mode the app also stops sending `organization` on the
# login request, since Auth0 would otherwise reject non-members before our own
# check runs.
#
# To invite one guest without switching the whole deployment off BOTH, flag
# their row `organizationExempt` ("Guest" in /admin/authorized-emails) instead
# of changing this variable — that address gets in without organization
# membership, and everyone else still needs both checks. Send that guest the
# /auth/guest link rather than the normal Log in button: the normal one names
# the organization, so Auth0 turns a non-member away before the allowlist is
# consulted. That route also needs "Type of Users: Both" on the Application,
# and is closed by default — open it with the "Guest sign-in link" toggle at
# the top of /admin/authorized-emails (no env var, no redeploy) before
# sending the link, and close it again once the guest is done.
AUTHORIZATION_MODE="BOTH"

# The Auth0 Organization id(s) (org_...) users must belong to one of (under
# BOTH/ORGANIZATION/EITHER — ignored under ALLOWLIST). Found under
# Organizations -> <org> -> Settings. Comma-separate more than one
# (e.g. "org_aaa,org_bbb") to accept several organizations — with two or more
# listed, Auth0 prompts the member to choose one at login instead of us
# picking for them (requires "Prompt for Organization" turned on for this
# Application in the Auth0 dashboard). REQUIRED under BOTH/ORGANIZATION: the
# organization check fails closed, so leaving this unset denies everyone
# rather than letting everyone through.
AUTH0_ORGANIZATION_ID="org_xxxxxxxxxxxx"

# Shared secret for POST /api/auth/registration-check, called by the Auth0
# Pre-User-Registration Action. Generate with `openssl rand -hex 32` and set
# the SAME value as an Auth0 Action Secret of this name. Unset means the
# endpoint refuses every registration check (fails closed).
AUTH0_REGISTRATION_CHECK_SECRET=""

# Bunny Stream (Video Library)
BUNNY_STREAM_LIBRARY_ID="12345"
BUNNY_STREAM_API_KEY="your-library-api-key"
# Pull zone hostname for thumbnails, e.g. vz-xxxxxxxx-xxx.b-cdn.net
BUNNY_STREAM_CDN_HOSTNAME="vz-xxxxxxxx-xxx.b-cdn.net"
# Only set this if "Token Authentication" is enabled under Library -> Security
# in the Bunny dashboard. It's the "Token Authentication Key" shown there —
# NOT the same as BUNNY_STREAM_API_KEY. Leave unset if token auth is off.
BUNNY_STREAM_TOKEN_AUTH_KEY=""

# Resolution offline downloads are served at (Downloads plugin). One of
# 240/360/480/720/1080; defaults to 720. The matching MP4 must exist in the
# library, which means enabling "MP4 Fallback" under Stream -> your library ->
# Encoding — without it /api/downloads refuses with "no downloadable file yet"
# rather than handing out a 404.
BUNNY_STREAM_DOWNLOAD_HEIGHT="720"

# Bunny Storage (Files)
BUNNY_STORAGE_ZONE="your-storage-zone-name"
BUNNY_STORAGE_API_KEY="your-storage-zone-password"
# Leave blank for the default (Falkenstein) region, or set e.g. "ny", "la", "sg", "syd", "uk"
BUNNY_STORAGE_REGION=""
# CDN hostname mapped to the storage zone's pull zone, e.g. xxxxxxxx.b-cdn.net
BUNNY_STORAGE_PULL_ZONE_HOSTNAME="xxxxxxxx.b-cdn.net"
# STRONGLY RECOMMENDED. Set this after enabling "Token Authentication" under
# the storage zone's Pull Zone -> Security in the Bunny dashboard. It's the
# "Token Authentication Key" shown there — NOT the same as
# BUNNY_STORAGE_API_KEY.
#
# Without it the pull zone serves every uploaded file to anyone who knows
# (or guesses) its URL, with no login and no way to revoke — so a file's
# "members only" flag protects the page, not the bytes. The app no longer
# hands those URLs out (downloads and the reader both stream through
# /api/files/[id]/content, which re-checks access per request), but any URL
# already shared keeps working until the zone requires a token.
BUNNY_STORAGE_TOKEN_AUTH_KEY=""

# --- Public podcast zone (optional, OFF by default) -------------------------
# A SEPARATE storage zone, with its own pull zone, holding nothing but
# podcast audio an admin has explicitly published. Podcast apps can't log in
# and an enclosure URL has to keep working for years, so this is the one
# place files are readable with no session.
#
# It is a separate *storage* zone, not an edge rule on the private one, on
# purpose: a private file simply isn't in this zone, so there's no path to
# guess and no rule that can be quietly removed later. Never point this at
# the same storage zone as above — that re-exposes every file the token auth
# is protecting.
#
# Leave all four unset (the default) and podcast audio streams through the
# app's own gated route instead. That's safer — it stops serving the moment a
# series is marked members-only — but the bytes go through your app rather
# than Bunny's edge. Configure these only if podcast bandwidth warrants it.
#
# Publishing is per-file and opt-in: "Not in podcast" / "In podcast" in
# /admin/files. Nothing is copied here automatically, and existing audio was
# NOT back-filled when this was introduced.
BUNNY_PUBLIC_STORAGE_ZONE=""
BUNNY_PUBLIC_STORAGE_API_KEY=""
# Leave blank for the default (Falkenstein) region, as with the private zone.
BUNNY_PUBLIC_STORAGE_REGION=""
# The public zone's pull zone hostname. Token Authentication must stay OFF
# here — that's the entire point of this zone.
BUNNY_STORAGE_PUBLIC_PULL_ZONE_HOSTNAME=""

# Web Push (for the Notifications plugin). Optional — leave unset and the
# notifications plugin's send calls become a no-op. Generate a keypair with:
#   npx web-push generate-vapid-keys
# NEXT_PUBLIC_VAPID_PUBLIC_KEY must match VAPID_PUBLIC_KEY (the client needs
# it to create a push subscription; the private key never reaches the browser).
VAPID_PUBLIC_KEY=""
VAPID_PRIVATE_KEY=""
NEXT_PUBLIC_VAPID_PUBLIC_KEY=""
# Contact address push services may use to reach you about your usage
VAPID_SUBJECT="mailto:you@example.com"
# Web Push endpoints are accepted only for the browsers' own push services
# (Chrome, Firefox, Edge, Safari, Samsung — see src/lib/push-endpoint.ts).
# A browser not on that list can be allowed by host suffix, comma-separated.
PUSH_SERVICE_HOSTS=""

# Email (for the Notifications plugin's opt-in email channel, alongside Web
# Push above). Optional — leave unset and sendEmail() becomes a no-op. Get an
# API key at https://resend.com; EMAIL_FROM must be a verified sender/domain
# there, e.g. "Marine Team <notifications@yourchurch.org>".
RESEND_API_KEY=""
EMAIL_FROM=""

# Bearer token for the scheduled jobs under /api/cron/* (see the "crons" in
# vercel.json). Vercel Cron sends "Authorization: Bearer $CRON_SECRET"
# automatically when it is set. REQUIRED in production: a production
# deployment without it answers 503 to every cron call rather than running
# the job for whoever asks (transcription is paid per call). Leave unset
# locally and the routes stay open for curl.
CRON_SECRET=""

# Query Monitor: a WordPress-Query-Monitor-style debug bar (query count/time,
# page render time, process memory) shown at the bottom of every page to
# logged-in ADMIN users. Must be "true" (case-insensitive — "TRUE"/"True"
# work too) to turn on; anything else (including unset) is off. Its status
# is also readable at /admin/query-monitor, but that page can't flip this —
# it's env-only, like WP_DEBUG, not a database-toggled plugin.
QUERY_MONITOR_ENABLED="false"

# --- Automatic transcription (optional) ---------------------------------
# Where a video's audio is sent to be turned into text. Unset, the
# "Transcribe it for me" button is refused and transcripts stay hand-typed —
# which is the honest state for a deployment with nowhere to send audio.
#
# Any speech-to-text service taking a multipart POST with a `file` field and
# answering `{ "text": ... }` works, hosted or self-hosted: that is the shape
# Whisper servers and the hosted APIs share. Point it at a machine in the
# office and no audio leaves the building.
TRANSCRIBE_API_URL=""
# Sent as `Authorization: Bearer`. Leave empty for a service on your own
# network that doesn't want one.
TRANSCRIBE_API_KEY=""
# The model name the service expects.
TRANSCRIBE_MODEL="whisper-1"
# Files bigger than this aren't sent. The default (25MB) is what the common
# hosted endpoints accept; a self-hosted server usually has no such limit, and
# a sermon-length MP4 is well over it, so raise this when you run your own.
TRANSCRIBE_MAX_BYTES=""

# --- Schedules from a Google Sheet (optional) ---------------------------
# A schedule can take its events from a spreadsheet somebody already
# maintains. With none of these set, Google Sheets simply isn't offered as a
# source and every schedule is managed in the admin interface instead —
# which is the normal case, and nothing about it is degraded.
#
# A service account is the right answer: access is granted per spreadsheet by
# sharing it with that address, so the app can read the one sheet it was given
# and nothing else. Share each spreadsheet with this address as a Viewer —
# this is the step people forget, and the symptom is a 403 the admin page
# quotes back at you along with the address to share with.
GOOGLE_SERVICE_ACCOUNT_EMAIL=""
# Keep the literal \n sequences from the downloaded JSON, and the quotes.
GOOGLE_SERVICE_ACCOUNT_PRIVATE_KEY=""
# Alternative to the two above: the whole service-account JSON on one line.
GOOGLE_SERVICE_ACCOUNT_JSON=""
# Last resort. Only works for a sheet shared with "anyone with the link",
# which is a much broader thing to have done than sharing one file with one
# address. Prefer a service account.
GOOGLE_SHEETS_API_KEY=""

# --- Text messages (optional) -------------------------------------------
# Only needed to send an announcement as a text. With none of these set the
# SMS channel is offered on the compose screen and refused with a message
# naming what is missing, rather than silently sending nothing.
#
# A text costs the church money and the recipient their attention, and in most
# places sending one without consent is illegal — so a member has to give
# their own number and tick the box before any of this reaches them.
#
# Twilio, which is what most people already have:
TWILIO_ACCOUNT_SID=""
TWILIO_AUTH_TOKEN=""
# The number or alphanumeric sender messages come from.
TWILIO_FROM=""
#
# Or your own gateway: a POST of {to, from, body} as JSON.
SMS_WEBHOOK_URL=""
SMS_WEBHOOK_TOKEN=""
SMS_FROM=""
#
# Country code to assume for a number typed without one, digits only ("44",
# "1"). Unset, a national number is refused rather than guessed at — guessing
# texts a stranger in another country about a church they've never heard of.
SMS_DEFAULT_COUNTRY_CODE=""

# --- Importing videos from YouTube or Vimeo (optional) -------------------
# For a church that already streams its service somewhere else: point at a
# channel, playlist, account or showcase and the videos become ordinary rows
# here that play in the source's own frame. Unset, the admin screen says which
# key is missing rather than importing nothing every night in silence.
#
# A Data API v3 key from the Google Cloud console.
YOUTUBE_API_KEY=""
# A Vimeo personal access token with the private scope.
VIMEO_ACCESS_TOKEN=""

Appendix H — Names the browser and integrations depend on

Extracted from the original source. These are part of the compatibility contract: an installed PWA, a saved book or video, a chosen bottom bar and a locale all live under these names on members' devices today.
H.1 — Named constants (storage keys, cookies, cache names, events)
Constant 	Value 	Where
BOOK_CACHE 	marine-team-books-v1 	public/sw.js
BOOK_CACHE 	marine-team-books-v1 	src/lib/offline-books.ts
BOOK_PATH_PREFIX 	/offline-book/ 	public/sw.js
CACHE_NAME 	marine-team-shell-v5 	public/sw.js
CALENDAR_CACHE 	marine-team-calendar-v1 	public/sw.js
CALENDAR_CACHE 	marine-team-calendar-v1 	src/lib/offline-calendar.ts
CALENDAR_PATH_PREFIX 	/offline-calendar/ 	public/sw.js
DEVICE_SETTINGS_EVENT 	marine-device-settings-change 	src/lib/device-settings.ts
DEVICE_SETTINGS_KEY 	marine-device-settings 	src/lib/device-settings.ts
DOWNLOADS_CHANGED_EVENT 	marine-downloads-change 	src/lib/offline-downloads.ts
DOWNLOAD_CACHE 	marine-team-downloads-v1 	public/sw.js
DOWNLOAD_CACHE 	marine-team-downloads-v1 	src/lib/offline-downloads.ts
DOWNLOAD_PATH_PREFIX 	/offline-video/ 	public/sw.js
HYMNAL_PATH_PREFIX 	/offline-hymnal/ 	public/sw.js
INDEX_KEY 	marine-downloads-index 	src/lib/offline-downloads.ts
INDEX_KEY 	marine-offline-books 	src/lib/offline-books.ts
INDEX_KEY 	marine-offline-calendar 	src/lib/offline-calendar.ts
INDEX_KEY 	marine-offline-services 	src/lib/offline-services.ts
KEY_PREFIX 	marine-toc-v1: 	src/lib/reader-cache.ts
KEY_PREFIX 	mt_live_ 	src/lib/api-keys.ts
LOCALE_COOKIE 	marine-locale 	src/lib/i18n/index.ts
NAV_TABS_SNAPSHOT_KEY 	marine-nav-tabs 	src/lib/nav-tabs.ts
OFFLINE_BOOKS_CHANGED_EVENT 	marine-offline-books-change 	src/lib/offline-books.ts
OFFLINE_CALENDAR_CHANGED_EVENT 	marine-offline-calendar-change 	src/lib/offline-calendar.ts
OFFLINE_SERVICES_CHANGED_EVENT 	marine-offline-services-change 	src/lib/offline-services.ts
SERVICE_CACHE 	marine-team-services-v1 	public/sw.js
SERVICE_CACHE 	marine-team-services-v1 	src/lib/offline-services.ts
SERVICE_PATH_PREFIX 	/offline-service/ 	public/sw.js
SHARE_COOKIE 	share_access 	src/lib/share-access.ts
H.1b — Names the static files use by literal (the offline shell and service worker have no bundle to import constants from)
Name 	Where
marine-device-settings 	public/offline.html
marine-downloads-index 	public/offline.html
marine-nav-tabs 	public/offline.html
marine-offline-books 	public/offline.html
marine-offline-calendar 	public/offline.html
marine-offline-services 	public/offline.html
marine-team-books-v1 	public/offline.html
marine-team-books-v1 	public/sw.js
marine-team-calendar-v1 	public/offline.html
marine-team-calendar-v1 	public/sw.js
marine-team-downloads-v1 	public/offline.html
marine-team-downloads-v1 	public/sw.js
marine-team-services-v1 	public/offline.html
marine-team-services-v1 	public/sw.js
marine-team-shell-v5 	public/sw.js
H.2 — Cache Storage and static viewer paths referenced

    /epubjs/
    /epubjs/epub.min.js
    /epubjs/jszip.min.js
    /offline-book/
    /offline-calendar/
    /offline-calendar/snapshot.json
    /offline-hymnal/
    /offline-service/
    /offline-video/
    /pdfjs/
    /pdfjs/pdf.min.mjs
    /pdfjs/pdf.worker.min.mjs

H.3 — How the vendored viewers are laid out (scripts/copy-offline-viewers.mjs, verbatim)

/**
 * Copies browser builds out of node_modules into `public/`.
 *
 * Two unrelated needs, one script, because both are "a file a page has to be
 * able to name a URL for": the offline shell's readers, and the OCR engine.
 *
 * The app itself never needs these: it imports both through the bundler,
 * which keeps them version-locked without anything in `public` (see
 * src/lib/pdf-client.ts). But `public/offline.html` is a static file the
 * service worker serves with no network and no bundle, and it has to be able
 * to draw a saved book — so it needs a copy of the library at a URL it can
 * name, cached alongside the book.
 *
 * epub.js comes with JSZip: its dist build is UMD and expects `JSZip` as a
 * global, so the pair travel together or neither works.
 *
 * Copied at install/build time rather than committed, so they stay whatever
 * versions package.json pins. Deliberately never fails the build: without
 * pdf.js the offline shell hands a PDF to the browser's own viewer, which is
 * worse but not broken, and an EPUB simply isn't offered for saving.
 */
import { copyFile, mkdir, stat } from "node:fs/promises";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";

const root = join(dirname(fileURLToPath(import.meta.url)), "..");
const modules = join(root, "node_modules");
const publicDir = join(root, "public");

// The minified builds only: these are fetched over a member's connection
// when they first save a book, and the unminified ones are several times the
// size for the same behaviour.
/**
 * The OCR engine, for reading a scanned book that has no text layer.
 *
 * Vendored rather than left to tesseract.js's default, which fetches its
 * worker, its wasm core and its language data from a public CDN. A church
 * office on a filtered connection would find the feature simply doesn't
 * work, with nothing on screen to say why — and this app doesn't load its
 * own code from anybody else's server.
 *
 * Only the LSTM path: that is what tesseract.js uses by default, and the
 * `best_int` language data is a quarter the size of the full set for the
 * same job. All three core variants travel because the worker feature-detects
 * which one this browser can run and asks for it by name — a missing one is
 * a 404 mid-run rather than a fallback.
 */
const TESSERACT_CORE = [
  "tesseract-core-lstm.wasm.js",
  "tesseract-core-simd-lstm.wasm.js",
  "tesseract-core-relaxedsimd-lstm.wasm.js",
];

const FILES = [
  { from: join(modules, "pdfjs-dist", "build", "pdf.min.mjs"), to: join(publicDir, "pdfjs", "pdf.min.mjs") },
  {
    from: join(modules, "pdfjs-dist", "build", "pdf.worker.min.mjs"),
    to: join(publicDir, "pdfjs", "pdf.worker.min.mjs"),
  },
  { from: join(modules, "jszip", "dist", "jszip.min.js"), to: join(publicDir, "epubjs", "jszip.min.js") },
  { from: join(modules, "epubjs", "dist", "epub.min.js"), to: join(publicDir, "epubjs", "epub.min.js") },
  {
    from: join(modules, "tesseract.js", "dist", "worker.min.js"),
    to: join(publicDir, "tesseract", "worker.min.js"),
  },
  ...TESSERACT_CORE.map((name) => ({
    from: join(modules, "tesseract.js-core", name),
    to: join(publicDir, "tesseract", name),
  })),
  {
    from: join(modules, "@tesseract.js-data", "eng", "4.0.0_best_int", "eng.traineddata.gz"),
    to: join(publicDir, "tesseract", "eng.traineddata.gz"),
  },
];

async function sizeOf(path) {
  try {
    return (await stat(path)).size;
  } catch {
    return null;
  }
}

async function main() {
  for (const { from, to } of FILES) {
    const name = to.slice(publicDir.length + 1);
    const sourceSize = await sizeOf(from);
    if (sourceSize === null) {
      console.warn(`[offline-viewers] ${name} not found; the feature that needs it will do without it.`);
      continue;
    }
    // Idempotent: this runs on every install and every build, and re-copying
    // two megabytes each time is noise in the build log for no gain.
    if ((await sizeOf(to)) === sourceSize) continue;
    await mkdir(dirname(to), { recursive: true });
    await copyFile(from, to);
    console.log(`[offline-viewers] copied ${name} (${Math.round(sourceSize / 1024)} KB)`);
  }
}

main().catch((error) => {
  console.warn(`[offline-viewers] skipped: ${error?.message ?? error}`);
});

Appendix I — Service worker, offline shell and manifest (verbatim)

Ship these as static files at /sw.js, /offline.html and /manifest.json (the last also rendered live at /api/manifest from branding). The only edits the port makes: the base path, where the site is installed in a subdirectory, and the endpoints the shell probes if their paths ever change — which the compatibility contract says they don't.
I.1 — public/sw.js

// Minimal service worker: enables PWA installability and Web Push
// notifications. Deliberately does NOT cache pages/API responses — this
// site's content is dynamic and often auth-gated, so an aggressive cache
// would risk showing stale or wrong-audience content offline. It only
// caches its own static shell assets, plus the videos, books, service
// orders and calendar a member explicitly saved (see the /offline-video/,
// /offline-book/, /offline-service/ and /offline-calendar/ handlers
// below), which are deliberate, member-initiated copies rather than
// opportunistic caching.
//
// /offline.html is the one exception to "no pages": it's a static,
// unauthenticated, data-free file (no Next.js build output, no server
// round trip) that reads the same localStorage indexes the app writes and
// plays or reads straight out of those caches. Without it, a failed
// navigation — including the installed PWA's own start_url on a cold
// launch — falls through to the browser's native "you're offline"
// interstitial, which has no way to reach anything already on the device.
// See the navigate branch of the fetch handler below.
const CACHE_NAME = "marine-team-shell-v5";
const SHELL_ASSETS = ["/manifest.json", "/icon-192.png", "/icon-512.png", "/offline.html"];

// Written by src/lib/offline-downloads.ts and src/lib/offline-books.ts; kept
// out of the activate-time cleanup below so a version bump of the shell never
// wipes someone's downloads or their hymnals.
const DOWNLOAD_CACHE = "marine-team-downloads-v1";
const DOWNLOAD_PATH_PREFIX = "/offline-video/";
const BOOK_CACHE = "marine-team-books-v1";
const BOOK_PATH_PREFIX = "/offline-book/";
// A hymn-per-file book is saved as its list of hymns rather than as a file,
// and lives in the same cache under its own path.
const HYMNAL_PATH_PREFIX = "/offline-hymnal/";
// A service's running order (src/lib/offline-services.ts). Its own cache
// rather than the books' one: a plan is kept for a particular Sunday and
// thrown away after it, and clearing one shouldn't take the other with it.
const SERVICE_CACHE = "marine-team-services-v1";
const SERVICE_PATH_PREFIX = "/offline-service/";
// The rota calendar (src/lib/offline-calendar.ts). One file rather than one
// per thing saved, and the only one of these that is kept up to date rather
// than saved once: it is a few kilobytes and it syncs incrementally.
const CALENDAR_CACHE = "marine-team-calendar-v1";
const CALENDAR_PATH_PREFIX = "/offline-calendar/";
// The reader libraries, saved into the book cache alongside the first book
// that needs them so the offline shell has something to draw a page with.
const VIEWER_PATH_PREFIXES = ["/pdfjs/", "/epubjs/"];
// The app's own file route. A saved book is the same bytes under a different
// name, which is what lets the in-app reader survive the connection dropping
// while it is open.
const CONTENT_PATH = /^\/api\/files\/([^/]+)\/content$/;

self.addEventListener("install", (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => cache.addAll(SHELL_ASSETS)).then(() => self.skipWaiting()),
  );
});

self.addEventListener("activate", (event) => {
  event.waitUntil(
    caches
      .keys()
      .then((keys) =>
        Promise.all(
          keys
            .filter(
              (key) =>
                key !== CACHE_NAME &&
                key !== DOWNLOAD_CACHE &&
                key !== BOOK_CACHE &&
                key !== SERVICE_CACHE &&
                key !== CALENDAR_CACHE,
            )
            .map((key) => caches.delete(key)),
        ),
      )
      .then(() => self.clients.claim()),
  );
});

/**
 * Answers from one of the saved-media caches.
 *
 * Range requests get an explicit slice of the cached blob: a media element
 * seeking in a file expects 206 Partial Content, and Cache Storage always
 * replays the whole 200 response, which several browsers refuse to seek in.
 * A PDF viewer asking for ranges is the same story.
 */
async function respondFromCache(cacheName, path, request, fallbackType) {
  const cached = await caches.open(cacheName).then((cache) => cache.match(path));
  if (!cached) return null;

  const range = request.headers.get("range");
  if (!range) return cached;

  const blob = await cached.blob();
  const match = /bytes=(\d*)-(\d*)/.exec(range);
  const start = match && match[1] ? Number(match[1]) : 0;
  const end = match && match[2] ? Number(match[2]) : blob.size - 1;

  return new Response(blob.slice(start, end + 1), {
    status: 206,
    headers: {
      "Content-Type": blob.type || fallbackType,
      "Content-Range": `bytes ${start}-${end}/${blob.size}`,
      "Content-Length": String(end - start + 1),
      "Accept-Ranges": "bytes",
    },
  });
}

self.addEventListener("fetch", (event) => {
  const url = new URL(event.request.url);
  if (url.origin !== self.location.origin) return;

  // Downloaded videos, saved books, a service order and the calendar. These
  // URLs are ours by construction (/offline-video/<id>.mp4,
  // /offline-book/<id>.pdf) and never exist on the server, so this handler is
  // the only thing that can answer them — which is what makes a plain
  // <video src> play, and a saved hymnal open, with no network at all.
  if (
    url.pathname.startsWith(DOWNLOAD_PATH_PREFIX) ||
    url.pathname.startsWith(BOOK_PATH_PREFIX) ||
    url.pathname.startsWith(HYMNAL_PATH_PREFIX) ||
    url.pathname.startsWith(SERVICE_PATH_PREFIX) ||
    url.pathname.startsWith(CALENDAR_PATH_PREFIX)
  ) {
    const isVideo = url.pathname.startsWith(DOWNLOAD_PATH_PREFIX);
    const isService = url.pathname.startsWith(SERVICE_PATH_PREFIX);
    const isCalendar = url.pathname.startsWith(CALENDAR_PATH_PREFIX);
    const isJson = isService || isCalendar || url.pathname.startsWith(HYMNAL_PATH_PREFIX);
    event.respondWith(
      respondFromCache(
        isVideo
          ? DOWNLOAD_CACHE
          : isService
            ? SERVICE_CACHE
            : isCalendar
              ? CALENDAR_CACHE
              : BOOK_CACHE,
        url.pathname,
        event.request,
        isVideo
          ? "video/mp4"
          : isJson
            ? "application/json"
            : url.pathname.endsWith(".epub")
              ? "application/epub+zip"
              : "application/pdf",
      ).then((response) => response || new Response("Not saved on this device", { status: 404 })),
    );
    return;
  }

  // The reader libraries: cache first, because the whole point of having
  // saved them is that they are there when the network isn't.
  if (VIEWER_PATH_PREFIXES.some((prefix) => url.pathname.startsWith(prefix))) {
    event.respondWith(
      caches
        .open(BOOK_CACHE)
        .then((cache) => cache.match(url.pathname))
        .then((cached) => cached || fetch(event.request)),
    );
    return;
  }

  // A book's own bytes, for the reader inside the app. The network is asked
  // first and always wins — this is an access-checked route, and a cached
  // copy must never stand in for a "no" — so this only catches the case where
  // there is no network to answer at all, and only for a book this device was
  // deliberately given.
  const contentMatch = CONTENT_PATH.exec(url.pathname);
  if (contentMatch && event.request.method === "GET") {
    event.respondWith(
      fetch(event.request).catch(async () => {
        // Either extension: the saved copy is named for the reader that
        // opens it, and this handler doesn't know which one this file is.
        for (const format of ["pdf", "epub"]) {
          const cached = await respondFromCache(
            BOOK_CACHE,
            `${BOOK_PATH_PREFIX}${contentMatch[1]}.${format}`,
            event.request,
            format === "epub" ? "application/epub+zip" : "application/pdf",
          );
          if (cached) return cached;
        }
        return Response.error();
      }),
    );
    return;
  }

  // Page loads (typing the URL, a bookmark, reopening the installed PWA)
  // try the network exactly as they would with no service worker at all —
  // this never serves a stale page. Only when the network is actually
  // unreachable does it fall back to the offline shell, so a member who
  // opens the app with no connection lands on what they've saved instead of
  // the OS's own offline error page.
  //
  // The shell is served *at the URL that was asked for* — the address bar
  // still says /categories/hymnals — so it can read its own location and
  // open the hymnals, rather than a generic list, when that is the icon
  // that was tapped.
  if (event.request.mode === "navigate") {
    event.respondWith(fetch(event.request).catch(() => caches.match("/offline.html")));
  }
});

self.addEventListener("push", (event) => {
  if (!event.data) return;
  let payload = { title: "Marine Team", body: "" };
  try {
    payload = event.data.json();
  } catch {
    payload.body = event.data.text();
  }
  event.waitUntil(
    self.registration.showNotification(payload.title, {
      body: payload.body,
      icon: "/icon-192.png",
      badge: "/icon-192.png",
      data: { url: payload.url || "/" },
    }),
  );
});

self.addEventListener("notificationclick", (event) => {
  event.notification.close();
  const url = event.notification.data?.url || "/";
  event.waitUntil(self.clients.openWindow(url));
});

I.2 — public/manifest.json

{
  "name": "Marine Team",
  "short_name": "Marine Team",
  "description": "Watch sermons, series, and downloads.",
  "start_url": "/",
  "display": "standalone",
  "background_color": "#0C4A6E",
  "theme_color": "#0C4A6E",
  "icons": [
    { "src": "/icon.svg", "sizes": "any", "type": "image/svg+xml", "purpose": "any" },
    { "src": "/icon-192.png", "sizes": "192x192", "type": "image/png", "purpose": "any" },
    { "src": "/icon-512.png", "sizes": "512x512", "type": "image/png", "purpose": "any" },
    { "src": "/icon-maskable-192.png", "sizes": "192x192", "type": "image/png", "purpose": "maskable" },
    { "src": "/icon-maskable-512.png", "sizes": "512x512", "type": "image/png", "purpose": "maskable" }
  ]
}

I.3 — public/offline.html

<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
<title>Offline — Marine Team</title>
<link rel="icon" href="/icon.svg" />
<script>
// Same key/logic as src/lib/device-settings.ts THEME_INIT_SCRIPT, kept in
// sync by hand since this file can't import from the app bundle — it has to
// stand alone so the service worker can serve it with zero network.
(function () {
  try {
    var raw = localStorage.getItem("marine-device-settings");
    var theme = raw ? (JSON.parse(raw) || {}).theme : "system";
    if (theme !== "light" && theme !== "dark") theme = "system";
    var dark = theme === "dark" || (theme === "system" && window.matchMedia("(prefers-color-scheme: dark)").matches);
    document.documentElement.classList.add(dark ? "dark" : "light");
  } catch (e) {}
})();
</script>
<style>
  :root { color-scheme: light dark; --sep: #e4e4e7; --sec: #71717a; --panel: #fff; --ink: #18181b; --chip: #f4f4f5; --accent: #0369a1; }
  html.dark { --sep: #27272a; --sec: #a1a1aa; --panel: #09090b; --ink: #fafafa; --chip: #18181b; --accent: #38bdf8; }
  * { box-sizing: border-box; }
  body {
    margin: 0;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
    background: var(--panel);
    color: var(--ink);
  }
  .wrap { max-width: 720px; margin: 0 auto; padding: 20px 16px calc(96px + env(safe-area-inset-bottom)); }
  .banner {
    display: flex; align-items: center; gap: 10px;
    border: 1px solid #fde68a; background: #fffbeb; color: #92400e;
    border-radius: 8px; padding: 10px 14px; font-size: 14px; margin-bottom: 20px;
  }
  html.dark .banner { border-color: #78350f; background: rgba(120,53,15,0.25); color: #fbbf24; }
  h1 { font-size: 20px; margin: 0 0 4px; }
  h2 { font-size: 15px; margin: 24px 0 8px; }
  .sub { color: var(--sec); font-size: 14px; margin: 0 0 16px; }
  ul { list-style: none; margin: 0; padding: 0; border: 1px solid var(--sep); border-radius: 10px; overflow: hidden; }
  li { display: flex; gap: 10px; align-items: center; justify-content: space-between; padding: 12px 14px; border-bottom: 1px solid var(--sep); }
  li:last-child { border-bottom: none; }
  .meta { min-width: 0; }
  .title { font-weight: 500; font-size: 14px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .detail { font-size: 12px; color: var(--sec); margin-top: 2px; }
  .actions { display: flex; gap: 6px; flex-shrink: 0; }
  button, .btn {
    font: inherit; font-size: 13px; cursor: pointer; border-radius: 6px; text-decoration: none;
    border: 1px solid var(--sep); background: var(--panel); color: inherit; padding: 6px 10px; display: inline-block;
  }
  button:hover, .btn:hover { background: var(--chip); }
  button.primary { border-color: var(--accent); color: var(--accent); }
  button.remove { color: #b91c1c; border-color: #fecaca; }
  html.dark button.remove { color: #f87171; border-color: #7f1d1d; }
  button[disabled] { opacity: 0.4; cursor: default; }
  .empty { font-size: 14px; color: var(--sec); padding: 20px 4px; }
  .rowlink { display: flex; width: 100%; gap: 10px; align-items: center; text-align: left; border: none; background: none; padding: 0; cursor: pointer; color: inherit; font: inherit; }
  .pagenum { width: 34px; flex-shrink: 0; text-align: right; font-variant-numeric: tabular-nums; color: var(--sec); font-size: 13px; }
  #player { display: none; margin-bottom: 20px; }
  #player video { width: 100%; border-radius: 10px; background: #000; display: block; }
  #player .now-playing { font-size: 14px; margin: 8px 0 0; color: var(--sec); }

  /* Reader */
  .reader-bar { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-bottom: 10px; font-size: 13px; }
  .reader-bar .spacer { flex: 1; }
  #canvas-wrap { background: var(--chip); border-radius: 10px; padding: 10px; overflow: auto; touch-action: pan-y; }
  #canvas-wrap canvas { display: block; margin: 0 auto; max-width: 100%; box-shadow: 0 2px 12px rgba(0,0,0,0.18); }
  .hint { font-size: 12px; color: var(--sec); margin-top: 8px; }
  #epub-view { height: 70vh; background: #fff; border: 1px solid var(--sep); border-radius: 10px; overflow: hidden; }
  .lyrics { white-space: pre-wrap; line-height: 1.6; font-size: 15px; border: 1px solid var(--sep); border-radius: 10px; padding: 16px; }
  .hymn-nav { display: flex; gap: 10px; align-items: center; margin-top: 14px; font-size: 13px; }
  .size-controls { display: flex; gap: 6px; justify-content: flex-end; margin-bottom: 8px; }
  .size-controls button { padding: 4px 10px; font-size: 13px; }
  .hymn-nav .spacer { flex: 1; }
  .group { font-size: 11px; text-transform: uppercase; letter-spacing: 0.06em; color: var(--sec); padding: 8px 14px; background: var(--chip); border-bottom: 1px solid var(--sep); }

  /* The same tab bar the app draws, from the snapshot it left behind. */
  #tabbar {
    position: fixed; inset: auto 0 0 0; display: flex; z-index: 30;
    border-top: 1px solid var(--sep); background: var(--panel);
    padding-bottom: env(safe-area-inset-bottom);
  }
  /* Past five icons the row scrolls sideways rather than squeezing — the
     same rule the app's own bar follows (see src/lib/nav-tabs.ts). */
  #tabbar.scrolls { overflow-x: auto; scrollbar-width: none; }
  #tabbar.scrolls::-webkit-scrollbar { display: none; }
  #tabbar a {
    flex: 1; display: flex; flex-direction: column; align-items: center; gap: 4px;
    padding: 8px 2px; font-size: 11px; color: var(--sec); text-decoration: none; min-width: 0;
  }
  #tabbar.scrolls a { flex: 0 0 4.75rem; }
  #tabbar a.active { color: var(--accent); font-weight: 500; }
  #tabbar a.saved { color: var(--ink); }
  #tabbar span { max-width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  #tabbar svg { width: 24px; height: 24px; }
</style>
</head>
<body>
<div class="wrap">
  <div class="banner">You're offline. Here's what's saved on this device.</div>
  <div id="view"></div>
  <div id="reconnect" style="margin-top:28px">
    <button id="retry">Try Marine Team again</button>
    <span id="retry-status" class="detail"></span>
  </div>
</div>
<nav id="tabbar" aria-label="Primary"></nav>

<script>
/*
 * The offline shell.
 *
 * A static page the service worker serves whenever a navigation can't reach
 * the network — including the installed app's own cold start. It has no
 * bundle, no server and no session, so everything here is read out of the
 * same localStorage indexes and Cache Storage entries the app writes:
 *
 *   marine-nav-tabs        the bottom bar the app last drew (lib/nav-tabs.ts)
 *   marine-offline-books   books saved to this device (lib/offline-books.ts)
 *   marine-downloads-index videos saved to this device (lib/offline-downloads.ts)
 *   marine-offline-services service orders saved here (lib/offline-services.ts)
 *   marine-offline-calendar the rota calendar (lib/offline-calendar.ts)
 *   marine-device-settings this device's settings (lib/device-settings.ts)
 *   marine-toc-v1:<id>     a book's contents list (lib/reader-cache.ts)
 *
 * Those constants are literals rather than imports for the same reason the
 * theme script above is a copy: this file has to work standing completely
 * alone. Keep them in step with the modules named beside them.
 *
 * It is served at whatever URL was asked for, so location.pathname is the
 * icon that was tapped — which is how tapping Hymnals with no connection
 * opens the hymnals rather than a generic list.
 */
const VIDEO_INDEX_KEY = "marine-downloads-index";
const DOWNLOAD_CACHE = "marine-team-downloads-v1";
const BOOK_INDEX_KEY = "marine-offline-books";
const BOOK_CACHE = "marine-team-books-v1";
const SERVICE_INDEX_KEY = "marine-offline-services";
const SERVICE_CACHE = "marine-team-services-v1";
const CALENDAR_INDEX_KEY = "marine-offline-calendar";
const CALENDAR_CACHE = "marine-team-calendar-v1";
const TABS_KEY = "marine-nav-tabs";
const SETTINGS_KEY = "marine-device-settings";
// The same bounds as MIN_READING_SCALE / MAX_READING_SCALE in
// lib/device-settings.ts. Clamped here too, because this page writes the
// setting as well as reading it, and a value it stored outside the range
// would come back to the app as one nobody chose.
const MIN_READING_SCALE = 0.75;
const MAX_READING_SCALE = 2;
const READING_SCALE_STEP = 0.1;
const TOC_PREFIX = "marine-toc-v1:";
const PDFJS_URL = "/pdfjs/pdf.min.mjs";
const PDFJS_WORKER_URL = "/pdfjs/pdf.worker.min.mjs";
// epub.js's dist build is UMD and expects JSZip as a global, so the two load
// in this order or neither works.
const JSZIP_URL = "/epubjs/jszip.min.js";
const EPUBJS_URL = "/epubjs/epub.min.js";

// Copied from src/components/icons.tsx — same 24-unit box, same 1.75 stroke —
// so the bar offline is the bar the app draws. Keep in sync by hand.
const ICONS = {
  home: '<path d="M3 11.5 12 4l9 7.5"/><path d="M5.5 9.5V19a1 1 0 0 0 1 1H9a1 1 0 0 0 1-1v-4a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v4a1 1 0 0 0 1 1h2.5a1 1 0 0 0 1-1V9.5"/>',
  search: '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.9-3.9"/>',
  clock: '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>',
  star: '<path d="M12 4.5l2.4 4.9 5.4.8-3.9 3.8.9 5.4-4.8-2.5-4.8 2.5.9-5.4-3.9-3.8 5.4-.8L12 4.5z"/>',
  sparkle: '<path d="M11 4l1.2 3.8L16 9l-3.8 1.2L11 14l-1.2-3.8L6 9l3.8-1.2L11 4z"/><path d="M17.5 14.5l.7 2.1 2.1.7-2.1.7-.7 2.1-.7-2.1-2.1-.7 2.1-.7.7-2.1z"/>',
  bell: '<path d="M18 8.5a6 6 0 1 0-12 0c0 6-2.5 8-2.5 8h17s-2.5-2-2.5-8"/><path d="M13.7 20.5a2 2 0 0 1-3.4 0"/>',
  folder: '<path d="M3.5 7.5a2 2 0 0 1 2-2h3.2a2 2 0 0 1 1.5.7l1 1.3h7.3a2 2 0 0 1 2 2v7a2 2 0 0 1-2 2h-13a2 2 0 0 1-2-2z"/>',
  playlist: '<path d="M4 7h11M4 12h11M4 17h7"/><path d="m17 12.5 4 2.5-4 2.5z"/>',
  book: '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 3.5H20v17H6.5A2.5 2.5 0 0 1 4 18V6a2.5 2.5 0 0 1 2.5-2.5z"/>',
  live: '<circle cx="12" cy="12" r="2.5"/><path d="M8.2 15.8a5.4 5.4 0 0 1 0-7.6M15.8 8.2a5.4 5.4 0 0 1 0 7.6"/><path d="M5.5 18.5a9.2 9.2 0 0 1 0-13M18.5 5.5a9.2 9.2 0 0 1 0 13"/>',
  person: '<circle cx="12" cy="8.5" r="3.5"/><path d="M5 20c0-3.3 3.1-6 7-6s7 2.7 7 6"/>',
  calendar: '<rect x="3.5" y="5.5" width="17" height="15" rx="2"/><path d="M3.5 10h17M8 3.5v4M16 3.5v4"/>',
  ticket: '<path d="M3.5 8.5V6.5a1 1 0 0 1 1-1h15a1 1 0 0 1 1 1v2a2.5 2.5 0 0 0 0 7v2a1 1 0 0 1-1 1h-15a1 1 0 0 1-1-1v-2a2.5 2.5 0 0 0 0-7z"/><path d="M14 5.5v13"/>',
  card: '<rect x="3.5" y="5" width="17" height="14" rx="2"/><path d="M7.5 10h5M7.5 14h9"/>',
  hands: '<path d="M12 20.5c-3 0-5.5-2-6.5-4.5L4 12a1.5 1.5 0 0 1 2.6-1.4L8 12V5a1.5 1.5 0 0 1 3 0v5"/><path d="M12 20.5c3 0 5.5-2 6.5-4.5L20 12a1.5 1.5 0 0 0-2.6-1.4L16 12V5a1.5 1.5 0 0 0-3 0v5"/>',
  people: '<circle cx="9" cy="8" r="3.25"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M16 5.5a3.25 3.25 0 0 1 0 6.5M17 14.5a6.5 6.5 0 0 1 4.5 5.5"/>',
  tv: '<rect x="2.5" y="4.5" width="19" height="13" rx="2"/><path d="M8 20.5h8"/>',
  download: '<path d="M12 4v10"/><path d="m8 10.5 4 4 4-4"/><path d="M4.5 18.5h15"/>',
  shield: '<path d="M12 3.5 5 6v5.5c0 4.3 2.9 7.6 7 9 4.1-1.4 7-4.7 7-9V6z"/>',
};

function readJson(key, fallback) {
  try {
    const parsed = JSON.parse(localStorage.getItem(key) || "null");
    return parsed === null ? fallback : parsed;
  } catch (e) {
    return fallback;
  }
}

function readBooks() {
  const items = readJson(BOOK_INDEX_KEY, []);
  if (!Array.isArray(items)) return [];
  return items
    .filter((item) => item && item.id && item.cacheUrl)
    // Anything that isn't a hymnal is a document, whatever an older version
    // of the app called it, and a document with no format recorded is a PDF.
    .map((item) =>
      Object.assign({}, item, {
        kind: item.kind === "hymnal" ? "hymnal" : "file",
        format: item.format === "epub" ? "epub" : "pdf",
      }),
    );
}

function readVideos() {
  const items = readJson(VIDEO_INDEX_KEY, []);
  return Array.isArray(items) ? items.filter((item) => item && item.videoId && item.cacheUrl) : [];
}

function writeVideos(items) {
  try {
    localStorage.setItem(VIDEO_INDEX_KEY, JSON.stringify(items));
  } catch (e) {}
}

function writeBooks(items) {
  try {
    localStorage.setItem(BOOK_INDEX_KEY, JSON.stringify(items));
  } catch (e) {}
}

function readServices() {
  const items = readJson(SERVICE_INDEX_KEY, []);
  return Array.isArray(items) ? items.filter((item) => item && item.id && item.cacheUrl) : [];
}

function writeServices(items) {
  try {
    localStorage.setItem(SERVICE_INDEX_KEY, JSON.stringify(items));
  } catch (e) {}
}

/** There is one calendar, so its index is one entry rather than a list. */
function readCalendar() {
  const entry = readJson(CALENDAR_INDEX_KEY, null);
  return entry && entry.cacheUrl ? entry : null;
}

function writeCalendar(entry) {
  try {
    if (entry) localStorage.setItem(CALENDAR_INDEX_KEY, JSON.stringify(entry));
    else localStorage.removeItem(CALENDAR_INDEX_KEY);
  } catch (e) {}
}

/**
 * Whose calendar this device shows, shared with the app.
 *
 * The same setting under the same key as CalendarView writes
 * (lib/device-settings.ts), so a name picked here with no signal is the name
 * the app agrees on the moment there is one — and the other way round.
 */
function calendarPerson() {
  const raw = readJson(SETTINGS_KEY, null);
  return raw && typeof raw.calendarPersonId === "string" ? raw.calendarPersonId : null;
}

function setCalendarPerson(id) {
  try {
    // Merged, like the reading size: this page must never be the reason
    // somebody's theme or tab bar goes back to the default.
    const current = readJson(SETTINGS_KEY, {}) || {};
    current.calendarPersonId = id;
    localStorage.setItem(SETTINGS_KEY, JSON.stringify(current));
  } catch (e) {}
}

/**
 * The reading text size this device chose, shared with the app.
 *
 * Offline is where the size matters most — a pew, a dim hall, no connection
 * to go and change a setting from — so this both reads it and writes it,
 * rather than only honouring what the app was last told.
 */
function readingScale() {
  var raw = readJson(SETTINGS_KEY, null);
  var value = raw && typeof raw.readingTextScale === "number" ? raw.readingTextScale : 1;
  if (!isFinite(value)) return 1;
  return Math.min(MAX_READING_SCALE, Math.max(MIN_READING_SCALE, value));
}

function setReadingScale(next) {
  var clamped = Math.min(MAX_READING_SCALE, Math.max(MIN_READING_SCALE, next));
  var rounded = Math.round(clamped * 100) / 100;
  try {
    // Merged into whatever else is stored: this page must never be the reason
    // somebody's theme or tab bar goes back to the default.
    var current = readJson(SETTINGS_KEY, {}) || {};
    current.readingTextScale = rounded;
    localStorage.setItem(SETTINGS_KEY, JSON.stringify(current));
  } catch (e) {}
  return rounded;
}

/** The day a service is for, as a person reads it. */
function formatServiceDate(iso) {
  if (!iso) return "";
  const date = new Date(iso);
  if (isNaN(date.getTime())) return "";
  return date.toLocaleDateString(undefined, {
    weekday: "long",
    day: "numeric",
    month: "long",
  });
}

/** Today, as the app's own todayIso() sees it: this device's local day. */
function todayIso() {
  const now = new Date();
  return (
    now.getFullYear() +
    "-" +
    String(now.getMonth() + 1).padStart(2, "0") +
    "-" +
    String(now.getDate()).padStart(2, "0")
  );
}

/**
 * "Today", "Tomorrow", "Sunday", or a date — the same wording as
 * relativeDayLabel in src/lib/dates.ts, so a rota reads the same whether the
 * app drew it or this page did. Self-contained on purpose: it is lifted out
 * whole and run against the app's version in the drift test.
 */
function relativeDay(value, today) {
  const day = new Date(value + "T00:00:00Z");
  const now = new Date(today + "T00:00:00Z");
  if (isNaN(day.getTime()) || isNaN(now.getTime())) return "";
  const difference = Math.round((day.getTime() - now.getTime()) / 86400000);
  if (difference === 0) return "Today";
  if (difference === 1) return "Tomorrow";
  if (difference === -1) return "Yesterday";
  const weekdays = ["Sunday", "Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday"];
  const months = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];
  if (difference > 1 && difference < 7) return weekdays[day.getUTCDay()];
  const label =
    weekdays[day.getUTCDay()].slice(0, 3) + ", " + months[day.getUTCMonth()] + " " + day.getUTCDate();
  return value.slice(0, 4) !== today.slice(0, 4) ? label + " " + day.getUTCFullYear() : label;
}

/** How long ago the calendar last caught up with the server. */
function formatSyncedAt(iso) {
  const at = new Date(iso);
  if (isNaN(at.getTime())) return "";
  const days = Math.floor((Date.now() - at.getTime()) / 86400000);
  if (days <= 0) return "today";
  if (days === 1) return "yesterday";
  if (days < 30) return days + " days ago";
  return "on " + at.toLocaleDateString(undefined, { day: "numeric", month: "long" });
}

/**
 * A book's contents, as the app resolved them when the book was saved. The
 * tag check is the same one lib/reader-cache.ts makes: a list read from a
 * different version of the file describes a different book.
 */
function readToc(book) {
  const stored = readJson(TOC_PREFIX + book.id, null);
  if (!stored || stored.tag !== String(book.sizeBytes == null ? 0 : book.sizeBytes)) return null;
  return Array.isArray(stored.entries) ? stored.entries : null;
}

/**
 * The hymn number a contents entry announces — the number on the board, not
 * the page it is on. Same rule as hymnNumberOf in src/lib/toc-nav.ts: only a
 * number at the start counts, with the words a hymnal's bookmarks put in
 * front of one. Kept in step by hand, like everything else in this file.
 */
function hymnNumberOf(label) {
  const match = /^\s*(?:hymn|hymn\s+no\.?|no\.?|#)?\s*(\d{1,4})(?![\d])/i.exec(label || "");
  if (!match) return null;
  const number = Number(match[1]);
  return number >= 1 ? number : null;
}

function formatBytes(bytes) {
  if (!bytes) return "";
  if (bytes < 1024 * 1024) return Math.max(1, Math.round(bytes / 1024)) + " KB";
  if (bytes < 1024 * 1024 * 1024) return Math.round(bytes / (1024 * 1024)) + " MB";
  return (bytes / (1024 * 1024 * 1024)).toFixed(1) + " GB";
}

function formatDuration(seconds) {
  if (!seconds) return "";
  const m = Math.floor(seconds / 60);
  const s = Math.floor(seconds % 60);
  return m + ":" + (s < 10 ? "0" : "") + s;
}

function el(tag, className, text) {
  const node = document.createElement(tag);
  if (className) node.className = className;
  if (text !== undefined) node.textContent = text;
  return node;
}

function icon(name) {
  const svg = document.createElementNS("http://www.w3.org/2000/svg", "svg");
  svg.setAttribute("viewBox", "0 0 24 24");
  svg.setAttribute("fill", "none");
  svg.setAttribute("stroke", "currentColor");
  svg.setAttribute("stroke-width", "1.75");
  svg.setAttribute("stroke-linecap", "round");
  svg.setAttribute("stroke-linejoin", "round");
  svg.setAttribute("aria-hidden", "true");
  svg.innerHTML = ICONS[name] || ICONS.folder;
  return svg;
}

const view = document.getElementById("view");
const scopePath = location.pathname === "/offline.html" ? null : location.pathname;

/**
 * The books a tap on this icon should show: the ones saved from that exact
 * place, and the ones saved from a series inside it — a hymnal filed under a
 * series still belongs to the Hymnals icon.
 */
function booksIn(path) {
  if (!path || path === "/") return [];
  return readBooks().filter((book) => book.homeHref === path || book.categoryHref === path);
}

function labelFor(book, path) {
  return book.homeHref === path ? book.homeLabel : book.categoryLabel || book.homeLabel;
}

// ---------------------------------------------------------------- the bar --
function renderTabs() {
  const bar = document.getElementById("tabbar");
  const tabs = readJson(TABS_KEY, []);
  bar.innerHTML = "";
  if (!Array.isArray(tabs) || tabs.length === 0) {
    bar.style.display = "none";
    return;
  }
  // TABS_ACROSS in src/lib/nav-tabs.ts.
  bar.className = tabs.length > 5 ? "scrolls" : "";
  tabs.forEach((tab) => {
    if (!tab || typeof tab.href !== "string") return;
    const link = el("a");
    link.href = tab.href;
    // Plain links on purpose: with no connection the service worker answers
    // with this same page (which then reads the new path), and the moment the
    // connection is back the very same tap lands in the real app.
    const saved = booksIn(tab.href).length > 0;
    link.className = (tab.href === scopePath ? "active" : "") + (saved ? " saved" : "");
    if (saved) link.title = "Saved on this device";
    link.appendChild(icon(tab.icon));
    link.appendChild(el("span", null, tab.label || ""));
    bar.appendChild(link);
  });
  const active = bar.querySelector("a.active");
  if (active) active.scrollIntoView({ block: "nearest", inline: "nearest" });
}

// ------------------------------------------------------------ the library --
function renderLibrary() {
  teardownReader();
  view.innerHTML = "";
  const scoped = booksIn(scopePath);
  const books = scoped.length > 0 ? scoped : readBooks();
  const videos = readVideos();
  const plans = readServices();
  const calendar = readCalendar();
  const scopeLabel = scoped.length > 0 ? labelFor(scoped[0], scopePath) : null;

  view.appendChild(el("h1", null, scopeLabel || "Saved on this device"));
  view.appendChild(
    el(
      "p",
      "sub",
      books.length > 0 || videos.length > 0 || plans.length > 0 || calendar
        ? "These open with no connection at all."
        : "Nothing is saved here yet.",
    ),
  );

  if (books.length > 0) {
    view.appendChild(el("h2", null, books.length === 1 ? "Book" : "Books"));
    const list = el("ul");
    books.forEach((book) => list.appendChild(bookRow(book)));
    view.appendChild(list);
  }

  if (plans.length > 0) {
    view.appendChild(el("h2", null, plans.length === 1 ? "Service" : "Services"));
    const list = el("ul");
    plans.forEach((plan) => list.appendChild(serviceRow(plan)));
    view.appendChild(list);
  }

  if (calendar) {
    view.appendChild(el("h2", null, "Calendar"));
    const list = el("ul");
    list.appendChild(calendarRow(calendar));
    view.appendChild(list);
  }

  if (videos.length > 0) {
    view.appendChild(el("h2", null, "Videos"));
    view.appendChild(playerNode());
    const list = el("ul");
    videos.forEach((video) => list.appendChild(videoRow(video)));
    view.appendChild(list);
  }

  if (books.length === 0 && videos.length === 0 && plans.length === 0 && !calendar) {
    view.appendChild(
      el(
        "p",
        "empty",
        "Connect, then use “Save for offline” on a book, “Keep this order offline” on a service, “Keep the calendar on this device” on the calendar, or the download button on a video, to keep it here.",
      ),
    );
  } else if (scoped.length > 0 && readBooks().length > scoped.length) {
    const all = el("button", null, "Show everything saved");
    all.addEventListener("click", () => {
      window.location.href = "/offline.html";
    });
    const row = el("p");
    row.appendChild(all);
    view.appendChild(row);
  }
}

function bookRow(book) {
  const li = el("li");
  const open = el("button", "rowlink");
  const meta = el("div", "meta");
  meta.appendChild(el("div", "title", book.title || "Untitled book"));
  const bits = [];
  if (book.kind === "hymnal") {
    if (book.hymnCount) bits.push(book.hymnCount + (book.hymnCount === 1 ? " hymn" : " hymns"));
  } else {
    const toc = readToc(book);
    if (toc) bits.push(toc.length + (toc.length === 1 ? " entry" : " entries"));
  }
  if (book.bytes) bits.push(formatBytes(book.bytes));
  meta.appendChild(el("div", "detail", bits.join(" · ")));
  open.appendChild(meta);
  open.addEventListener("click", () => {
    if (book.kind === "hymnal") renderHymnal(book);
    else renderBook(book);
  });

  const remove = el("button", "remove", "Remove");
  remove.addEventListener("click", async () => {
    if (!window.confirm('Remove "' + (book.title || "this book") + '" from this device?')) return;
    try {
      const cache = await caches.open(BOOK_CACHE);
      await cache.delete(book.cacheUrl);
    } catch (e) {}
    writeBooks(readBooks().filter((item) => item.id !== book.id));
    renderTabs();
    renderLibrary();
  });

  li.appendChild(open);
  const actions = el("div", "actions");
  actions.appendChild(remove);
  li.appendChild(actions);
  return li;
}

// -------------------------------------------------------- one service --
function serviceRow(plan) {
  const li = el("li");
  const open = el("button", "rowlink");
  const meta = el("div", "meta");
  meta.appendChild(el("div", "title", plan.title || "Service"));
  const bits = [];
  const day = formatServiceDate(plan.serviceDate);
  if (day) bits.push(day);
  if (plan.itemCount) bits.push(plan.itemCount + (plan.itemCount === 1 ? " hymn" : " hymns"));
  meta.appendChild(el("div", "detail", bits.join(" · ")));
  open.appendChild(meta);
  open.addEventListener("click", () => renderService(plan));

  const remove = el("button", "remove", "Remove");
  remove.addEventListener("click", async () => {
    if (!window.confirm('Remove "' + (plan.title || "this service") + '" from this device?')) return;
    try {
      const cache = await caches.open(SERVICE_CACHE);
      await cache.delete(plan.cacheUrl);
    } catch (e) {}
    writeServices(readServices().filter((item) => item.id !== plan.id));
    renderLibrary();
  });

  li.appendChild(open);
  const actions = el("div", "actions");
  actions.appendChild(remove);
  li.appendChild(actions);
  return li;
}

/**
 * The running order, and as much of each hymn as this device actually holds.
 *
 * The plan is two kilobytes and the books are forty megabytes, so the common
 * case is a device that has the order but not everything in it. Each row says
 * which it is rather than offering a button that would do nothing.
 */
async function renderService(plan) {
  teardownReader();
  view.innerHTML = "";
  const back = el("button", null, "← Back");
  back.addEventListener("click", renderLibrary);
  view.appendChild(back);

  view.appendChild(el("h1", null, plan.title || "Service"));
  const day = formatServiceDate(plan.serviceDate);
  if (day) view.appendChild(el("p", "sub", day));

  let saved;
  try {
    saved = await (await fetch(plan.cacheUrl)).json();
  } catch (e) {
    // The browser evicted it under storage pressure; the index still lists it.
    view.appendChild(el("p", "empty", "This service's order is no longer on this device."));
    return;
  }

  if (saved.notes) view.appendChild(el("p", "sub", saved.notes));

  const items = Array.isArray(saved.items) ? saved.items : [];
  if (items.length === 0) {
    view.appendChild(el("p", "empty", "No hymns were listed for this service."));
    return;
  }

  const books = readBooks();
  const list = el("ul");
  list.style.marginTop = "12px";

  items.forEach((item) => {
    const li = el("li");
    const row = el("button", "rowlink");
    const meta = el("div", "meta");
    const heading = el("div", "title");
    // The number on the board leads the row, the way it does on the sheet.
    heading.textContent = (item.number ? item.number + ". " : "") + (item.title || "Hymn");
    meta.appendChild(heading);

    // A hymn inside a whole book: the book has to be on the device, and its
    // contents have to have been saved with it, or there is no page to open.
    const book = item.hymnNumber !== null && item.hymnNumber !== undefined
      ? books.find((candidate) => candidate.id === item.fileId && candidate.kind === "file")
      : null;
    const toc = book ? readToc(book) : null;
    const entry =
      toc && item.hymnNumber
        ? toc.find((candidate) => hymnNumberOf(candidate.label) === item.hymnNumber && candidate.location !== null)
        : null;

    const bits = [];
    if (item.note) bits.push(item.note);
    if (item.hymnNumber !== null && item.hymnNumber !== undefined) {
      if (!book) bits.push("Book not on this device");
      else if (!entry) bits.push("Saved, but its contents weren't");
    }
    if (bits.length > 0) meta.appendChild(el("div", "detail", bits.join(" · ")));
    row.appendChild(meta);

    if (entry && book) {
      row.addEventListener("click", () => openBook(book, entry.location, toc));
    } else {
      // Nothing to open: a hymn of its own is a lyrics page this shell has no
      // copy of, and a book that isn't here can't be opened by wanting it.
      row.disabled = true;
    }

    li.appendChild(row);
    list.appendChild(li);
  });

  view.appendChild(list);
}

// ------------------------------------------------------- the calendar --
function calendarRow(entry) {
  const li = el("li");
  const open = el("button", "rowlink");
  const meta = el("div", "meta");
  meta.appendChild(el("div", "title", "Calendar"));
  const bits = [];
  if (entry.eventCount) bits.push(entry.eventCount + (entry.eventCount === 1 ? " date" : " dates"));
  const when = formatSyncedAt(entry.syncedAt);
  if (when) bits.push("updated " + when);
  meta.appendChild(el("div", "detail", bits.join(" · ")));
  open.appendChild(meta);
  open.addEventListener("click", () => renderCalendar(entry));

  const remove = el("button", "remove", "Remove");
  remove.addEventListener("click", async () => {
    if (!window.confirm("Remove the calendar from this device?")) return;
    try {
      const cache = await caches.open(CALENDAR_CACHE);
      await cache.delete(entry.cacheUrl);
    } catch (e) {}
    writeCalendar(null);
    renderLibrary();
  });

  li.appendChild(open);
  const actions = el("div", "actions");
  actions.appendChild(remove);
  li.appendChild(actions);
  return li;
}

/**
 * What you are on for, with no connection.
 *
 * The calendar is the one saved thing that is a few kilobytes of text, so
 * unlike a book there is never a question of whether enough of it is here —
 * the whole year is. The only question is whose it is, and that is answered
 * the same way the app answers it: pick a name once, stored against this
 * device, changeable right here because standing in a hall with no signal is
 * exactly when somebody first wants to.
 */
async function renderCalendar(entry) {
  teardownReader();
  view.innerHTML = "";
  const back = el("button", null, "← Back");
  back.addEventListener("click", renderLibrary);
  view.appendChild(back);
  view.appendChild(el("h1", null, "Calendar"));

  let saved;
  try {
    saved = await (await fetch(entry.cacheUrl)).json();
  } catch (e) {
    // The browser evicted it under storage pressure; the index still lists it.
    view.appendChild(el("p", "empty", "The calendar is no longer on this device."));
    return;
  }

  const people = Array.isArray(saved.people) ? saved.people : [];
  const events = Array.isArray(saved.events) ? saved.events : [];
  const scheduleNames = {};
  (Array.isArray(saved.schedules) ? saved.schedules : []).forEach((schedule) => {
    scheduleNames[schedule.id] = schedule.name;
  });

  const chooser = el("p", "sub");
  chooser.appendChild(el("span", null, "You are "));
  const select = document.createElement("select");
  select.setAttribute("aria-label", "Your name");
  select.style.cssText = "font:inherit;padding:2px 4px";
  const everyone = el("option", null, "Everyone");
  everyone.value = "";
  select.appendChild(everyone);
  people.forEach((person) => {
    const option = el("option", null, person.displayName);
    option.value = person.id;
    select.appendChild(option);
  });
  select.value = calendarPerson() || "";
  select.addEventListener("change", () => {
    setCalendarPerson(select.value || null);
    draw();
  });
  chooser.appendChild(select);
  view.appendChild(chooser);

  const list = el("ul");
  view.appendChild(list);

  function draw() {
    list.innerHTML = "";
    const today = todayIso();
    const personId = select.value || null;
    const mine = events
      .filter((item) => {
        if (item.status === "CANCELLED") return false;
        if (personId && !(item.people || []).some((who) => who.personId === personId)) return false;
        // A multi-day event is still on while it runs.
        return (item.endDate || item.date) >= today;
      })
      .sort((a, b) =>
        a.date === b.date
          ? (a.startTime || "") < (b.startTime || "")
            ? -1
            : 1
          : a.date < b.date
            ? -1
            : 1,
      )
      .slice(0, 40);

    if (mine.length === 0) {
      const name = people.find((person) => person.id === personId);
      list.appendChild(
        el("p", "empty", name ? "Nothing coming up for " + name.displayName + "." : "Nothing coming up."),
      );
      return;
    }

    mine.forEach((item) => {
      const li = el("li");
      // Plain rows rather than the buttons the other lists use: a date is a
      // date, there is nothing behind it to open, and a list of buttons that
      // all refuse to be pressed reads as a list of things gone wrong.
      const meta = el("div", "meta");
      const names = (item.people || []).map((who) => who.displayName).join(", ");
      meta.appendChild(
        el("div", "title", names || item.title || scheduleNames[item.scheduleId] || "Scheduled"),
      );
      const bits = [scheduleNames[item.scheduleId], relativeDay(item.date, today)];
      if (item.startTime) bits.push(item.startTime);
      if (item.location) bits.push(item.location);
      if (item.notes) bits.push(item.notes);
      meta.appendChild(el("div", "detail", bits.filter(Boolean).join(" · ")));
      li.appendChild(meta);
      list.appendChild(li);
    });
  }

  draw();
}

// ----------------------------------------------------------- one book --
function renderBook(book) {
  teardownReader();
  view.innerHTML = "";
  const back = el("button", null, "← Back");
  back.addEventListener("click", renderLibrary);
  view.appendChild(back);

  view.appendChild(el("h1", null, book.title || "Untitled book"));
  const toc = readToc(book);
  view.appendChild(
    el(
      "p",
      "sub",
      toc && toc.length > 0
        ? "Tap an entry to open the book there."
        : "This book's contents weren't saved with it — it opens at the first page.",
    ),
  );

  const openFirst = el("button", "primary", "Open the book");
  openFirst.addEventListener("click", () => openBook(book, null, toc));
  view.appendChild(openFirst);

  // Typing the number on the board is the fastest way into a hymnal, and
  // never more so than with no connection to fall back on.
  const numbered = (toc || []).filter((entry) => hymnNumberOf(entry.label) !== null && entry.location !== null);
  if (numbered.length > 1) {
    const form = document.createElement("form");
    form.style.cssText = "display:flex;gap:8px;align-items:center;margin-top:12px;font-size:14px";
    const label = el("label", null, "Go to hymn");
    label.setAttribute("for", "offline-hymn-number");
    const input = document.createElement("input");
    input.id = "offline-hymn-number";
    input.inputMode = "numeric";
    input.pattern = "[0-9]*";
    input.setAttribute("aria-label", "Hymn number");
    input.style.cssText =
      "width:5rem;padding:6px 8px;border:1px solid var(--sep);border-radius:6px;background:var(--panel);color:inherit;font:inherit;text-align:center";
    const go = el("button", null, "Open");
    go.type = "submit";
    const miss = el("span", "detail");
    form.appendChild(label);
    form.appendChild(input);
    form.appendChild(go);
    form.appendChild(miss);
    form.addEventListener("submit", (event) => {
      event.preventDefault();
      const wanted = Number(input.value.trim());
      const entry = numbered.find((item) => hymnNumberOf(item.label) === wanted);
      if (!entry) {
        miss.textContent = wanted ? "No hymn " + wanted + " in this book." : "";
        return;
      }
      openBook(book, entry.location, toc);
    });
    input.addEventListener("input", () => {
      miss.textContent = "";
    });
    view.appendChild(form);
  }

  if (toc && toc.length > 0) {
    const list = el("ul");
    list.style.marginTop = "12px";
    toc.forEach((entry) => {
      const li = el("li");
      const row = el("button", "rowlink");
      // A PDF's contents entry is a page, and the number shown is the one
      // printed in the book — the same sum lib/page-offset.ts does, with
      // front matter showing none. An EPUB's is a chapter href, which is not
      // a number and has no column of its own.
      const printed =
        book.format === "epub" || entry.location === null
          ? null
          : Number(entry.location) - (book.pageOffset || 0);
      row.appendChild(el("span", "pagenum", printed !== null && printed >= 1 ? String(printed) : ""));
      row.appendChild(el("span", "title", entry.label || ""));
      if (entry.location === null) {
        row.disabled = true;
      } else {
        row.addEventListener("click", () => openBook(book, entry.location, toc));
      }
      li.appendChild(row);
      list.appendChild(li);
    });
    view.appendChild(list);
  }
}

// ------------------------------------------------- a hymn-per-file book --
/**
 * A book whose hymns are its files is saved as its list of hymns rather than
 * as a document (see lib/offline-books.ts), so reading it offline is reading
 * that list: the hymns in printed-page order, then one hymn's lyrics, with
 * the next and previous ones a tap away — the same stepping the app offers.
 */
async function loadHymnal(book) {
  const cache = await caches.open(BOOK_CACHE);
  const response = await cache.match(book.cacheUrl);
  if (!response) throw new Error("This hymnal is no longer stored on this device.");
  const data = await response.json();
  return Array.isArray(data.hymns) ? data.hymns : [];
}

async function renderHymnal(book) {
  teardownReader();
  view.innerHTML = "";
  view.appendChild(el("p", "sub", "Opening…"));

  let hymns;
  try {
    hymns = await loadHymnal(book);
  } catch (error) {
    writeBooks(readBooks().filter((item) => item.id !== book.id));
    view.innerHTML = "";
    view.appendChild(el("h1", null, book.title || "Untitled hymnal"));
    view.appendChild(
      el("p", "sub", "This hymnal is no longer stored on this device. Connect and save it again."),
    );
    const back = el("button", null, "← Back");
    back.addEventListener("click", renderLibrary);
    view.appendChild(back);
    renderTabs();
    return;
  }

  view.innerHTML = "";
  const back = el("button", null, "← Back");
  back.addEventListener("click", renderLibrary);
  view.appendChild(back);
  view.appendChild(el("h1", null, book.title || "Untitled hymnal"));
  view.appendChild(
    el("p", "sub", hymns.length + (hymns.length === 1 ? " hymn saved here." : " hymns saved here.")),
  );

  const search = document.createElement("input");
  search.type = "search";
  search.placeholder = "Find a hymn";
  search.setAttribute("aria-label", "Find a hymn");
  search.style.cssText =
    "width:100%;padding:8px 10px;border:1px solid var(--sep);border-radius:8px;background:var(--panel);color:inherit;font:inherit;font-size:14px;margin-bottom:10px";
  view.appendChild(search);

  const list = el("ul");
  view.appendChild(list);

  // By number and by title, because that is how someone looks for a hymn —
  // "412" from the board at the front, or a line of the first verse.
  function draw(query) {
    const needle = (query || "").trim().toLowerCase();
    const shown = !needle
      ? hymns
      : hymns.filter(
          (hymn) =>
            String(hymn.pageNumber || "").startsWith(needle) ||
            (hymn.title || "").toLowerCase().includes(needle) ||
            (hymn.lyricsText || "").toLowerCase().includes(needle),
        );
    list.innerHTML = "";
    if (shown.length === 0) {
      list.appendChild(el("li", null, "No hymn here matches that."));
      return;
    }
    let group = null;
    shown.forEach((hymn) => {
      if (!needle && hymn.groupLabel && hymn.groupLabel !== group) {
        group = hymn.groupLabel;
        const heading = el("li", "group", group);
        heading.style.display = "block";
        list.appendChild(heading);
      }
      const li = el("li");
      const row = el("button", "rowlink");
      row.appendChild(el("span", "pagenum", hymn.pageNumber == null ? "" : String(hymn.pageNumber)));
      row.appendChild(el("span", "title", hymn.title || ""));
      row.addEventListener("click", () => renderHymn(book, hymns, hymns.indexOf(hymn)));
      li.appendChild(row);
      list.appendChild(li);
    });
  }

  search.addEventListener("input", () => draw(search.value));
  draw("");
}

function renderHymn(book, hymns, index) {
  const hymn = hymns[index];
  if (!hymn) return;
  view.innerHTML = "";

  const back = el("button", null, "‹ " + (book.title || "Back"));
  back.addEventListener("click", () => renderHymnal(book));
  view.appendChild(back);

  const heading = el("h1", null, hymn.title || "");
  view.appendChild(heading);
  if (hymn.pageNumber != null) view.appendChild(el("p", "sub", "Page " + hymn.pageNumber));

  const words = el("div", "lyrics", hymn.lyricsText || "");
  // The same 15px base the app's lyrics use, times whatever this device set.
  const applySize = (scale) => {
    words.style.fontSize = 15 * scale + "px";
  };
  applySize(readingScale());

  const sizing = el("div", "size-controls");
  const smaller = el("button", null, "A−");
  const larger = el("button", null, "A+");
  smaller.setAttribute("aria-label", "Smaller text");
  larger.setAttribute("aria-label", "Larger text");
  const nudge = (by) => {
    applySize(setReadingScale(readingScale() + by));
  };
  smaller.addEventListener("click", () => nudge(-READING_SCALE_STEP));
  larger.addEventListener("click", () => nudge(READING_SCALE_STEP));
  sizing.appendChild(smaller);
  sizing.appendChild(larger);
  view.appendChild(sizing);

  view.appendChild(words);

  const nav = el("div", "hymn-nav");
  const previous = hymns[index - 1];
  const next = hymns[index + 1];
  const back1 = el("button", null, "‹ Back");
  back1.disabled = !previous;
  back1.title = previous ? previous.title : "";
  back1.addEventListener("click", () => renderHymn(book, hymns, index - 1));
  const forward = el("button", null, "Next ›");
  forward.disabled = !next;
  forward.title = next ? next.title : "";
  forward.addEventListener("click", () => renderHymn(book, hymns, index + 1));
  nav.appendChild(back1);
  nav.appendChild(el("span", "spacer"));
  nav.appendChild(forward);
  view.appendChild(nav);
  window.scrollTo({ top: 0 });
}

// --------------------------------------------------------------- reading --
let pdfjsPromise = null;
let epubjsPromise = null;
let activeReader = null;
// epub.js renders into an iframe it owns and holds an unzipped archive; both
// have to be let go of when the reader closes, or a few books in a session
// add up to real memory.
let activeEpub = null;

function teardownReader() {
  activeReader = null;
  if (activeEpub) {
    try {
      activeEpub.rendition.destroy();
      activeEpub.book.destroy();
    } catch (error) {
      // Already torn down.
    }
    activeEpub = null;
  }
}

window.addEventListener("keydown", (event) => {
  if (!activeReader || event.metaKey || event.ctrlKey || event.altKey) return;
  if (event.key === "ArrowRight") activeReader.go(1);
  else if (event.key === "ArrowLeft") activeReader.go(-1);
});
window.addEventListener("resize", () => {
  if (activeReader) activeReader.draw();
});

function loadScript(src) {
  return new Promise((resolve, reject) => {
    const tag = document.createElement("script");
    tag.src = src;
    tag.onload = () => resolve();
    tag.onerror = () => reject(new Error("Couldn't load " + src));
    document.head.appendChild(tag);
  });
}

function loadEpubjs() {
  if (!epubjsPromise) {
    // Saved into the book cache with the first EPUB (lib/offline-books.ts),
    // so the service worker can answer for both with no network.
    epubjsPromise = loadScript(JSZIP_URL)
      .then(() => loadScript(EPUBJS_URL))
      .then(() => {
        if (!window.ePub) throw new Error("epub.js didn't load");
        return window.ePub;
      });
  }
  return epubjsPromise;
}

function loadPdfjs() {
  if (!pdfjsPromise) {
    // Saved into the book cache with the first book (lib/offline-books.ts),
    // so the service worker can answer for it with no network.
    pdfjsPromise = import(PDFJS_URL).then((mod) => {
      const lib = mod && mod.getDocument ? mod : mod.default;
      lib.GlobalWorkerOptions.workerSrc = PDFJS_WORKER_URL;
      return lib;
    });
  }
  return pdfjsPromise;
}

async function bookBytes(book) {
  const cache = await caches.open(BOOK_CACHE);
  const response = await cache.match(book.cacheUrl);
  if (!response) throw new Error("This book isn't on this device any more.");
  return new Uint8Array(await response.arrayBuffer());
}

/**
 * `target` is whatever that book's contents entries hold — a PDF page number
 * or an EPUB chapter href — or null to open at the beginning.
 */
async function openBook(book, target, toc) {
  view.innerHTML = "";
  view.appendChild(el("p", "sub", "Opening…"));

  let data;
  try {
    data = await bookBytes(book);
  } catch (error) {
    // The browser evicted it under storage pressure. Saying so beats offering
    // a book that won't open, and the index is corrected so the list agrees.
    writeBooks(readBooks().filter((item) => item.id !== book.id));
    view.innerHTML = "";
    view.appendChild(el("h1", null, book.title || "Untitled book"));
    view.appendChild(
      el("p", "sub", "This book is no longer stored on this device. Connect and save it again."),
    );
    const back = el("button", null, "← Back");
    back.addEventListener("click", renderLibrary);
    view.appendChild(back);
    renderTabs();
    return;
  }

  if (book.format === "epub") {
    await openEpub(book, data, target, toc);
    return;
  }

  let doc;
  try {
    const pdfjs = await loadPdfjs();
    doc = await pdfjs.getDocument({ data: data }).promise;
  } catch (error) {
    // No viewer on the device (the book was saved before pdf.js was deployed,
    // or that copy was evicted). The browser has a PDF viewer of its own, and
    // the service worker serves the file as a PDF precisely so it can be used.
    renderViewerFallback(book, Number(target) || 1, error);
    return;
  }
  renderReader(book, doc, Number(target) || 1, toc);
}

/**
 * An EPUB reflows, so there are no pages to draw and nothing for a browser to
 * fall back to — no browser renders an EPUB of its own. epub.js does the
 * rendering, into an iframe it owns, which is why the keys and the swipe are
 * registered inside that document rather than on this one.
 */
async function openEpub(book, data, target, toc) {
  let ePub;
  try {
    ePub = await loadEpubjs();
  } catch (error) {
    renderEpubFallback(book, error);
    return;
  }

  view.innerHTML = "";
  const bar = el("div", "reader-bar");
  const back = el("button", null, "‹ Contents");
  back.addEventListener("click", () => {
    teardownReader();
    renderBook(book);
  });
  const label = el("span", "detail");
  const previous = el("button", null, "‹");
  previous.setAttribute("aria-label", "Previous");
  const next = el("button", null, "›");
  next.setAttribute("aria-label", "Next");
  bar.appendChild(back);
  bar.appendChild(el("span", "spacer"));
  bar.appendChild(previous);
  bar.appendChild(label);
  bar.appendChild(next);
  view.appendChild(bar);

  const container = el("div");
  container.id = "epub-view";
  view.appendChild(container);
  view.appendChild(el("p", "hint", "Swipe left and right, or use the arrow keys, to move through the book."));

  // A copy of the bytes, not a view onto them: epub.js hands the buffer
  // straight to JSZip.
  const epubBook = ePub(data.slice().buffer);
  const rendition = epubBook.renderTo(container, {
    width: "100%",
    height: "100%",
    // Scrolled rather than paginated, matching the in-app reader: it behaves
    // far better on a phone.
    flow: "scrolled-doc",
    spread: "none",
  });
  activeEpub = { book: epubBook, rendition: rendition };
  activeReader = { go: (by) => (by > 0 ? rendition.next() : rendition.prev()), draw: () => {} };

  previous.addEventListener("click", () => rendition.prev());
  next.addEventListener("click", () => rendition.next());

  // Which chapter is on screen, by the same loose href match the in-app
  // reader's search results use — a navigation href can carry a fragment the
  // spine item's doesn't.
  rendition.on("relocated", (location) => {
    const href = location && location.start && location.start.href;
    if (!href || !toc) return;
    const tail = String(href).split("/").pop();
    const entry = toc.find((item) => item.location && String(item.location).includes(tail));
    label.textContent = entry ? entry.label : "";
  });

  // Inside the iframe, because that is where the reading happens and where
  // the events land.
  rendition.hooks.content.register((contents) => {
    const doc = contents.document;
    doc.addEventListener("keydown", (event) => {
      if (event.key === "ArrowRight") rendition.next();
      else if (event.key === "ArrowLeft") rendition.prev();
    });
    let gesture = null;
    doc.addEventListener(
      "touchstart",
      (event) => {
        gesture = event.touches.length === 1 ? { x: event.touches[0].clientX, y: event.touches[0].clientY, dx: 0, turning: false } : null;
      },
      { passive: true },
    );
    doc.addEventListener(
      "touchmove",
      (event) => {
        if (!gesture || event.touches.length !== 1) { gesture = null; return; }
        const dx = event.touches[0].clientX - gesture.x;
        const dy = event.touches[0].clientY - gesture.y;
        if (!gesture.turning) {
          if (Math.abs(dx) < 12 && Math.abs(dy) < 12) return;
          if (Math.abs(dy) >= Math.abs(dx)) { gesture = null; return; }
          gesture.turning = true;
        }
        gesture.dx = dx;
      },
      { passive: true },
    );
    doc.addEventListener("touchend", () => {
      if (gesture && gesture.turning && Math.abs(gesture.dx) >= 60) {
        if (gesture.dx < 0) rendition.next();
        else rendition.prev();
      }
      gesture = null;
    });
  });

  try {
    await rendition.display(target || undefined);
  } catch (error) {
    // A contents entry pointing at a section this copy doesn't have; the
    // book still opens at its beginning.
    try {
      await rendition.display();
    } catch (ignored) {
      renderEpubFallback(book, error);
    }
  }
}

/**
 * No browser renders an EPUB on its own, so unlike a PDF there is nothing to
 * hand it to — the honest offer is the file itself, for whatever reading app
 * the device has.
 */
function renderEpubFallback(book, error) {
  teardownReader();
  view.innerHTML = "";
  const back = el("button", null, "← Back");
  back.addEventListener("click", () => renderBook(book));
  view.appendChild(back);
  view.appendChild(el("h1", null, book.title || "Untitled book"));
  view.appendChild(
    el(
      "p",
      "sub",
      "The reader for this book isn't saved on this device, and a browser can't show an EPUB on its own. The file itself is here — open it in a reading app.",
    ),
  );
  const open = el("a", "btn", "Save the file");
  open.href = book.cacheUrl;
  open.setAttribute("download", (book.title || "book") + ".epub");
  view.appendChild(open);
  if (error && error.message) view.appendChild(el("p", "hint", error.message));
}

function renderViewerFallback(book, page, error) {
  view.innerHTML = "";
  const back = el("button", null, "← Back");
  back.addEventListener("click", () => renderBook(book));
  view.appendChild(back);
  view.appendChild(el("h1", null, book.title || "Untitled book"));
  view.appendChild(
    el(
      "p",
      "sub",
      "This browser can't draw the pages here, so the book opens in its own PDF viewer instead — it's the same file, saved on this device.",
    ),
  );
  const open = el("a", "btn", "Open the book");
  open.href = book.cacheUrl + "#page=" + page;
  view.appendChild(open);
  if (error && error.message) view.appendChild(el("p", "hint", error.message));
}

function renderReader(book, doc, startPage, toc) {
  let page = Math.min(Math.max(1, startPage || 1), doc.numPages);
  // A multiplier on top of fitting the width, which is what a phone wants
  // before anything else.
  let zoom = 1;
  let renderTask = null;

  view.innerHTML = "";
  const bar = el("div", "reader-bar");
  const back = el("button", null, "‹ Contents");
  back.addEventListener("click", () => {
    if (renderTask) renderTask.cancel();
    teardownReader();
    renderBook(book);
  });
  const prev = el("button", null, "‹");
  prev.setAttribute("aria-label", "Previous page");
  const label = el("span", "detail");
  const next = el("button", null, "›");
  next.setAttribute("aria-label", "Next page");
  const zoomOut = el("button", null, "−");
  zoomOut.setAttribute("aria-label", "Zoom out");
  const zoomIn = el("button", null, "+");
  zoomIn.setAttribute("aria-label", "Zoom in");

  bar.appendChild(back);
  bar.appendChild(el("span", "spacer"));
  bar.appendChild(prev);
  bar.appendChild(label);
  bar.appendChild(next);
  bar.appendChild(zoomOut);
  bar.appendChild(zoomIn);
  view.appendChild(bar);

  const wrap = el("div");
  wrap.id = "canvas-wrap";
  const canvas = el("canvas");
  wrap.appendChild(canvas);
  view.appendChild(wrap);
  view.appendChild(el("p", "hint", "Swipe left and right, or use the arrow keys, to turn the page."));

  // Which contents entry the page falls in, so the bar can say which hymn is
  // on screen — the same rule as lib/toc-nav.ts, in miniature.
  function entryLabel() {
    if (!toc) return "";
    let best = null;
    let bestPage = -Infinity;
    toc.forEach((entry) => {
      if (entry.location === null) return;
      const at = Number(entry.location);
      if (at <= page && at >= bestPage) {
        bestPage = at;
        best = entry;
      }
    });
    return best ? best.label : "";
  }

  /** The bar says where you are before the page is drawn, not after. */
  function updateBar() {
    // The number printed in the book, not the PDF's own — the same sum
    // lib/page-offset.ts does.
    const printed = page - (book.pageOffset || 0);
    const shown = printed >= 1 ? "Page " + printed : "Page " + page + " (front matter)";
    const hymn = entryLabel();
    label.textContent = hymn ? shown + " · " + hymn : shown;
    prev.disabled = page <= 1;
    next.disabled = page >= doc.numPages;
  }

  async function draw() {
    updateBar();
    try {
      const pdfPage = await doc.getPage(page);
      const natural = pdfPage.getViewport({ scale: 1 });
      const ratio = window.devicePixelRatio || 1;
      const available = Math.max(240, wrap.clientWidth - 20);
      const scale = (available / natural.width) * zoom;
      const viewport = pdfPage.getViewport({ scale: scale * ratio });
      const context = canvas.getContext("2d");
      canvas.width = viewport.width;
      canvas.height = viewport.height;
      canvas.style.width = viewport.width / ratio + "px";
      canvas.style.height = viewport.height / ratio + "px";

      if (renderTask) renderTask.cancel();
      renderTask = pdfPage.render({ canvas: canvas, canvasContext: context, viewport: viewport });
      await renderTask.promise;
    } catch (error) {
      // A cancelled render is what flipping pages quickly looks like, and is
      // nobody's problem. Anything else means this browser can't draw the
      // page — an old engine the library needs more of, a worker that won't
      // start — and a blank canvas is the worst possible answer when the book
      // is right there on the device. Hand it to the browser's own viewer.
      if (error && error.name === "RenderingCancelledException") return;
      activeReader = null;
      renderViewerFallback(book, page, error);
    }
  }

  /** Moves `by` pages, clamped to the book — the one way the page changes. */
  function go(by) {
    const bounded = Math.min(Math.max(1, page + by), doc.numPages);
    if (bounded === page) return;
    page = bounded;
    draw();
  }

  prev.addEventListener("click", () => go(-1));
  next.addEventListener("click", () => go(1));
  zoomOut.addEventListener("click", () => {
    zoom = Math.max(0.5, Math.round((zoom - 0.25) * 100) / 100);
    draw();
  });
  zoomIn.addEventListener("click", () => {
    zoom = Math.min(3, Math.round((zoom + 0.25) * 100) / 100);
    draw();
  });

  // Swipe, on the same terms as the in-app reader: a gesture has to commit to
  // the horizontal before it counts, a second finger is a pinch, and a page
  // drawn wider than the screen is being panned rather than turned.
  let gesture = null;
  wrap.addEventListener("touchstart", (event) => {
    gesture = null;
    if (event.touches.length !== 1 || wrap.scrollWidth > wrap.clientWidth + 1) return;
    gesture = { x: event.touches[0].clientX, y: event.touches[0].clientY, dx: 0, turning: false };
  }, { passive: true });
  wrap.addEventListener("touchmove", (event) => {
    if (!gesture || event.touches.length !== 1) { gesture = null; return; }
    const dx = event.touches[0].clientX - gesture.x;
    const dy = event.touches[0].clientY - gesture.y;
    if (!gesture.turning) {
      if (Math.abs(dx) < 12 && Math.abs(dy) < 12) return;
      if (Math.abs(dy) >= Math.abs(dx)) { gesture = null; return; }
      gesture.turning = true;
    }
    gesture.dx = dx;
  }, { passive: true });
  wrap.addEventListener("touchend", () => {
    if (!gesture || !gesture.turning || Math.abs(gesture.dx) < 60) { gesture = null; return; }
    go(gesture.dx < 0 ? 1 : -1);
    gesture = null;
  });

  // Registered once for the page (below) and pointed at whichever reader is
  // open, so opening a second book doesn't leave the first one listening.
  activeReader = { go: go, draw: draw };
  draw();
}

// ------------------------------------------------------------- the videos --
function playerNode() {
  const player = el("div");
  player.id = "player";
  const video = document.createElement("video");
  video.id = "video";
  video.controls = true;
  video.playsInline = true;
  player.appendChild(video);
  player.appendChild(el("p", "now-playing"));
  return player;
}

function videoRow(item) {
  const li = el("li");
  const meta = el("div", "meta");
  meta.appendChild(el("div", "title", item.title || "Untitled video"));
  const bits = [];
  if (item.seriesTitle) bits.push(item.seriesTitle);
  const dur = formatDuration(item.durationSeconds);
  if (dur) bits.push(dur);
  const size = formatBytes(item.bytes);
  if (size) bits.push(size);
  meta.appendChild(el("div", "detail", bits.join(" · ")));

  const actions = el("div", "actions");
  const playBtn = el("button", "primary", "▶ Play");
  playBtn.addEventListener("click", () => play(item));
  const removeBtn = el("button", "remove", "Remove");
  removeBtn.addEventListener("click", () => removeVideo(item));
  actions.appendChild(playBtn);
  actions.appendChild(removeBtn);

  li.appendChild(meta);
  li.appendChild(actions);
  return li;
}

function play(item) {
  const player = document.getElementById("player");
  const video = document.getElementById("video");
  if (!player || !video) return;
  // The service worker answers this path straight from Cache Storage
  // (including range requests), so this works with no network at all —
  // see the DOWNLOAD_PATH_PREFIX handler in public/sw.js.
  video.src = item.cacheUrl;
  player.querySelector(".now-playing").textContent = item.title || "";
  player.style.display = "block";
  player.scrollIntoView({ behavior: "smooth", block: "start" });
  video.play().catch(() => {});
}

async function removeVideo(item) {
  if (!window.confirm('Remove "' + (item.title || "this video") + '" from this device?')) return;
  try {
    const cache = await caches.open(DOWNLOAD_CACHE);
    await cache.delete(item.cacheUrl);
  } catch (e) {}
  writeVideos(readVideos().filter((i) => i.videoId !== item.videoId));
  renderLibrary();
}

document.getElementById("retry").addEventListener("click", () => {
  const status = document.getElementById("retry-status");
  status.textContent = " Checking…";
  fetch("/", { method: "HEAD", cache: "no-store" })
    .then(() => { window.location.href = scopePath || "/"; })
    .catch(() => { status.textContent = " Still offline."; });
});

/**
 * A tap that was heading for a specific book — /books/<id> or /read/<id>,
 * from a link, a bookmark, or the app's own reader before the connection
 * went — opens that book rather than the list, when it's one of the saved
 * ones.
 */
function bookFromPath() {
  const match = /^\/(?:books|read)\/([^/]+)/.exec(scopePath || "");
  if (match) return readBooks().find((book) => book.id === match[1]) || null;
  // A hymn-per-file book *is* its series, so its own page is the series page.
  const saved = readBooks().filter((book) => book.homeHref === scopePath);
  return saved.length === 1 && saved[0].kind === "hymnal" ? saved[0] : null;
}

/**
 * The same for a single hymn — /hymns/<id> — which needs the saved hymnals
 * opened to know which one holds it, so it can only be answered
 * asynchronously.
 */
async function openHymnFromPath() {
  const match = /^\/hymns\/([^/]+)/.exec(scopePath || "");
  if (!match) return false;
  for (const book of readBooks().filter((item) => item.kind === "hymnal")) {
    try {
      const hymns = await loadHymnal(book);
      const at = hymns.findIndex((hymn) => hymn.id === match[1]);
      if (at !== -1) {
        renderHymn(book, hymns, at);
        return true;
      }
    } catch (error) {
      // Evicted, or never finished saving; try the next hymnal.
    }
  }
  return false;
}

renderTabs();
const requested = bookFromPath();
if (requested) {
  if (requested.kind === "hymnal") renderHymnal(requested);
  else renderBook(requested);
} else {
  renderLibrary();
  // Replaces the list it just drew, if this turns out to be a hymn we hold.
  openHymnFromPath();
}
</script>
</body>
</html>

Appendix J — Auth0 Actions (verbatim)
J.1 — auth0-actions/README.md
Auth0 Actions

Source for the Auth0 Actions this app depends on. They live here so they're reviewable and versioned; Auth0 itself is configured through its dashboard, so deploying the app does not deploy these — see the checklist below.
The security model

authenticated with Auth0
  ↓  member of the Marine Team organization      (org_id claim on the ID token)
  ↓  email ACTIVE in AuthorizedEmail             (PostgreSQL, via Prisma)
  ↓  application access

Both checks must pass. Neither is sufficient alone, and neither is ever taken from something the browser supplied.
Marine Team member 	Authorized email 	Result
no 	no 	DENY
no 	yes 	DENY
yes 	no 	DENY
yes 	yes 	ALLOW

Where each is enforced:

    Organization — authorizationParameters.organization in src/lib/auth0.ts makes Auth0 refuse non-members at the identity provider, and isOrganizationMember() re-checks the verified org_id claim server-side. The parameter is the request; the claim is the proof.
    Allowlist — getCurrentUser() reads AuthorizedEmail on every server-rendered page and API request, so removing an email takes effect on that person's next request rather than whenever their cookie expires.

pre-user-registration.js

Stops unauthorized emails creating accounts at all.

    Auth0 Dashboard → Actions → Library → Build from scratch
    Name: Marine Team registration check, Trigger: Pre User Registration
    Paste pre-user-registration.js
    Add two Secrets (the key icon in the editor):
        AUTH0_REGISTRATION_CHECK_URL — https://<your-domain>/api/auth/registration-check
        AUTH0_REGISTRATION_CHECK_SECRET — the same value as the app's AUTH0_REGISTRATION_CHECK_SECRET env var (generate with openssl rand -hex 32)
    Deploy, then drag it into Actions → Triggers → pre-user-registration

The Action fails closed: if the endpoint is unreachable, slow (5s timeout), or answers anything but {"allowed": true}, registration is denied.

    This trigger only fires for database connections. Social signups (Google) do not run it — for those, the organization requirement plus the allowlist check in getCurrentUser() are what refuse access, which is why the allowlist is enforced on every request rather than only at signup.

link-accounts.js

Optional. Merges identities so one person is one Auth0 user — without it, signing in with Google and later with Microsoft on the same address creates two Auth0 users with two different sub values.

    Auth0 Dashboard → Actions → Library → Build from scratch
    Name: Marine Team account linking, Trigger: Login / Post Login
    Paste link-accounts.js
    Add three Secrets, from a Machine-to-Machine application authorized for the Management API with the read:users and update:users scopes:
        AUTH0_DOMAIN — your-tenant.eu.auth0.com
        AUTH0_M2M_CLIENT_ID
        AUTH0_M2M_CLIENT_SECRET
    Deploy, then drag it into Actions → Triggers → post-login, above any Action that reads the user's identities

No npm modules to add. The editor's Modules panel is a fixed list — if it says everything available is already bound, that's expected and there is nothing to do there. This Action calls the Management API over plain fetch, the same way pre-user-registration.js calls the app. The auth0 SDK would do the same work, but its availability in the Actions runtime isn't dependable (the "cannot find module 'auth0'" reports are common) and its v3 and v4 APIs differ enough that pasting the wrong one fails at runtime rather than in the editor.

It only links when both accounts have a verified email. Linking on an unverified address is the account-takeover path this feature is known for: anyone able to sign up asserting an existing member's address would be merged into their account. The application refuses the same case independently (decideLinking in src/lib/identity-linking.ts), so this is defence in depth, not the only guard.

Unlike the registration check, this Action fails open. That one guards the door, so an outage must deny; this one only tidies identities, and the app's sub-first resolution keeps people on the right member row without it. Blocking a login because a merge failed would trade a cosmetic problem for a lockout.

When the account that just logged in is the one merged away, the Action calls api.authentication.setPrimaryUser() so the login continues as the surviving account. Auth0 does not switch the transaction over by itself, and without it the login finishes as a user that no longer exists on its own. It's called only after users.link(), because the API requires the authenticating identity to already be one of the primary user's secondary identities.

    The app does not require this Action. Skip it and members still get one account per person, because getCurrentUser() links verified identities by email itself. What the Action adds is a single stable sub per person at the Auth0 layer, which keeps sessions and logs consistent across providers.

Check email_verified before relying on any of this

Both the Action and the app refuse to link an identity whose email the provider hasn't verified, so a connection that doesn't assert the claim doesn't just skip linking — it means someone signing in that way is denied access if their address already belongs to a member. That is the correct outcome (it's exactly the takeover case), but it looks like a broken login if you weren't expecting it.

Auth0 sets email_verified to whatever the provider returns, and to false when the provider returns nothing. GitHub is a known case of this — Auth0 publishes a support article on email_verified=False for GitHub logins, and the connection generally needs the user:email scope before verified address information is available at all.

Before enabling a second connection, sign in with it once and check that user's profile in User Management → Users shows email_verified: true. If it doesn't, fix the connection's scopes rather than relaxing the rule.
Dashboard checklist

    Organizations enabled; Marine Team exists and its id is in the app's AUTH0_ORGANIZATION_ID.
    Application → Organizations: usage Require Organization Membership, and the Google connection is enabled for the organization.
    Allowed Callback URLs: https://<your-domain>/auth/callback. Allowed Logout URLs: https://<your-domain>.
    The registration check Action is deployed and attached to the pre-user-registration flow.
    Optionally, the account-linking Action is deployed and attached to the post-login flow, with its M2M application authorized for read:users and update:users.

A note on the callback errors

Two errors are expected when someone tries a personal account, and neither means the cookie configuration is broken:

    invalid_request (client requires organization membership, but user does not belong to any organization) — the organization requirement doing its job.
    Missing state cookie from login request — usually a stale or re-played callback URL (a refresh, a bookmarked /auth/callback, or a second attempt after the first consumed the transaction cookie).

Both are caught by the onCallback hook in src/lib/auth0.ts and turned into /access-denied. State and nonce validation are untouched — the fix is to present the error nicely, not to stop checking.
J.2 — auth0-actions/pre-user-registration.js

/**
 * Auth0 Action — Pre User Registration
 *
 * Stops an email that isn't on the application's allowlist from ever creating
 * an account. Paste this into Auth0 (Actions -> Library -> Build from scratch,
 * trigger "Pre User Registration") and add it to that flow.
 *
 * It never talks to PostgreSQL. Auth0 holds a URL and a shared secret; the
 * application endpoint is the only thing that can read the allowlist, and it
 * answers with nothing but a boolean.
 *
 * Required Action Secrets:
 *   AUTH0_REGISTRATION_CHECK_URL     https://your-domain/api/auth/registration-check
 *   AUTH0_REGISTRATION_CHECK_SECRET  the same value as the app's env var of that name
 *
 * Fails CLOSED: if the endpoint is unreachable, slow, misconfigured, or
 * answers anything other than a clear yes, registration is denied. An outage
 * must not become an open door.
 */

const TIMEOUT_MS = 5000;

exports.onExecutePreUserRegistration = async (event, api) => {
  const url = event.secrets.AUTH0_REGISTRATION_CHECK_URL;
  const secret = event.secrets.AUTH0_REGISTRATION_CHECK_SECRET;

  const deny = (reason) =>
    api.access.deny(
      // Shown to the person signing up: no internal detail.
      "You are not authorized to create an account for this application. Please contact an administrator.",
      reason,
    );

  if (!url || !secret) return deny("registration_check_not_configured");

  // Only ever call the configured HTTPS endpoint — never a URL derived from
  // anything in the event, which is what would turn this into an SSRF.
  let endpoint;
  try {
    endpoint = new URL(url);
  } catch {
    return deny("registration_check_bad_url");
  }
  if (endpoint.protocol !== "https:") return deny("registration_check_insecure_url");

  const email = (event.user.email || "").trim().toLowerCase();
  if (!email) return deny("registration_missing_email");

  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), TIMEOUT_MS);

  try {
    const response = await fetch(endpoint.toString(), {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        Authorization: `Bearer ${secret}`,
      },
      body: JSON.stringify({
        email,
        auth0UserId: event.user.user_id,
        // The connection behind the signup, e.g. "google-oauth2".
        provider: event.connection && event.connection.strategy,
      }),
      signal: controller.signal,
    });

    if (!response.ok) return deny("registration_check_unavailable");

    const body = await response.json();
    // Strict equality on a real boolean: a body that's missing the field, or
    // carries a truthy string, is not an authorization.
    if (body.allowed !== true) return deny("email_not_authorized");
  } catch {
    // Timeout, DNS failure, TLS problem, malformed JSON — all the same answer.
    return deny("registration_check_error");
  } finally {
    clearTimeout(timeout);
  }
};

J.3 — auth0-actions/link-accounts.js

/**
 * Auth0 Action — Post Login (account linking)
 *
 * Merges identities so one person is one Auth0 user. Without this, signing in
 * with Google and later with GitHub on the same address produces two separate
 * Auth0 users with two different `sub` values, and the application has to
 * reconcile them by email afterwards.
 *
 * Paste this into Auth0 (Actions -> Library -> Build from scratch, trigger
 * "Login / Post Login") and add it to the Login flow. It must run BEFORE any
 * Action that reads the user's identities.
 *
 * Required Action Secrets (a Machine-to-Machine app authorized for the
 * Management API, with the `read:users` and `update:users` scopes):
 *   AUTH0_DOMAIN         your-tenant.eu.auth0.com
 *   AUTH0_M2M_CLIENT_ID
 *   AUTH0_M2M_CLIENT_SECRET
 *
 * No npm modules to add: this calls the Management API over plain `fetch`,
 * the same way pre-user-registration.js calls the app. The `auth0` SDK would
 * do the same work, but its availability in the Actions runtime isn't
 * dependable and its v3 and v4 APIs differ enough that pasting the wrong one
 * fails at runtime rather than in the editor.
 *
 * Fails OPEN, unlike the pre-user-registration Action, and the difference is
 * deliberate. That one guards the door, so an outage must deny. This one only
 * tidies identities: if it can't run, the person still signs in, and the
 * application's own sub-first resolution (src/lib/current-user.ts) keeps them
 * on the right member row anyway. Blocking a login because a merge failed
 * would trade a cosmetic problem for a lockout.
 */

const TIMEOUT_MS = 5000;

async function api(url, options, timeoutMs = TIMEOUT_MS) {
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), timeoutMs);
  try {
    const res = await fetch(url, { ...options, signal: controller.signal });
    if (!res.ok) throw new Error(`${options.method || "GET"} ${url} -> ${res.status}`);
    return await res.json();
  } finally {
    clearTimeout(timer);
  }
}

exports.onExecutePostLogin = async (event, api_) => {
  const { AUTH0_DOMAIN, AUTH0_M2M_CLIENT_ID, AUTH0_M2M_CLIENT_SECRET } = event.secrets;
  if (!AUTH0_DOMAIN || !AUTH0_M2M_CLIENT_ID || !AUTH0_M2M_CLIENT_SECRET) return;

  // Linking on an unverified email is the account-takeover path this whole
  // feature is known for: anyone able to sign up asserting someone else's
  // address would be merged into their account. The application refuses the
  // same case (decideLinking in src/lib/identity-linking.ts) — this is the
  // same rule enforced one layer earlier.
  if (!event.user.email || event.user.email_verified !== true) return;

  // Already the result of a previous merge: nothing to do, and re-checking
  // would mean two Management API calls on every single login.
  if (Array.isArray(event.user.identities) && event.user.identities.length > 1) return;

  try {
    const base = `https://${AUTH0_DOMAIN}`;

    const token = await api(`${base}/oauth/token`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        grant_type: "client_credentials",
        client_id: AUTH0_M2M_CLIENT_ID,
        client_secret: AUTH0_M2M_CLIENT_SECRET,
        audience: `${base}/api/v2/`,
      }),
    });

    const authHeaders = {
      Authorization: `Bearer ${token.access_token}`,
      "Content-Type": "application/json",
    };

    const matches = await api(
      `${base}/api/v2/users-by-email?email=${encodeURIComponent(event.user.email)}`,
      { headers: authHeaders },
    );

    const candidates = (matches || []).filter(
      (candidate) =>
        candidate.user_id !== event.user.user_id &&
        // Both sides have to be verified. An unverified *existing* account is
        // just as unsafe to merge into as an unverified incoming one.
        candidate.email_verified === true,
    );
    if (candidates.length === 0) return;

    // Merge into the oldest account, so the identity people have been using
    // longest stays primary and keeps its user_id. The app tolerates either
    // outcome — it resolves by sub — but a stable primary means the `sub` in
    // existing sessions and logs keeps meaning the same person.
    candidates.sort((a, b) => new Date(a.created_at) - new Date(b.created_at));
    const oldest = candidates[0];

    const oldestIsOlder = new Date(oldest.created_at) <= new Date(event.user.created_at);
    const [target, source] = oldestIsOlder ? [oldest, event.user] : [event.user, oldest];

    const sourceIdentity = (source.identities || [])[0];
    if (!sourceIdentity) return;

    await api(`${base}/api/v2/users/${encodeURIComponent(target.user_id)}/identities`, {
      method: "POST",
      headers: authHeaders,
      body: JSON.stringify({
        provider: sourceIdentity.provider,
        user_id: String(sourceIdentity.user_id),
      }),
    });

    // When the account that just logged in is the one merged away, its sub no
    // longer exists as a user of its own — it's now a secondary identity of
    // `target`. Auth0 does not switch the transaction over on its own, so
    // without this the login finishes as a user that isn't there any more.
    //
    // Safe precisely here and not before: the API requires the authenticating
    // identity to already be among the primary user's secondary identities,
    // which the link call above has just made true.
    if (target.user_id !== event.user.user_id) {
      api_.authentication.setPrimaryUser(target.user_id);
    }
  } catch (error) {
    // Logged for the Action's own log stream only; never surfaced to the
    // person, and never a reason to block the login.
    console.log(`Account linking skipped: ${error && error.message}`);
  }
};
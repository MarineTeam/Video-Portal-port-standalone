# Writing a plugin

**A plugin is code, and it runs with the whole site's privileges.** It can
read every table, send email as the site, and change anything an
administrator can. Install only plugins you would trust as much as you would
trust somebody editing the site's files — the same rule as WordPress. Only an
administrator holding *Manage plugins* can install one, and the plugin list
says so.

Everything past the media library is itself a plugin written against this API
(the 31 bundled ones in `plugins/`), which is how the API is known to be
enough.

## The shape of a plugin

```
plugins/
  sermon-notes/
    plugin.php          the header and the plugin object
    migrations/         0001_create.sql, 0002_backfill.php … (optional)
    templates/          templates the plugin renders (optional)
    assets/             JS, CSS, images — served at /plugins/<slug>/assets/…
```

`plugin.php` starts with a header, read as text before anything runs:

```php
<?php
/**
 * Plugin Name: Sermon notes
 * Slug:        sermon-notes
 * Version:     1.0.0
 * Description: Lets members keep their own timestamped notes on a video.
 * Author:      Grace Church
 * Requires PHP: 8.2
 * Requires App: 3.0
 * Provides:    video          (optional: auth, video, email, files or sms)
 * Depends:     favorites      (optional: loaded after these; skipped without them)
 * Category Override: yes      (optional: can be switched per category)
 */
```

The `Slug` must match the folder name. It then **returns** an object
implementing `App\Modules\Plugins\Plugin` — most plugins extend
`App\Modules\Plugins\BasePlugin` and override what they need:

```php
use App\Core\App;
use App\Core\Hooks;
use App\Core\Response;
use App\Core\Router;
use App\Core\Middleware;
use App\Modules\Plugins\BasePlugin;

return new class (__DIR__) extends BasePlugin {
    public function boot(Hooks $hooks, App $app): void
    {
        $hooks->on('routes.register', function (Router $r) use ($app): void {
            $r->get('/notes', fn () => $app->page('sermon-notes/list', []), [Middleware::member($app)]);
        });
        $hooks->filter('nav.sections', fn (array $nav) => [
            ...$nav,
            ['href' => '/notes', 'label' => 'My notes', 'icon' => 'book'],
        ]);
    }
};
```

| Method | When it runs |
|---|---|
| `boot(Hooks, App)` | every request while the plugin is active |
| `activate(App)` | once, when an administrator activates it |
| `deactivate(App)` | when deactivated; must not delete data |
| `uninstall(App)` | only when an administrator chooses *Delete* — the one place to drop tables |
| `migrations(): ?string` | the folder of numbered migration files (BasePlugin returns `migrations/` if it exists) |

## When a plugin breaks

On shared hosting there is no shell to switch a broken plugin off from, so the
site does it:

- Anything thrown while `plugin.php` is loaded or `boot()` runs — an
  exception, a `ParseError`, a missing class — deactivates the plugin.
- A fatal error while loading (running out of memory, the time limit) is
  caught at shutdown: the plugin is deactivated and the visitor gets the
  plain error page instead of a blank screen.
- If the process is killed outright mid-load, the next request finds the
  marker it left in `storage/plugins/loading.json` and deactivates it then.
- A hook callback that throws after boot is contained: it is logged, the page
  continues without that callback's contribution, and ten failures in ten
  minutes deactivates the plugin.

Each deactivation records the reason and the error on the plugin's row, shows
a notice on every admin page, and emails the administrators once.
`/admin/plugins` and `/admin/logs` never load third-party plugins, so they
always work.

## Hooks

`$hooks->on($name, $callback, $priority = 10)` registers an action;
`$hooks->filter($name, $callback, $priority = 10)` a filter, which receives a
value and returns it (changed or not). Lower priority runs first.

### Fired now

| Hook | Kind | Arguments | Where |
|---|---|---|---|
| `routes.register` | action | `Router $router, App $app` | every request, after plugins boot — add routes here |
| `app.request` | action | `Request, App` | before routing |
| `app.shutdown` | action | `Request, Response, App` | after the route answered |
| `capabilities.register` | action | `callable $register(key, label, hint, siteWideOnly = true)` | call it to add a capability to the permission builder |
| `lang.catalogue` | filter | `array $strings, string $locale` | return extra `key => text` for a language |
| `services.providers` | action | `Registry $registry` | `$registry->register(MyProvider::class)` — see SERVICES.md |
| `jobs.register` | action | `Scheduler $scheduler, App` | `$scheduler->register($name, $intervalSeconds, fn (float $deadline): string => …, $budgetSeconds)` |
| `nav.sections` | filter | `array $items, ?array $user` | the sidebar: `['href', 'label', 'icon']` |
| `nav.tabs` | filter | `array $tabs, ?array $user` | the suggested bottom bar |
| `render.head` | action | `App` | echo into `<head>` (use `View::nonce()` for an inline script) |
| `render.body_end` | action | `App` | echo before `</body>` |
| `admin.dashboard.cards` | filter | `array $cards` | HTML strings shown on the admin dashboard |
| `user.resolved` | filter | `array $userRow` | the signed-in member, once per request |
| `user.signed_in` | action | `string $userId, Identity` | after a successful sign-in |
| `auth.refused` | action | `array{email, provider, reason}` | after a refused sign-in |
| `template.resolve` | filter | `?string $file, string $name` | return a file to render instead of a template |
| `template.<name>.vars` | filter | `array $vars` | the variables a template receives (`<name>` with `/` as `.`) |
| `profile.sections` | filter | `array $items, array $user` | links in the profile's own navigation: `['href' => '/profile/…', 'label']` |
| `profile.overview` | filter | `array $cards, array $user` | cards on /profile: `['title', 'body'?, 'href'?, 'count'?]` |
| `profile.inbox.top` | action | `App, array $user` | echo above the inbox (the push permission toggle) |
| `profile.settings` | action | `App, array $user` | echo a settings group into the Account card |
| `profile.fields` | filter | `array $fields, array $user` | extra keys `PATCH /api/profile` accepts (`key => true`); save them on `profile.updated` |
| `profile.updated` | action | `string $userId, array $input` | after `PATCH /api/profile` |
| `profile.export` | filter | `array $doc, string $userId` | add a section to "Download my data" (it is checked for credentials after you) |
| `page.category.panels` | filter | `array $panels, array $context` | add to a category page; `$context`: `category`, `categoryId`, `viewer` (`Library\Viewer`), `plugins` (slug ⇒ on, resolved for this category), `app` |
| `page.series.panels` | filter | `array $panels, array $context` | add to a series page; `$context`: `series`, `videos`, `categoryId`, `viewer`, `plugins`, `app` |
| `page.video.panels` | filter | `array $panels, array $context` | add to a video page; `$context`: `video`, `series`, `siblings`, `categoryId`, `viewer`, `locked`, `player`, `plugins`, `app` |

A panel is `['area' => 'actions' | 'below', 'html' => string, 'order' => int]`:
`actions` is the row of buttons under the title, `below` sits under the
page's own content. The html is yours, so escape what you put in it —
render it from your own template (below) and it is. Check
`$context['plugins']['your-slug']` before adding anything: that is the
plugin's state for this page's category, so a category that switches you
off loses your panel.

To put something in a member's inbox — the record kept whether or not push
or email reached them — call `App\Modules\Profile\Inbox::add($db, $userId,
$title, $body, $url)`.

### Arriving with the modules that fire them

These are part of the API and will fire from the library, access and profile
modules as they are ported (see `docs/PORT_MAP.md`): `content.can_view`,
`home.rows`, `related.items`, `admin.menu`, `settings.register`,
`plugin.category_override`. (`series.saved`, `video.saved`, `file.saved`,
`*.published`, `*.trashed`, `*.restored`, `*.purged` and
`content.search_sources` fire already.)

## What a plugin may and may not do

- **Tables:** its own, named with its own prefix (`p_<slug>_…`), created by
  its migrations. (The bundled plugins use the tables the original schema
  already had — `series_favorites`, `ratings` and so on — which the core's
  `0001_init.sql` creates.) Migrations are numbered files (`0001_….sql` or `.php`),
  applied one per request with the same resumable runner as the core, and
  must be safe to re-run (`CREATE TABLE IF NOT EXISTS {{p_notes_items}}`).
- **Core data:** through the module classes (`App\Modules\…`), never by
  reaching into another plugin's tables.
- **Templates:** render through `$app->page('my-plugin/name', $vars)`; add
  the plugin's `templates/` folder to the view with the `template.resolve`
  filter. Output is escaped with `e()`; `$v->raw()` is the only exception.
- **BasePlugin helpers:** `$this->useTemplates($hooks)` serves
  `templates/<name>.php` as `<slug>/<name>`, so
  `$app->view()->partial('my-plugin/button', $vars)` and
  `$app->page('my-plugin/page', $vars)` find them; `$this->asset('app.js')`
  is the address of `assets/app.js` with a cache-busting version.
- **The item a member acts on:** `App\Modules\Library\ContentTarget::from($app,
  $input, ['series', 'video'], 'my-slug')` reads `{seriesId}` / `{videoId}` /
  `{categoryId}`, answers 404 for anything the reader can't open and 403
  `plugin_disabled` where your plugin is off for its category, and gives you
  the row, its kind and its category.
- **Browser scripts in a panel:** read `window.MT` when you use it, not when
  your module loads — the core's module may run after yours.
- **Translations:** return `key => text` from the `lang.catalogue` filter
  (the bundled plugins keep them in `lang/en.php`, `lang/es.php`).
- **A toggle button without writing JavaScript:**
  `<button data-api="/api/…" data-body='{"videoId":"…"}' data-toggle="favorited"
  data-label-on="Saved" data-label-off="Save" aria-pressed="false">` posts the
  body, and flips `aria-pressed` and the label from the boolean the answer
  carries under that key — no reload.
- **Browser code:** plain ES modules under `assets/`, loaded from
  `/plugins/<slug>/assets/…` (nothing executable or hidden is ever served
  from there). `window.MT` offers `MT.hooks`, `MT.api` (fetch with the CSRF
  header and base path applied) and `MT.settings` (device settings).
- **Per-category switching:** declare `Category Override: yes`, then ask
  `App\Modules\Plugins\PluginStates::enabled($db, 'my-slug', $categoryId)`
  on a page that belongs to a category.

## Installing and updating

- **With the zip extension:** Admin → Plugins → *Install a plugin*, choose the
  `.zip`. It must contain exactly one folder named after the slug. Paths that
  leave that folder, symbolic links, more than 10,000 files or more than
  200 MB unpacked are refused. Installing does not activate.
- **Without it:** unzip on your computer, upload the folder into `plugins/`
  by FTP, and refresh Admin → Plugins.
- **Updating** is installing a newer version over the old one. Its new
  migrations run on the next requests.
- **Delete** (third-party plugins only) calls `uninstall()` and removes the
  folder. Bundled plugins can be switched off but not deleted.

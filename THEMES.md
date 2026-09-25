# Writing a theme

A theme changes how the site looks by overriding templates and adding CSS,
without touching the application. The default theme lives in
`themes/default/`; a new theme is usually a *child* of it, overriding only
what it changes.

## The shape of a theme

```
themes/
  harbour/
    theme.json         name, slug, version, parent, author, screenshot
    templates/         any file from app/Templates/, at the same path
    assets/
      theme.css        loaded after the core stylesheet
      theme.js         loaded after the core modules (an ES module)
    functions.php      optional: receives $hooks and $app, like a plugin
    customizer.json    optional: settings shown under Admin → Appearance
```

`theme.json`:

```json
{
  "name": "Harbour",
  "slug": "harbour",
  "version": "1.0.0",
  "parent": "default",
  "author": "Grace Church",
  "screenshot": "assets/screenshot.png"
}
```

Templates resolve **child → parent → core**: a child theme that only has
`templates/home.php` gets its own home page and everything else from its
parent, then from `app/Templates/`.

If `functions.php` throws while loading, the theme is switched back to the
default automatically and administrators see a notice saying why.

## Colours and CSS

Every colour in the core stylesheet is a CSS custom property. Three come from
Admin → Branding and are written into each page (`--brand`, `--brand-deep`,
`--brand-light`, and the derived `--accent`, `--accent-strong`,
`--accent-soft`, `--accent-ring`, `--hero-from`, `--hero-to`). The neutral
ones are defined in `public/assets/css/app.css` for light and dark:
`--ink`, `--ink-soft`, `--sec`, `--panel`, `--page`, `--chip`, `--sep`,
`--ok`, `--warn`, `--error`, `--radius`, `--radius-sm`, `--shadow`.

Dark mode is the class `dark` on `<html>`, stamped before the page paints; a
theme styles it with `html.dark …`. Override tokens rather than rules where
you can:

```css
:root { --radius: 2px; --font: Georgia, serif; }
html.dark { --page: #000; }
```

## Writing templates

Templates are plain PHP. Every one has in scope:

| Name | What it is |
|---|---|
| `$v` | the View: `$v->partial('name', $vars)`, `$v->raw($html)`, `$v->nonce()`, `$v->start('section')` / `$v->end()` |
| `e($value)` | escape for HTML — use it for every output |
| `url($path, $query = [])` | a link under the site's base path |
| `asset($path)` | a file under `public/assets/`, versioned |
| `t($key, $vars = [])` | a translated string |
| `csrf_field()` | the hidden CSRF input every POST form needs (output it with `$v->raw()`) |
| `$shell` | the page shell, below |

Write `<?= e($title) ?>`. The only unescaped output is `$v->raw(...)`, which
the build lists so every use is visible to a reviewer.

### `$shell` (every page)

| Key | Contents |
|---|---|
| `branding` | `name`, `shortName`, `brand`, `brandDeep`, `brandLight`, `logoUrl` |
| `brandingCss` | the custom properties, for the layout's `<style nonce>` |
| `user` | `null`, or `id`, `name`, `email`, `isAdmin`, `isStaff` |
| `csrf` | this session's CSRF token |
| `nonce` | this response's script nonce |
| `locale` | `en`, `es`, … |
| `nav` | sidebar items: `href`, `label`, `icon` |
| `tabs` | bottom-bar items: `href`, `label`, `icon` |
| `path` | the current path, without the base path |
| `theme` | `css` and `js` lists of this theme's asset URLs |
| `notices`, `themeNotice`, `breakGlass` | administrator notices |
| `adminNav` | the admin sections this person can use |
| `head`, `bodyEnd` | what plugins added through `render.head` / `render.body_end` |
| `themeSettings` | the active theme's customizer values, `key => value` |
| `themeClasses` | the classes the layouts put on `<html>` for toggles and selects |

### Core templates and their variables

| Template | Variables |
|---|---|
| `layouts/site` | `$content`, `$title`, optional `$description`, `$meta` (Open Graph `property => content`), `$jsonLd` (list of arrays), `$noindex` |
| `layouts/auth` | `$content`, `$title` |
| `layouts/admin` | `$content`, `$title` |
| `partials/head` | everything the layout has |
| `partials/notices` | `$shell` |
| `partials/admin-nav` | `$shell` |
| `home` | `$branding`, `$categories` (`name`, `slug`) |
| `access-denied` | `$guestOpen` |
| `auth/login` | `$error`, `$email`, `$returnTo`, `$magicLink`, `$selfRegistration`, `$breakGlass`, `$primary` |
| `auth/message` | `$title`, `$body` |
| `auth/register` | `$error`, `$email`, `$name` |
| `auth/reset-request` | `$configured` |
| `auth/reset` | `$token`, `$error` |
| `auth/magic` | `$token`, `$email` |
| `auth/recover` | `$error` |

Library, profile and plugin templates are listed here as their modules are
ported. A plugin can let a theme change a template's variables through the
`template.<name>.vars` filter.

## Customizer settings

`customizer.json` is a list of settings, each `key` (a letter, then letters,
digits, `_` or `-`), `type` (`colour`, `image`, `text`, `select`, `toggle`),
`label`, optional `default` and, for a select, `options` (strings, or
`{"value", "label"}` with values of lowercase letters, digits and dashes).
A child theme's declaration of a key replaces its parent's.

```json
[
  { "key": "rounded", "type": "toggle", "label": "Rounded corners", "default": true },
  { "key": "stripe", "type": "colour", "label": "Stripe colour", "default": "#ff8800" },
  { "key": "density", "type": "select", "label": "Density", "default": "cosy",
    "options": [{ "value": "cosy", "label": "Cosy" }, { "value": "compact", "label": "Compact" }] },
  { "key": "hero", "type": "image", "label": "Hero image" }
]
```

They appear under Admin → Appearance, are stored per theme, and reach the
page like this:

| Type | On the page |
|---|---|
| `toggle` | the class `theme-<key>` on `<html>` while it is on |
| `select` | the class `theme-<key>-<value>` on `<html>` |
| `colour` | the custom property `--theme-<key>` (`#rrggbb`) |
| `image` | the custom property `--theme-<key>` as `url("…")` — an https:// address or an image uploaded on that screen |
| `text` | only `$shell['themeSettings']` |

Keys are written kebab-case in class and property names (`heroImage` →
`--theme-hero-image`). Every value is also in `$shell['themeSettings']`.

A setting whose key is a branding field — `name`, `shortName`, `brand`,
`brandDeep`, `brandLight`, `logoUrl` — replaces that field while the theme is
active (the colours every other colour is derived from included), so the
branding stays the base every theme inherits and a theme can still offer its
own. The default theme's one setting is *Rounded corners*:

```css
html:not(.theme-rounded) { --radius: 0; --radius-sm: 0; }
```

## Installing, switching, deleting

Admin → Appearance → *Install a theme* takes a `.zip` holding one folder named
after the slug (the same checks as plugins); uploading one that is installed
replaces it. Without the zip extension, upload the folder into `themes/` by
FTP. A theme can't be activated while the parent it names is missing, and
the default theme, the active theme, its parents, and any theme another one
builds on can't be deleted. Deleting a theme deletes its customizer values.

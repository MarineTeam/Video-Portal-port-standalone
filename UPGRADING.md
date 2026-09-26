# Upgrading

Two audiences here. If you run a site and want the new version on it, that is
INSTALL.md, *Updating to a new version* — this document is about what a new
version is allowed to do to you, and what you have to do if you write plugins
or themes.

## What a version number promises

Versions are `MAJOR.MINOR.PATCH`.

- **Patch** (3.0.0 → 3.0.1): fixes. No schema change, no hook removed, no
  template variable renamed. Upgrade without reading anything.
- **Minor** (3.0 → 3.1): new features, new hooks, new columns and tables.
  Everything a plugin or theme used in 3.0 still works in 3.1. Something may
  be *deprecated* — it keeps working and says so in the log.
- **Major** (3 → 4): the deprecated things are gone. A major release ships
  with a section in this file saying what went, and what replaced it, per
  item.

The rule behind those, in one sentence: **a site upgrades without anybody
editing a file**. If a release would need somebody to open a template over
FTP and change it, it is a major release and this file says so.

## What an upgrade does to the database

Migrations are numbered files in `app/Migrations/` (`NNNN_name.sql`, or
`.php` when data needs code), applied in order and recorded in
`schema_migrations` once the whole file succeeded.

Three properties matter, and all three exist because of shared hosting:

- **One file per request.** The updater applies them with a progress bar, so a
  host's thirty-second limit cannot leave the schema half-applied and
  forgotten.
- **Re-runnable.** MySQL does not roll DDL back, so every file is written to
  survive being run twice (`IF NOT EXISTS`, a check before an `ALTER`). A file
  that died half way simply runs again.
- **Additive.** A migration adds tables, columns and indexes. It does not drop
  a column somebody's data is in. Where something really must go, the release
  that stops writing it and the release that drops it are different releases,
  with a major version between them.

A plugin's migrations work the same way, in its own folder, under its own
source name, so its numbering is independent of the core's.

## Rolling back

Until the database step has run, **Roll back the files** on Admin → Update
puts the previous version back: the old files were moved aside rather than
deleted. After the database step there is no button, and the honest answer is
the backup — Admin → Backup & import, restored into an empty database with
`storage/config.php` beside it.

This is why the updater does the files first and the database last, and why
it asks before the last step rather than running the whole thing on one
click.

## If you write a plugin

Read PLUGINS.md for the hooks themselves. What matters across versions:

- **Hooks are the contract.** Anything in the table in PLUGINS.md is covered
  by the version rules above. Anything else — a core class you called
  directly, a template you copied, a column you read — is not, and a minor
  release may move it.
- **Declare what you need.** `Requires:` in the plugin header names the
  minimum core version. A plugin whose requirement is not met is left
  deactivated with a line saying so, rather than fataling on a site somebody
  has already upgraded.
- **Your tables are yours.** Name them `p_<slug>_…` and create them from your
  own migrations. The bundled plugins are the exception and use the tables the
  original schema already had (`series_favorites`, `ratings`), because their
  data predates them.
- **A plugin that throws is switched off, not tolerated.** Ten failures in ten
  minutes and the loader deactivates it with the reason in the log. Test
  against a new minor release before your congregation does.

## If you write a theme

- **Every core template's variables** are listed in THEMES.md, and a minor
  release may add to that list but will not remove from it or rename.
- **A theme that overrides a core template takes on that template's upkeep.**
  A new version's template may gain a block yours will not have. Override the
  smallest thing that gets you what you want — a partial rather than the page
  — and you will have less to revisit.
- **A theme whose `functions.php` throws** is swapped for the default with a
  notice, rather than taking the site down. That is a safety net, not a plan.

## Upgrading from the Next.js deployment

That is not an upgrade but a migration, and it has its own tooling and its own
document: `tools/export-from-nextjs/README.md`. The short version is that the
old database is exported to a zip from a laptop and read in at Admin → Backup
& import, ids and timestamps intact, so links people have bookmarked still
resolve.

## Release zips

A release built by the maintainers carries `MANIFEST.json` — every file with
its SHA-256 — and `MANIFEST.sig`, an Ed25519 signature of that manifest. The
updater checks the signature against the key built into the site *before
anything is unpacked*, then checks every file against the manifest as it goes.
A zip that was not built by the maintainers, or was changed after it was, is
refused with that as the reason.

Building one yourself — `php tools/release/build.php` — produces an unsigned
zip unless `RELEASE_SIGNING_KEY` is set. The updater will not install an
unsigned zip; for your own builds, deploy by FTP and use *Finish the update*.

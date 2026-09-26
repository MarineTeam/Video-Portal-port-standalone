# Exporting the Next.js deployment

`export.mjs` reads the old site's Postgres database and writes a zip that the
port reads at **Admin → Backup & import**. It runs on a laptop, not on the
church's hosting, and it never writes to the old database.

## What you need

- Node 18 or newer.
- The old deployment's **direct** Postgres connection string — the one that
  reaches the database itself, not a connection pooler. On Vercel or Neon it
  is usually the variable called `DIRECT_URL` or `POSTGRES_URL_NON_POOLING`;
  a pooled URL cannot hold the cursor this script reads through.
- Network access to that database. Most hosts want your address allowed
  first.

## Running it

```sh
cd tools/export-from-nextjs
npm install pg
node export.mjs "postgres://user:password@host:5432/database?sslmode=require"
```

It prints a line per table and writes `nextjs-export-<date>.zip` beside
itself. A large site takes a few minutes; nothing is held in memory but one
table at a time.

Options:

| Option | What it does |
|---|---|
| `--out <file>` | where to write the zip |
| `--skip A,B` | leave tables out — `--skip ViewEvent,AuditLog` drops the two that are usually the largest and the least missed |
| `--only A,B` | export only these tables |
| `--store` | do not compress: faster, and a much larger file |

## What is not exported, and why

Two tables are never written, whatever you ask for:

- **PushSubscription** — a subscription is signed by the old site's VAPID
  keys and means nothing to the new one. Members turn notifications back on
  once, per device.
- **TvDevice** — each row is a login token for a screen. Pair the screens
  again; it is a minute each.

Everything else comes over as it is, ids and timestamps included, so a link
anybody has bookmarked still resolves. In particular **share-link passwords
are kept** in the `scrypt$salt$key` form Node wrote them in: the port
verifies that form with its own scrypt and replaces it with a modern hash the
first time somebody unlocks the link, so a link shared with a password still
opens.

Two things to know afterwards:

- **API keys come over but do not work.** The new site hashes keys
  differently, so the rows arrive for their names and history; issue fresh
  keys at Admin → API keys and delete the old rows.
- **Nobody gains a password.** Members who signed in through Auth0 or another
  provider keep doing so; with local sign-in enabled they can use "forgot
  password" instead.

## Importing it

Upload the zip at **Admin → Backup & import** on the new site. It is read a
batch per request, so a shared host will not time out, and the browser tab
can be left open. Nothing is deleted and nothing is overwritten: a row whose
id is already there is counted as "already here" and left alone, which means
stopping half way and starting again is safe.

When it finishes, the screen shows each table beside the count the export
recorded for it. Those two numbers agreeing is the check that the migration
worked; anything short is listed first, with the database's own reason for
each row it refused.

## Files

Files kept in Bunny Storage do not move: the rows point at the same paths
they always did. If the new site's Files slot is also Bunny Storage, they are
already where they belong and there is nothing to do.

If it is not, the import screen offers to pull them onto this server once the
rows are in, a batch per request like everything else here. Set Bunny Storage
up at Admin → Services first, so the site has something to read them with.

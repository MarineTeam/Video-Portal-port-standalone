#!/usr/bin/env node
// Export a running Next.js deployment's database to a zip the port reads at
// Admin -> Backup & import.
//
// Run it from a laptop, against the old deployment's direct Postgres
// connection string. It reads and never writes:
//
//   npm install pg
//   node export.mjs "postgres://user:pass@host:5432/db?sslmode=require"
//
// pg rather than Prisma on purpose: the export has to run against a
// deployment whose schema has drifted from whatever prisma/schema.prisma
// this laptop has, and a client that validates the schema first would
// refuse the one export that matters.
//
// Every table becomes <Model>.ndjson — one JSON object per line, ids and
// timestamps exactly as Postgres gave them — beside a manifest.json holding
// the row count per table, which the importer checks its own counts against.
//
// No dependency but pg: the zip is written here (store or deflate, with a
// CRC of each entry), because asking somebody to install a build toolchain
// before they can leave a platform is its own kind of lock-in.

import { createWriteStream } from 'node:fs';
import { deflateRawSync } from 'node:zlib';
import { Buffer } from 'node:buffer';
import process from 'node:process';
import pg from 'pg';

const FORMAT = 'marine-team-nextjs-export/1';

// Every model in the data model, which is every table the importer knows.
// A table this list has and the deployment does not is reported and skipped:
// an older site simply has fewer of them.
const MODELS = [
  'User', 'UserIdentity', 'CategoryEditor', 'SeriesEditor', 'Category', 'Series',
  'Video', 'Chapter', 'Speaker', 'SeriesFavorite', 'VideoFavorite', 'BookHymn',
  'BookPage', 'BookHymnDetail', 'FileFavorite', 'ServicePlan', 'ServiceTeam',
  'ServiceTeamMember', 'ServiceAssignment', 'ServiceBlockout', 'ServicePlanItem',
  'Comment', 'CommentReport', 'WatchProgress', 'FileAsset', 'ReadingProgress',
  'ReadingMark', 'ApiKey', 'AuditLog', 'Plugin', 'PluginCategoryOverride',
  'PermissionGroup', 'GroupAssignment', 'Rating', 'SeriesWatchLater',
  'CategoryWatchLater', 'VideoWatchLater', 'PushSubscription', 'DraftRevision',
  'Webhook', 'Announcement', 'LiveStream', 'HomeRow', 'Subscription',
  'PendingNotification', 'Playlist', 'PlaylistItem', 'Reaction', 'ViewEvent',
  'HymnLookup', 'SeriesViewerGroup', 'SeriesViewer', 'VideoViewerGroup',
  'VideoViewer', 'SermonOutlineAnswer', 'SermonNote', 'SlugAlias', 'ShareLink',
  'ShareLinkRecipient', 'DownloadPolicy', 'DownloadPolicyGroup',
  'DownloadPolicyUser', 'AuthSettings', 'AuthorizedEmail',
  'UnauthorizedAccessAttempt', 'Notification', 'BrandSettings', 'Schedule',
  'ScheduleSource', 'Person', 'PersonAlias', 'CalendarEvent',
  'CalendarEventPerson', 'Event', 'EventSeries', 'EventRegistration', 'Form',
  'FormField', 'FormSubmission', 'FormAnswer', 'PrayerRequest',
  'PrayerIntercession', 'SmallGroup', 'SmallGroupMember', 'GroupMessage',
  'DiscussionGuide', 'DiscussionGuideItem', 'SmallGroupMeeting',
  'GroupAttendance', 'Broadcast', 'BroadcastRecipient', 'VideoFeed',
  'LiveChatMessage', 'LiveChatMute', 'TvDevice',
];

// Credentials that mean something only to the old deployment. A push
// subscription is signed by that site's VAPID keys and a television token is
// that site's login; copying either moves a live secret into a second place
// without making anything work. Members turn notifications back on once, and
// the screens are paired again.
//
// Share-link passwords are the opposite case and are kept exactly as they
// are: Node wrote them as scrypt$salt$key, the port verifies that form with
// its own scrypt and re-hashes on the first correct unlock, so a link that
// was shared with a password still opens.
const NEVER_EXPORTED = new Set(['PushSubscription', 'TvDevice']);

const PAGE = 2000;

function usage(message) {
  if (message) console.error(`\n${message}\n`);
  console.error(`Usage: node export.mjs <postgres-url> [options]

  --out <file>        where to write the zip (default: nextjs-export-<date>.zip)
  --skip <A,B>        tables to leave out, e.g. --skip ViewEvent,AuditLog
  --only <A,B>        only these tables
  --store             do not compress (faster, a much larger file)

The URL is the old deployment's direct Postgres connection string. Nothing is
written to that database.`);
  process.exit(message ? 1 : 0);
}

function parseArgs(argv) {
  const args = { skip: new Set(), only: null, store: false, out: null, url: null };
  for (let i = 0; i < argv.length; i++) {
    const arg = argv[i];
    if (arg === '--help' || arg === '-h') usage();
    else if (arg === '--store') args.store = true;
    else if (arg === '--out') args.out = argv[++i];
    else if (arg === '--skip') for (const t of (argv[++i] ?? '').split(',')) args.skip.add(t.trim());
    else if (arg === '--only') args.only = new Set((argv[++i] ?? '').split(',').map((t) => t.trim()));
    else if (arg.startsWith('-')) usage(`Unknown option ${arg}.`);
    else args.url = arg;
  }
  if (!args.url) args.url = process.env.DATABASE_URL ?? null;
  if (!args.url) usage('Give the old deployment’s Postgres connection string, or set DATABASE_URL.');
  return args;
}

// -- A zip, written as we go -------------------------------------------------

const CRC_TABLE = (() => {
  const table = new Int32Array(256);
  for (let n = 0; n < 256; n++) {
    let c = n;
    for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
    table[n] = c;
  }
  return table;
})();

function crc32(buffer, seed = 0) {
  let c = ~seed;
  for (let i = 0; i < buffer.length; i++) c = CRC_TABLE[(c ^ buffer[i]) & 0xff] ^ (c >>> 8);
  return ~c >>> 0;
}

/**
 * The smallest zip writer that produces a file every unzip tool reads: one
 * local header and one central-directory entry per file, no zip64. An entry
 * is held in memory while it is built, which is what the paging below keeps
 * bounded.
 */
class Zip {
  constructor(path) {
    this.out = createWriteStream(path);
    this.offset = 0;
    this.entries = [];
  }

  write(chunk) {
    this.offset += chunk.length;
    if (!this.out.write(chunk)) return new Promise((resolve) => this.out.once('drain', resolve));
    return Promise.resolve();
  }

  async add(name, body, store) {
    if (body.length >= 0xffffffff) {
      throw new Error(`${name} is over 4 GB, which this zip format cannot hold. Re-run with --skip ${name.replace(/\.ndjson$/, '')}.`);
    }
    const nameBytes = Buffer.from(name, 'utf8');
    const crc = crc32(body);
    const deflated = store ? body : deflateRawSync(body, { level: 6 });
    // Compressing something already compressed can grow it; keep whichever
    // is smaller, and say which in the entry's method.
    const compressed = deflated.length < body.length ? deflated : body;
    const method = compressed === body ? 0 : 8;
    const header = Buffer.alloc(30);
    header.writeUInt32LE(0x04034b50, 0);
    header.writeUInt16LE(20, 4);
    header.writeUInt16LE(0x0800, 6); // the name is UTF-8
    header.writeUInt16LE(method, 8);
    header.writeUInt32LE(0, 10); // time and date: an export has no useful mtime
    header.writeUInt32LE(crc, 14);
    header.writeUInt32LE(compressed.length, 18);
    header.writeUInt32LE(body.length, 22);
    header.writeUInt16LE(nameBytes.length, 26);
    this.entries.push({ nameBytes, crc, method, compressed: compressed.length, size: body.length, at: this.offset });
    await this.write(header);
    await this.write(nameBytes);
    await this.write(compressed);
  }

  async close() {
    const start = this.offset;
    for (const e of this.entries) {
      const central = Buffer.alloc(46);
      central.writeUInt32LE(0x02014b50, 0);
      central.writeUInt16LE(20, 4);
      central.writeUInt16LE(20, 6);
      central.writeUInt16LE(0x0800, 8);
      central.writeUInt16LE(e.method, 10);
      central.writeUInt32LE(0, 12);
      central.writeUInt32LE(e.crc, 16);
      central.writeUInt32LE(e.compressed, 20);
      central.writeUInt32LE(e.size, 24);
      central.writeUInt16LE(e.nameBytes.length, 28);
      central.writeUInt32LE(e.at, 42);
      await this.write(central);
      await this.write(e.nameBytes);
    }
    const end = Buffer.alloc(22);
    end.writeUInt32LE(0x06054b50, 0);
    end.writeUInt16LE(this.entries.length, 8);
    end.writeUInt16LE(this.entries.length, 10);
    end.writeUInt32LE(this.offset - start, 12);
    end.writeUInt32LE(start, 16);
    await this.write(end);
    await new Promise((resolve, reject) => this.out.end((err) => (err ? reject(err) : resolve())));
  }
}

// -- Reading the old database ------------------------------------------------

/** The tables the deployment actually has, so a missing one is a note rather than an error. */
async function tablesPresent(client) {
  const { rows } = await client.query(
    "SELECT table_name FROM information_schema.tables WHERE table_schema = current_schema() AND table_type = 'BASE TABLE'",
  );
  return new Set(rows.map((r) => r.table_name));
}

/**
 * Every row of one table, a page at a time, through a server-side cursor.
 *
 * A cursor rather than LIMIT/OFFSET because half these tables have no single
 * id to order by, and rather than one big SELECT because a laptop should not
 * have to hold ViewEvent in memory.
 */
async function* rowsOf(client, table) {
  await client.query('BEGIN READ ONLY');
  try {
    await client.query(`DECLARE mt_export NO SCROLL CURSOR FOR SELECT * FROM "${table}"`);
    for (;;) {
      const { rows } = await client.query(`FETCH ${PAGE} FROM mt_export`);
      if (rows.length === 0) break;
      yield rows;
    }
    await client.query('CLOSE mt_export');
  } finally {
    await client.query('COMMIT');
  }
}

/**
 * JSON that keeps what Postgres gave us.
 *
 * Timestamps arrive as text (see the type parsers above) and are written
 * exactly as they came, which is the whole point: a Date here would be read
 * in this laptop's time zone and written back in UTC, quietly moving every
 * service time in the export. A Buffer (bytea) becomes base64 under a
 * marker, and a bigint its digits, so neither is mangled into a broken
 * string.
 */
function line(row) {
  return JSON.stringify(row, (_key, value) => {
    if (value instanceof Date) return value.toISOString();
    if (Buffer.isBuffer(value)) return { $base64: value.toString('base64') };
    if (typeof value === 'bigint') return value.toString();
    return value;
  });
}

async function main() {
  const args = parseArgs(process.argv.slice(2));
  const out = args.out ?? `nextjs-export-${new Date().toISOString().slice(0, 10)}.zip`;
  // Timestamps as the text Postgres holds, before anything is read. pg
  // otherwise turns a `timestamp without time zone` into a Date in this
  // laptop's zone, and every instant in the export moves by the offset
  // between that laptop and the church.
  pg.types.setTypeParser(1114, (v) => v);
  pg.types.setTypeParser(1184, (v) => v);
  const client = new pg.Client({ connectionString: args.url });
  await client.connect();

  const present = await tablesPresent(client);
  const zip = new Zip(out);
  const manifest = { format: FORMAT, exportedAt: new Date().toISOString(), tables: {}, notes: {} };

  try {
    for (const model of MODELS) {
      if (args.only && !args.only.has(model)) continue;
      if (NEVER_EXPORTED.has(model)) {
        manifest.notes[model] = 'not exported: a credential that only works on the old site';
        console.log(`  ${model.padEnd(28)} skipped (credentials)`);
        continue;
      }
      if (args.skip.has(model)) {
        manifest.notes[model] = 'not exported: asked for with --skip';
        console.log(`  ${model.padEnd(28)} skipped (--skip)`);
        continue;
      }
      if (!present.has(model)) {
        manifest.notes[model] = 'this deployment has no such table';
        console.log(`  ${model.padEnd(28)} absent`);
        continue;
      }
      const parts = [];
      let count = 0;
      for await (const rows of rowsOf(client, model)) {
        for (const row of rows) parts.push(line(row));
        count += rows.length;
        process.stdout.write(`  ${model.padEnd(28)} ${count}\r`);
      }
      await zip.add(`${model}.ndjson`, Buffer.from(parts.length === 0 ? '' : `${parts.join('\n')}\n`, 'utf8'), args.store);
      manifest.tables[model] = count;
      console.log(`  ${model.padEnd(28)} ${count}`);
    }
    await zip.add('manifest.json', Buffer.from(`${JSON.stringify(manifest, null, 2)}\n`, 'utf8'), args.store);
    await zip.close();
  } finally {
    await client.end();
  }

  const total = Object.values(manifest.tables).reduce((a, b) => a + b, 0);
  console.log(`\nWrote ${out}: ${total} rows in ${Object.keys(manifest.tables).length} tables.`);
  console.log('Upload it at Admin → Backup & import on the new site.');
}

main().catch((error) => {
  console.error(`\nExport failed: ${error.message}`);
  process.exit(1);
});

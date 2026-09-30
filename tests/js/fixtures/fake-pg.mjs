/**
 * Enough of `pg` to run tools/export-from-nextjs/export.mjs without a
 * Postgres: a client that answers the four queries the exporter makes, from
 * rows given to it in MT_FAKE_PG (a JSON file: { "Model": [row, ...] }).
 *
 * The exporter is the one program in this port that runs against somebody
 * else's database, once, on the day they leave the old platform. Its output
 * cannot be checked there, so it is checked here: the real exporter writes a
 * real zip, and the real importer reads it.
 */
import { readFileSync } from 'node:fs';
import process from 'node:process';

const data = JSON.parse(readFileSync(process.env.MT_FAKE_PG, 'utf8'));

class Client {
  constructor() {
    this.cursor = null;
  }

  async connect() {}

  async end() {}

  async query(sql) {
    if (sql.startsWith('SELECT table_name')) {
      return { rows: Object.keys(data).map((table_name) => ({ table_name })) };
    }
    const declare = /DECLARE mt_export NO SCROLL CURSOR FOR SELECT \* FROM "([^"]+)"/.exec(sql);
    if (declare) {
      this.cursor = [...(data[declare[1]] ?? [])];
      return { rows: [] };
    }
    const fetch = /^FETCH (\d+) FROM mt_export$/.exec(sql);
    if (fetch) {
      return { rows: (this.cursor ?? []).splice(0, Number(fetch[1])) };
    }
    if (/^(BEGIN READ ONLY|COMMIT|CLOSE mt_export)$/.test(sql)) {
      return { rows: [] };
    }
    throw new Error(`the exporter asked something this stands in for does not know: ${sql}`);
  }
}

export default { Client, types: { setTypeParser() {} } };

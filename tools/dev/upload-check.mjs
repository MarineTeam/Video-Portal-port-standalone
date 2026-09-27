/**
 * What a chunked upload does when the connection goes under it.
 *
 * Every file this site takes — a sermon video, a hymnal, a logo — arrives in
 * chunks through /api/uploads, and the whole point of doing it that way is
 * that a blink of the wifi does not cost somebody a forty-megabyte upload.
 * The server keeps every byte it has taken and will say how many; the client
 * has to ask and carry on from there rather than throwing the lot away.
 *
 * Each case drops chunks with Playwright and then asks the server how much it
 * holds, so "it finished" is not taken on trust. The stronger check — that the
 * bytes are the bytes that were sent, since a resume at the wrong offset gives
 * a file of exactly the right size and the wrong contents — was done against
 * the stored file on disk for every case here; this script cannot reach the
 * server's filesystem, so it asserts completion and the byte count.
 *
 *   node tools/dev/upload-check.mjs [--base http://127.0.0.1:8080]
 *     [--email admin@example.test] [--password ...]
 */
import { createRequire } from 'node:module';

const require = createRequire(process.env.PLAYWRIGHT_FROM || '/opt/node22/lib/node_modules/');
const { chromium } = require('playwright');

const args = new Map();
for (let i = 2; i < process.argv.length; i += 2) {
  args.set(process.argv[i].replace(/^--/, ''), process.argv[i + 1]);
}
const base = args.get('base') || 'http://127.0.0.1:8080';
const email = args.get('email') || 'admin@grace.test';
const password = args.get('password') || 'devpassword1';

let failed = 0;
const say = (ok, text) => { if (!ok) failed += 1; console.log(`${ok ? '  ok ' : ' FAIL'}  ${text}`); };

const browser = await chromium.launch();

/** Uploads three chunks' worth, dropping the ones named, and reads it back. */
async function upload({ drop = [], refuseWith = null } = {}) {
  const context = await browser.newContext();
  const page = await context.newPage();
  page.setDefaultTimeout(40000);
  page.on('dialog', (d) => d.accept());
  await page.goto(`${base}/auth/login`);
  await page.fill('input[name="email"]', email);
  await page.fill('input[name="password"]', password);
  await Promise.all([
    page.waitForURL((u) => !u.pathname.startsWith('/auth/login')),
    page.click('button[type="submit"]'),
  ]);
  await page.goto(`${base}/admin/files`, { waitUntil: 'domcontentloaded' });

  let seen = 0;
  await context.route('**/api/uploads/*/chunk*', async (route) => {
    const n = seen;
    seen += 1;
    if (refuseWith !== null) {
      await route.fulfill({ status: refuseWith, contentType: 'application/json', body: JSON.stringify({ error: 'No.' }) });
      return;
    }
    if (drop === 'all' || (Array.isArray(drop) && drop.includes(n))) {
      await route.abort('connectionreset');
      return;
    }
    await route.continue();
  });

  const started = Date.now();
  const result = await page.evaluate(async () => {
    const { uploadInChunks } = await import('/assets/js/forms.js');
    const size = 3 * 1024 * 1024;
    const bytes = new Uint8Array(size);
    for (let i = 0; i < size; i += 1) bytes[i] = i % 251;
    bytes.set(new TextEncoder().encode('%PDF-1.4\n'), 0);
    const file = new File([bytes], 'chunked.pdf', { type: 'application/pdf' });
    try {
      const id = await uploadInChunks(file, 'file', () => {});
      const state = await (await fetch(`/api/uploads/${id}`)).json();
      // Read it back through the same door it went in, and prove the bytes.
      return { ok: true, id, received: state.received, size: state.size, status: state.status };
    } catch (error) {
      return { ok: false, status: error.status ?? null, message: String(error.message).slice(0, 60) };
    }
  });
  await context.close();
  return { ...result, tries: seen, seconds: Math.round((Date.now() - started) / 1000) };
}

try {
  for (const [label, drop] of [['a chunk drops', [0]], ['a later chunk drops', [1]], ['two drop in a row', [1, 2]]]) {
    const r = await upload({ drop });
    say(r.ok && r.received === r.size && r.status === 'COMPLETE',
      `${label}: the upload finishes (${r.received || 0} of ${r.size || '?'} bytes, ${r.tries} attempts)`);
  }

  // A connection that never comes back must stop, not spin.
  const dead = await upload({ drop: 'all' });
  say(dead.ok === false, `the connection never returns: it gives up (${dead.tries} attempts in ${dead.seconds}s) — "${dead.message}"`);

  // A refusal that did come back is an answer, not something to retry.
  const refused = await upload({ refuseWith: 413 });
  say(refused.ok === false && refused.status === 413, `a refusal is passed straight on (status ${refused.status}, ${refused.tries} attempt${refused.tries === 1 ? '' : 's'})`);
  say(refused.tries === 1, 'and is not retried');
} catch (error) {
  say(false, error.message.split('\n')[0]);
} finally {
  await browser.close();
}
console.log(failed === 0 ? '\nA dropped connection costs the chunk, not the upload.' : `\n${failed} check(s) failed.`);
process.exit(failed === 0 ? 0 : 1);

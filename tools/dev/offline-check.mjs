/**
 * Does a saved book actually open with nothing to fetch it from?
 *
 * The offline shell is the one part of this site no PHP test can reach: it
 * needs a service worker, Cache Storage and a real PDF renderer. That is where
 * the bug lived that this check was written after — the worker looked for the
 * reader libraries under a prefix nothing saved them at, so a saved book
 * opened to a blank page on the one occasion it had been saved for.
 *
 * What it does, against a site you are already running:
 *   1. signs in, opens a book and saves it to the device;
 *   2. takes pdf.js off the server, so only a device that saved it has a copy;
 *   3. opens the offline shell, opens the book, and measures the page.
 *
 * Ink on the canvas is the assertion: a reader that fails draws nothing, and
 * "the element exists" would pass for a blank one.
 *
 * Run it with a dev server up and Playwright available:
 *   node tools/dev/offline-check.mjs --book <fileId> [--base http://127.0.0.1:8080]
 *     [--email admin@example.test] [--password ...]
 *
 * It puts pdf.js back whatever happens, including on failure.
 */
import { createRequire } from 'node:module';
import { execFileSync } from 'node:child_process';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const require = createRequire(process.env.PLAYWRIGHT_FROM || '/opt/node22/lib/node_modules/');
const { chromium } = require('playwright');

const args = new Map();
for (let i = 2; i < process.argv.length; i += 2) {
  args.set(process.argv[i].replace(/^--/, ''), process.argv[i + 1]);
}
const base = args.get('base') || 'http://127.0.0.1:8080';
const book = args.get('book');
const email = args.get('email') || 'admin@grace.test';
const password = args.get('password') || 'devpassword1';
if (!book) {
  console.error('Give --book <fileId> for a PDF this account may read.');
  process.exit(2);
}

const root = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const viewers = join(root, 'public', 'vendor-js', 'pdfjs');
const parked = join(root, 'storage', 'tmp', 'pdfjs-parked-by-offline-check');

const steps = [];
const say = (ok, text) => { steps.push({ ok, text }); console.log(`${ok ? '  ok ' : ' FAIL'}  ${text}`); };

let moved = false;
const browser = await chromium.launch();
try {
  const context = await browser.newContext();
  const page = await context.newPage();
  page.setDefaultTimeout(20000);

  await page.goto(`${base}/auth/login`);
  await page.fill('input[name="email"]', email);
  await page.fill('input[name="password"]', password);
  await Promise.all([
    page.waitForURL((u) => !u.pathname.startsWith('/auth/login')),
    page.click('button[type="submit"]'),
  ]);
  await page.evaluate(async () => {
    await navigator.serviceWorker.register('/sw.js');
    await navigator.serviceWorker.ready;
  });
  say(true, 'signed in, with the service worker in control');

  await page.goto(`${base}/read/${book}`, { waitUntil: 'domcontentloaded' });
  const keep = page.locator('[data-reader-keep]');
  await keep.waitFor({ state: 'visible' });
  await keep.click();
  for (let i = 0; i < 60 && !/remove/i.test((await keep.textContent()).trim()); i++) {
    await page.waitForTimeout(500);
  }
  say(/remove/i.test((await keep.textContent()).trim()), 'the book is saved on the device');

  const cached = await page.evaluate(async () => {
    const cache = await caches.open('marine-team-books-v1');
    return (await cache.keys()).map((r) => new URL(r.url).pathname).sort();
  });
  say(cached.some((p) => p.includes('pdf.mjs')), `the reader library is saved with it (${cached.length} entries)`);

  // Only a device that saved it has a copy now.
  execFileSync('mv', [viewers, parked]);
  moved = true;
  say(true, 'pdf.js taken off the server');

  await page.goto(`${base}/offline.html`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(1000);
  const entry = page.locator('li', { hasText: /.*/ }).first();
  await entry.waitFor({ state: 'visible' });
  say(true, 'the offline shell lists what is saved');

  await entry.click();
  await page.waitForTimeout(1000);
  await page.locator('button', { hasText: 'Open the book' }).first().click();
  await page.waitForTimeout(8000);

  const drawn = await page.evaluate(() => [...document.querySelectorAll('canvas')].map((c) => {
    if (!c.width || !c.height) return 0;
    const data = c.getContext('2d').getImageData(0, 0, c.width, c.height).data;
    let ink = 0;
    for (let i = 0; i < data.length; i += 4) {
      if (data[i] < 200 || data[i + 1] < 200 || data[i + 2] < 200) ink += 1;
    }
    return ink;
  }));
  const ink = Math.max(0, ...drawn);
  say(ink > 200, `the page is drawn from the device's own copy (${ink} marked pixels)`);
} catch (error) {
  say(false, `threw: ${error.message.split('\n')[0]}`);
} finally {
  if (moved) {
    execFileSync('mv', [parked, viewers]);
    console.log('  ..   pdf.js put back');
  }
  await browser.close();
}

const failed = steps.filter((s) => !s.ok).length;
console.log(failed === 0 ? '\nA saved book opens with nothing to fetch it from.' : `\n${failed} step(s) failed.`);
process.exit(failed === 0 ? 0 : 1);

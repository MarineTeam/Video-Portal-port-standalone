/**
 * A service's running order kept on a device, and read back with no signal.
 *
 * The order and its words are saved as one small JSON file. What makes it
 * different from the other saved things is that it goes stale: a hymn swapped
 * on the Saturday leaves the phone holding last week's list, so the page asks
 * the server for the fingerprint alone — a request that carries no words — and
 * says so when the two differ.
 *
 *   node tools/dev/service-check.mjs --plan <planId> [--base http://127.0.0.1:8080]
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
const plan = args.get('plan');
const email = args.get('email') || 'admin@grace.test';
const password = args.get('password') || 'devpassword1';
if (!plan) {
  console.error('Give --plan <planId> for a published service plan with something in its order.');
  process.exit(2);
}

let failed = 0;
const say = (ok, text) => { if (!ok) failed += 1; console.log(`${ok ? '  ok ' : ' FAIL'}  ${text}`); };

const browser = await chromium.launch();
try {
  const context = await browser.newContext();
  const page = await context.newPage();
  page.setDefaultTimeout(20000);
  const errors = [];
  page.on('pageerror', (e) => errors.push(String(e).split('\n')[0]));

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

  await page.goto(`${base}/services/${plan}`, { waitUntil: 'domcontentloaded' });
  const keep = page.locator('[data-keep-service]');
  await keep.waitFor({ state: 'visible' });
  await keep.click();
  for (let i = 0; i < 40 && !/remove/i.test((await keep.textContent()).trim()); i += 1) {
    await page.waitForTimeout(250);
  }
  say(/remove/i.test((await keep.textContent()).trim()), 'the order is saved on the device');

  const entry = await page.evaluate(() => {
    const items = JSON.parse(localStorage.getItem('marine-offline-services') || '[]');
    return items[0] || null;
  });
  say(entry !== null && typeof entry.fingerprint === 'string' && entry.itemCount > 0,
    `the index names it (${entry ? `${entry.itemCount} in the order` : 'nothing saved'})`);

  // The offline shell, reading only what is on the device.
  await page.goto(`${base}/offline.html`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(1000);
  const row = page.locator('button.rowlink', { hasText: entry ? entry.title : 'Service' }).first();
  await row.waitFor({ state: 'visible' });
  await row.click();
  await page.waitForTimeout(1500);
  const shown = await page.locator('body').innerText();
  say(shown.includes(entry.title), 'the shell opens it');
  // The order is the point: a service that lists only its own name is a date.
  const listed = await page.locator('ul li').count();
  say(listed === entry.itemCount, `every item in the order is there (${listed} of ${entry.itemCount})`);

  // A hymn swapped after it was saved.
  await page.goto(`${base}/services/${plan}`, { waitUntil: 'domcontentloaded' });
  await page.evaluate(() => {
    const key = 'marine-offline-services';
    const items = JSON.parse(localStorage.getItem(key) || '[]');
    items[0].fingerprint = 'not-what-the-server-has';
    localStorage.setItem(key, JSON.stringify(items));
  });
  await page.reload({ waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(1500);
  const status = (await page.locator('[data-keep-service-status]').textContent()) || '';
  say(/changed/i.test(status), `a changed order is reported ("${status.trim().slice(0, 48)}")`);

  await page.locator('[data-keep-service]').click();
  await page.waitForTimeout(800);
  const gone = await page.evaluate(async () => ({
    index: JSON.parse(localStorage.getItem('marine-offline-services') || '[]').length,
    cached: (await (await caches.open('marine-team-services-v1')).keys()).length,
  }));
  say(gone.index === 0 && gone.cached === 0, 'removing it clears both the index and the cache');
  say(errors.length === 0, `the pages threw nothing${errors.length ? `: ${errors[0]}` : ''}`);
} catch (error) {
  say(false, error.message.split('\n')[0]);
} finally {
  await browser.close();
}
console.log(failed === 0 ? '\nA service order keeps, reads and refreshes on a device.' : `\n${failed} check(s) failed.`);
process.exit(failed === 0 ? 0 : 1);

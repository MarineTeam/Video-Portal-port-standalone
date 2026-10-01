/**
 * Times in the reader's own zone, on the pages that show them.
 *
 * The server writes every instant as UTC inside a <time datetime="…">, and
 * the browser is what turns it into the reader's zone. For most of this
 * port's life that conversion lived in player.js, which three pages load —
 * so sixteen of the seventeen templates that emit one showed UTC to the
 * reader instead. A rota an hour out in a British summer, or eight hours
 * out for a Californian church, with nothing to say it was wrong.
 *
 * No PHP test can see any of this, so it is driven here, in a browser told
 * it is in Los Angeles.
 *
 *   node tools/dev/local-time-check.mjs [--base http://127.0.0.1:8080]
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
// Eight hours from UTC in winter and seven in summer, so a conversion that
// did not happen is unmistakable and a daylight-saving slip shows up too.
const context = await browser.newContext({ timezoneId: 'America/Los_Angeles', locale: 'en-GB' });
const page = await context.newPage();

await page.goto(`${base}/auth/login`);
await page.fill('input[name=email]', email);
await page.fill('input[name=password]', password);
await page.click('button[type=submit]');
await page.waitForURL(`${base}/`);

// ------------------------------------------------- the pages as they load

const pages = ['/', '/prayer', '/services', '/calendar', '/live', '/admin/api-keys',
  '/admin/prayer', '/admin/broadcasts', '/admin/forms', '/admin/schedules', '/admin/live'];

let seen = 0;
const stragglers = [];
for (const path of pages) {
  const response = await page.goto(base + path, { waitUntil: 'networkidle' }).catch(() => null);
  if (!response || response.status() !== 200) {
    continue;
  }
  const times = await page.$$eval('time[datetime]', (ns) => ns.map((n) => ({
    attr: (n.getAttribute('datetime') || '').trim(),
    shown: n.textContent.trim(),
    done: n.hasAttribute('data-localised'),
  })));
  for (const t of times) {
    const dayOnly = /^\d{4}-\d{2}-\d{2}$/.test(t.attr);
    if (dayOnly) {
      // A day is not an instant. Moving it into a zone west of UTC would
      // put a Sunday service on the Saturday.
      if (t.done || t.shown !== t.attr) {
        stragglers.push(`${path}: the day ${t.attr} was moved into a zone, and shows "${t.shown}"`);
      }
      continue;
    }
    seen += 1;
    if (!t.done) {
      stragglers.push(`${path}: "${t.attr}" still shows "${t.shown}"`);
    }
  }
}

say(seen > 0, `the pages really do show instants (${seen} of them)`);
say(stragglers.length === 0, 'every instant is in the reader’s zone, and every bare day is left as a day');
for (const line of stragglers.slice(0, 10)) console.log(`         ${line}`);
if (stragglers.length > 10) console.log(`         …and ${stragglers.length - 10} more`);

// ------------------------------------------- and the ones drawn in later

// The prayer wall, the live chat and every admin table built from JSON add
// times after the page has loaded. Converting only what was there at load
// is the other half of the bug this replaces.
await page.goto(`${base}/prayer`, { waitUntil: 'networkidle' });

for (const [when, iso, expected] of [
  ['in summer', '2026-07-04T17:06:00.000Z', '10:06'],
  ['in winter', '2026-01-04T17:06:00.000Z', '09:06'],
]) {
  const got = await page.evaluate(async (stamp) => {
    const el = document.createElement('time');
    el.setAttribute('datetime', stamp);
    el.setAttribute('data-local-time', '');
    el.textContent = stamp;
    document.body.append(el);
    await new Promise((resolve) => { setTimeout(resolve, 150); });
    return el.textContent;
  }, iso);
  say(got.includes(expected), `a time added after the page loaded is converted ${when} (${got})`);
}

const nested = await page.evaluate(async () => {
  const box = document.createElement('div');
  box.innerHTML = '<ul><li><time datetime="2026-07-04T17:06:00.000Z" data-local-time>raw</time></li></ul>';
  document.body.append(box);
  await new Promise((resolve) => { setTimeout(resolve, 150); });
  return box.querySelector('time').textContent;
});
say(nested.includes('10:06'), `one arriving inside a subtree is converted too (${nested})`);

const day = await page.evaluate(async () => {
  const el = document.createElement('time');
  el.setAttribute('datetime', '2026-07-04');
  el.setAttribute('data-local-date', '');
  el.textContent = '2026-07-04';
  document.body.append(el);
  await new Promise((resolve) => { setTimeout(resolve, 150); });
  return el.textContent;
});
say(day === '2026-07-04', `a bare day added later is still that day (${day})`);

await browser.close();
console.log(failed === 0 ? '\nAll good.' : `\n${failed} failed.`);
process.exit(failed === 0 ? 0 : 1);

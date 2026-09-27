/**
 * What the offline shell shows of a saved rota, for somebody signed in and
 * for somebody who is not.
 *
 * The snapshot a visitor saves has the names cut out of it and says so with
 * namesWithheld. The shell used to ignore that: three dates, no names, no
 * reason — while the live page, on the same data, says "Sign in to see who is
 * on." Offline is where that matters more, because signing in is not an
 * option until there is a connection again.
 *
 *   node tools/dev/calendar-check.mjs [--base http://127.0.0.1:8080]
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
try {
  for (const signedIn of [false, true]) {
    const who = signedIn ? 'signed in' : 'a visitor';
    const context = await browser.newContext();
    const page = await context.newPage();
    page.setDefaultTimeout(20000);
    const errors = [];
    page.on('pageerror', (e) => errors.push(String(e).split('\n')[0]));

    await page.goto(`${base}/calendar`, { waitUntil: 'domcontentloaded' });
    if (signedIn) {
      await page.goto(`${base}/auth/login`);
      await page.fill('input[name="email"]', email);
      await page.fill('input[name="password"]', password);
      await Promise.all([
        page.waitForURL((u) => !u.pathname.startsWith('/auth/login')),
        page.click('button[type="submit"]'),
      ]);
      await page.goto(`${base}/calendar`, { waitUntil: 'domcontentloaded' });
    }
    await page.evaluate(async () => {
      await navigator.serviceWorker.register('/sw.js');
      await navigator.serviceWorker.ready;
    });
    await page.reload({ waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(600);

    const snapshot = await page.evaluate(async () => {
      const taken = await (await fetch('/api/sync/snapshot')).json();
      const cache = await caches.open('marine-team-calendar-v1');
      await cache.put('/offline-calendar/snapshot.json', new Response(JSON.stringify(taken), { headers: { 'Content-Type': 'application/json' } }));
      localStorage.setItem('marine-offline-calendar', JSON.stringify({ cacheUrl: '/offline-calendar/snapshot.json', savedAt: new Date().toISOString() }));
      return { withheld: taken.namesWithheld === true, people: (taken.people || []).length, events: (taken.events || []).length };
    });
    say(snapshot.withheld !== signedIn, `${who}: the snapshot ${snapshot.withheld ? 'withholds' : 'carries'} names (${snapshot.people} people, ${snapshot.events} events)`);

    await page.goto(`${base}/offline.html`, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(1000);
    await page.locator('button.rowlink', { hasText: 'Calendar' }).first().click();
    await page.waitForTimeout(1500);

    const text = await page.locator('body').innerText();
    const explained = text.includes('Sign in to see who is on');
    const chooser = text.includes('You are');
    const rows = await page.locator('ul li').count();

    say(explained === !signedIn, `${who}: the missing names are ${explained ? 'explained' : 'not mentioned'}`);
    // A chooser of one option cannot narrow anything.
    say(chooser === signedIn, `${who}: the "You are" chooser is ${chooser ? 'offered' : 'left out'}`);
    say(rows === snapshot.events, `${who}: every saved date is listed (${rows} of ${snapshot.events})`);
    say(errors.length === 0, `${who}: the shell threw nothing${errors.length ? `: ${errors[0]}` : ''}`);
    await context.close();
  }
} catch (error) {
  say(false, error.message.split('\n')[0]);
} finally {
  await browser.close();
}
console.log(failed === 0 ? '\nThe saved rota reads correctly either way.' : `\n${failed} check(s) failed.`);
process.exit(failed === 0 ? 0 : 1);

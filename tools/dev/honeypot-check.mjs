/**
 * The honeypot on the public forms anybody can post to.
 *
 * It only works if the field is really in the form, really invisible, and
 * really left alone by the browser. A honeypot a member can see, tab into or
 * have autofilled is worse than none: their prayer request or their sign-up
 * is answered "thank you" and thrown away, and nothing anywhere says so.
 *
 * The three halves — the field in the template, the rule in the stylesheet
 * that parks it off the page, and the check on the server — live far apart,
 * and losing any one of them leaves the other two looking fine. Each was
 * removed in turn to see this fail.
 *
 *   node tools/dev/honeypot-check.mjs [--base http://127.0.0.1:8080]
 *     [--email admin@example.test] [--password ...]
 */
import { createRequire } from 'node:module';
const require = createRequire('/opt/node22/lib/node_modules/');
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
// A profile that has addresses and profiles saved, as a real one does.
const context = await browser.newContext();
const page = await context.newPage();

// The event and the form are whichever the site has; only their index
// addresses are fixed, so this does not go stale with the dev data.
async function firstUnder(index, prefix) {
  await page.goto(base + index, { waitUntil: 'domcontentloaded' });
  return page.evaluate((p) => {
    const link = [...document.querySelectorAll(`a[href*="${p}/"]`)]
      .map((a) => new URL(a.href).pathname)
      .find((h) => h.startsWith(p + '/') && !h.includes('.'));
    return link ?? null;
  }, prefix);
}

const forms = [['prayer', '/prayer']];
for (const [what, index, prefix] of [['an event', '/events', '/events'], ['a form', '/forms', '/forms']]) {
  const found = await firstUnder(index, prefix);
  if (found === null) {
    console.log(`  --  no ${what} on this site to check`);
    continue;
  }
  forms.push([what, found]);
}

for (const [where, path] of forms) {
  await page.goto(base + path, { waitUntil: 'networkidle' });
  const hp = page.locator('input[name=website]');
  if (await hp.count() === 0) { say(false, `${where}: there is no honeypot on this form`); continue; }

  // Not isVisible(): a field parked at left:-10000px counts as visible to
  // Playwright, and the question here is whether a person can see it.
  const box = await hp.boundingBox();
  say(box === null || box.x + box.width < 0 || box.width <= 1, `${where}: it is off the page, not merely small`);

  // Parking something ten thousand pixels away is only invisible if it does
  // not drag the page out with it.
  const overflows = await page.evaluate(() =>
    document.documentElement.scrollWidth > document.documentElement.clientWidth + 1);
  say(!overflows, `${where}: and does not give the page a sideways scrollbar`);

  // Tabbing through the form the way somebody using a keyboard does.
  const reachable = await page.evaluate(() => {
    const field = document.querySelector('input[name=website]');
    field.focus();
    return document.activeElement === field && field.tabIndex >= 0;
  });
  say(!reachable, `${where}: the tab key does not land on it`);

  say(
    await hp.getAttribute('autocomplete') === 'off',
    `${where}: the browser is asked not to fill it`,
  );
  const hidden = await hp.evaluate((n) => n.closest('[aria-hidden="true"]') !== null);
  say(hidden, `${where}: a screen reader is not told to read it`);

  // What a browser actually does: fill the form the way a person would and
  // see whether anything put a value in the honeypot along the way.
  await page.evaluate(() => {
    for (const el of document.querySelectorAll('form input:not([type=hidden]), form textarea')) {
      if (el.name === 'website' || el.type === 'checkbox' || el.type === 'radio' || el.type === 'file') continue;
      el.focus();
      el.value = el.type === 'email' ? 'someone@example.test' : 'Something a member typed';
      el.dispatchEvent(new Event('input', { bubbles: true }));
    }
  });
  say(await hp.inputValue() === '', `${where}: filling the rest of the form leaves it empty`);
}


// ------------------------------------------- and what it does to a request

const mark = `honeypot check ${Date.now()}`;

async function askForPrayer(honey) {
  await page.goto(`${base}/prayer`, { waitUntil: 'networkidle' });
  await page.fill('[data-prayer-form] textarea[name=body], [data-prayer-form] input[name=body]', honey ? `${mark} (a bot)` : `${mark} (a member)`);
  await page.fill('[data-prayer-form] input[name=name]', 'Someone');
  if (honey) await page.evaluate(() => { document.querySelector('input[name=website]').value = 'http://spam.example'; });
  const answer = page.waitForResponse((r) => r.url().includes('/api/prayer') && r.request().method() === 'POST');
  await page.click('[data-prayer-form] button[type=submit]');
  const r = await answer;
  return { status: r.status(), body: await r.text() };
}

const bot = await askForPrayer(true);
say(bot.status === 201, `a request with the honeypot filled is answered as if it worked (${bot.status})`);
say(
  !/spam|honeypot|bot|rejected|refused/i.test(bot.body),
  'and the answer gives nothing away about why',
);

const member = await askForPrayer(false);
say(member.status === 201, `a request with it left alone is accepted (${member.status})`);

// Neither is on the wall: every request waits for a moderator, which is the
// point of the wall. So the moderator's queue is where the two part company.
await page.goto(`${base}/auth/login`);
await page.fill('input[name=email]', email);
await page.fill('input[name=password]', password);
await page.click('button[type=submit]');
await page.waitForURL(`${base}/`);
await page.goto(`${base}/admin/prayer`, { waitUntil: 'networkidle' });
const queue = await page.locator('body').innerText();
say(queue.includes(`${mark} (a member)`), "the member's request is waiting for a moderator");
say(!queue.includes(`${mark} (a bot)`), "the bot's was never kept, though it was told thank you");

await browser.close();
console.log(failed === 0 ? '\nAll good.' : `\n${failed} failed.`);
process.exit(failed === 0 ? 0 : 1);

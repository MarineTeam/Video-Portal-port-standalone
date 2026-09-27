/**
 * What the service worker answers when something asks for part of a saved file.
 *
 * A saved video is played straight out of Cache Storage, and a media element
 * seeks by asking for byte ranges. Cache Storage always replays the whole
 * response, so the worker slices it itself — and getting that arithmetic wrong
 * is invisible until somebody is offline with a video that will not play.
 *
 * Each case below is the request and the answer HTTP requires, measured
 * against a hundred-byte file whose byte i has the value i, so the numbers
 * say which bytes actually came back rather than only how many.
 *
 *   node tools/dev/range-check.mjs [--base http://127.0.0.1:8080]
 */
import { createRequire } from 'node:module';

const require = createRequire(process.env.PLAYWRIGHT_FROM || '/opt/node22/lib/node_modules/');
const { chromium } = require('playwright');

const args = new Map();
for (let i = 2; i < process.argv.length; i += 2) {
  args.set(process.argv[i].replace(/^--/, ''), process.argv[i + 1]);
}
const base = args.get('base') || 'http://127.0.0.1:8080';

// range, expected status, expected Content-Range, expected [first, last] byte
// (null for an empty body), and why the case is here.
const cases = [
  ['bytes=0-9', 206, 'bytes 0-9/100', [0, 9], 'the opening bytes a player reads first'],
  ['bytes=0-0', 206, 'bytes 0-0/100', [0, 0], 'the one-byte probe Safari opens with'],
  ['bytes=10-', 206, 'bytes 10-99/100', [10, 99], 'everything from a seek onwards'],
  ['bytes=-16', 206, 'bytes 84-99/100', [84, 99], 'the LAST sixteen bytes: an MP4 keeps its moov atom at the end'],
  ['bytes=-500', 206, 'bytes 0-99/100', [0, 99], 'more tail than the file has is the whole file'],
  ['bytes=90-1000', 206, 'bytes 90-99/100', [90, 99], 'past the end is clamped, and said so honestly'],
  ['bytes=100-120', 416, 'bytes */100', null, 'wholly past the end cannot be satisfied'],
  ['bytes=0-9,20-29', 200, null, [0, 99], 'several ranges at once: the whole file instead, which is allowed'],
];

const browser = await chromium.launch();
let failed = 0;
try {
  const page = await (await browser.newContext()).newPage();
  page.setDefaultTimeout(20000);
  await page.goto(base, { waitUntil: 'domcontentloaded' });
  await page.evaluate(async () => {
    await navigator.serviceWorker.register('/sw.js');
    await navigator.serviceWorker.ready;
  });
  await page.reload({ waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(800);

  const results = await page.evaluate(async (ranges) => {
    const bytes = new Uint8Array(100);
    for (let i = 0; i < 100; i += 1) bytes[i] = i;
    const cache = await caches.open('marine-team-downloads-v1');
    await cache.put('/offline-video/range-check.mp4', new Response(bytes, { headers: { 'Content-Type': 'video/mp4' } }));

    const out = [];
    for (const range of ranges) {
      try {
        const r = await fetch('/offline-video/range-check.mp4', { headers: { Range: range } });
        const body = new Uint8Array(await r.arrayBuffer());
        out.push({
          status: r.status,
          contentRange: r.headers.get('content-range'),
          contentLength: r.headers.get('content-length'),
          length: body.length,
          first: body.length ? body[0] : null,
          last: body.length ? body[body.length - 1] : null,
        });
      } catch (error) {
        out.push({ threw: error.message });
      }
    }
    await cache.delete('/offline-video/range-check.mp4');
    return out;
  }, cases.map((c) => c[0]));

  for (const [i, [range, status, contentRange, span, why]] of cases.entries()) {
    const got = results[i];
    const wrong = [];
    if (got.threw) {
      wrong.push(`threw: ${got.threw}`);
    } else {
      if (got.status !== status) wrong.push(`status ${got.status}, wanted ${status}`);
      if (contentRange !== null && got.contentRange !== contentRange) wrong.push(`Content-Range "${got.contentRange}", wanted "${contentRange}"`);
      if (span === null && got.length !== 0) wrong.push(`${got.length} bytes, wanted none`);
      if (span !== null && (got.first !== span[0] || got.last !== span[1])) wrong.push(`bytes ${got.first}..${got.last}, wanted ${span[0]}..${span[1]}`);
      // A length that does not match the body is read as a truncated response.
      if (got.contentLength !== null && Number(got.contentLength) !== got.length) {
        wrong.push(`Content-Length ${got.contentLength} with a ${got.length}-byte body`);
      }
    }
    if (wrong.length) failed += 1;
    console.log(`${wrong.length ? ' FAIL' : '  ok '}  ${range.padEnd(16)} ${why}`);
    for (const line of wrong) console.log(`        ${line}`);
  }
} catch (error) {
  failed += 1;
  console.log(` FAIL  ${error.message.split('\n')[0]}`);
} finally {
  await browser.close();
}
console.log(failed === 0 ? '\nA saved file answers for part of itself correctly.' : `\n${failed} case(s) wrong.`);
process.exit(failed === 0 ? 0 : 1);

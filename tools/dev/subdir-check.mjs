/**
 * A site unzipped into a subdirectory — example.org/church — which shared
 * hosting makes easy to end up with and which this port supports.
 *
 * Everything the site writes has to carry that prefix and everything it reads
 * has to have it taken off, and the two are written far apart: Url on the way
 * out, Request::stripBase on the way in, with the session cookie's own path
 * between them. A missed prefix is not subtle — a dead link, a logo that does
 * not load, a sign-in that loops — but nothing at a domain root ever shows it,
 * and the whole suite runs at a domain root.
 *
 * This reads the pages a member actually meets and refuses any address the
 * site emits that lands outside the install. Run it against a server started
 * with tools/dev/subdir-router.php:
 *
 *   cp -r storage /tmp/sub-storage
 *   # ...and set base_url in /tmp/sub-storage/config.php to
 *   #    http://127.0.0.1:8099/church
 *   MT_STORAGE_DIR=/tmp/sub-storage \
 *     php -S 127.0.0.1:8099 -t public tools/dev/subdir-router.php &
 *   node tools/dev/subdir-check.mjs
 *
 * A database seeded while the site sat at a root holds media addresses
 * written without the prefix — branding, covers, thumbnails are stored whole,
 * as the original stores them. Those are the seed's, not the code's, so
 * --ignore-stored (the default) passes over them.
 */
import { connect } from 'node:net';

const args = new Map();
for (let i = 2; i < process.argv.length; i += 2) {
  args.set(process.argv[i].replace(/^--/, ''), process.argv[i + 1]);
}
const origin = args.get('origin') || 'http://127.0.0.1:8099';
const prefix = args.get('prefix') || '/church';
const email = args.get('email') || 'admin@grace.test';
const password = args.get('password') || 'devpassword1';
const ignoreStored = args.get('ignore-stored') !== 'no';

let failed = 0;
const say = (ok, text) => { if (!ok) failed += 1; console.log(`${ok ? '  ok ' : ' FAIL'}  ${text}`); };

/** Every address the page hands a browser: attributes and a few headers. */
function addressesIn(html) {
  const found = [];
  // Only a real attribute, so data-href — which carries the unprefixed path
  // on purpose, for the shell to match the open section against — is left be.
  const re = /(?:^|[\s"'])(href|src|action|poster|srcset|content)="([^"]*)"/gi;
  let m;
  while ((m = re.exec(html)) !== null) {
    const attr = m[1].toLowerCase();
    for (const raw of attr === 'srcset' ? m[2].split(',') : [m[2]]) {
      const value = raw.trim().split(/\s+/)[0];
      // content= is mostly prose; only the ones that are plainly a path.
      if (attr === 'content' && !value.startsWith('/') && !value.startsWith('http')) continue;
      found.push({ attr, value });
    }
  }
  return found;
}

function outside(value) {
  if (!value.startsWith('/')) return false;       // relative, a fragment, a scheme
  if (value.startsWith('//')) return false;       // another host, judged elsewhere
  return value !== prefix && !value.startsWith(`${prefix}/`);
}

const stored = /^\/media\//;

const jar = new Map();
function remember(response) {
  for (const line of (response.headers.getSetCookie?.() ?? [])) {
    const [pair] = line.split(';');
    const at = pair.indexOf('=');
    jar.set(pair.slice(0, at).trim(), pair.slice(at + 1).trim());
  }
}
const cookieHeader = () => [...jar].map(([k, v]) => `${k}=${v}`).join('; ');

async function get(path, init = {}) {
  const response = await fetch(origin + path, {
    redirect: 'manual',
    ...init,
    headers: { cookie: cookieHeader(), ...(init.headers || {}) },
  });
  remember(response);
  return response;
}

// ---------------------------------------------------------------- signed out

const pages = [
  '/', '/videos', '/series', '/categories', '/speakers', '/search?q=grace',
  '/calendar', '/events', '/prayer', '/groups', '/services', '/forms',
  '/books', '/downloads', '/live', '/auth/login', '/auth/register', '/auth/forgot',
];

async function sweep(what) {
  const bad = [];
  for (const page of pages) {
    const response = await get(prefix + page);
    if (response.status >= 500) { bad.push(`${page} answered ${response.status}`); continue; }
    if (response.status >= 300 && response.status < 400) {
      const to = response.headers.get('location') || '';
      if (outside(to)) bad.push(`${page} redirects to ${to}`);
      continue;
    }
    const html = await response.text();
    for (const { attr, value } of addressesIn(html)) {
      if (!outside(value)) continue;
      if (ignoreStored && stored.test(value)) continue;
      bad.push(`${page} → ${attr}="${value}"`);
    }
  }
  say(bad.length === 0, `${what}: every address the pages emit is inside ${prefix}`);
  for (const line of bad.slice(0, 12)) console.log(`         ${line}`);
  if (bad.length > 12) console.log(`         …and ${bad.length - 12} more`);
}

await sweep('signed out');

// ------------------------------------------------------------------ signing in

const loginPage = await (await get(`${prefix}/auth/login`)).text();
const csrf = /name="_csrf" value="([^"]*)"/.exec(loginPage)?.[1] ?? '';
say(csrf !== '', 'the sign-in form carries a token');
say(
  /<form[^>]*action="\/church\/auth\/login"/.test(loginPage.replace(prefix, '/church')),
  'the sign-in form posts back inside the install',
);

const cookiePath = [];
const posted = await fetch(`${origin}${prefix}/auth/login`, {
  method: 'POST',
  redirect: 'manual',
  headers: {
    'content-type': 'application/x-www-form-urlencoded',
    cookie: cookieHeader(),
    origin,
    referer: `${origin}${prefix}/auth/login`,
  },
  body: new URLSearchParams({ _csrf: csrf, email, password }).toString(),
});
for (const line of (posted.headers.getSetCookie?.() ?? [])) {
  if (line.startsWith('mt_session=') || line.startsWith('__Host-')) cookiePath.push(line);
}
remember(posted);

say(posted.status === 303, `signing in answers 303 (got ${posted.status})`);
say(!outside(posted.headers.get('location') || ''), `and sends the member to ${posted.headers.get('location')}`);
say(
  cookiePath.some((c) => c.includes(`Path=${prefix}/`)),
  `the session cookie is scoped to ${prefix}/`,
);
// The other half of the cookie's name — that __Host- is dropped, because it
// means "path /" and a subdirectory cannot honour it — only shows over HTTPS,
// which a dev server is not. BasePathTest proves that one in process.

pages.push('/profile', '/profile/settings', '/admin', '/admin/videos', '/admin/services', '/television');
await sweep('signed in');

// -------------------------------------------------------- what machines read

for (const [path, pattern, what] of [
  ['/robots.txt', new RegExp(`Sitemap: ${origin}${prefix}/sitemap.xml`), 'robots names the sitemap inside the install'],
  ['/sitemap.xml', new RegExp(`<loc>${origin}${prefix}/`), 'the sitemap lists addresses inside the install'],
  ['/feed.xml', new RegExp(`<link>${origin}${prefix}/`), 'the feed links inside the install'],
  ['/api/manifest', new RegExp(`"start_url":"${prefix}/"`), 'the app manifest starts inside the install'],
  ['/api/manifest', new RegExp(`"src":"${prefix}/icon`), 'and its icons are inside the install'],
]) {
  const body = await (await get(prefix + path)).text();
  say(pattern.test(body), `${what} (${path})`);
}

// A member's device asks for the worker at the prefix and it has to claim the
// prefix as its scope, or nothing it saved is ever served back.
const sw = await get(`${prefix}/sw.js`);
say(sw.status === 200, 'the service worker is served under the prefix');

// ------------------------------------------------- a path that walks out of it

// fetch() resolves ".." before it sends anything, as a browser does, so the
// only way to put an unresolved path on the wire is to write the request out.
function raw(target) {
  return new Promise((resolve, reject) => {
    const { hostname, port } = new URL(origin);
    const socket = connect({ host: hostname, port: Number(port) }, () => {
      socket.write(
        `GET ${target} HTTP/1.1\r\nHost: ${hostname}:${port}\r\n`
        + `Cookie: ${cookieHeader()}\r\nConnection: close\r\n\r\n`,
      );
    });
    let text = '';
    socket.setEncoding('utf8');
    socket.on('data', (chunk) => { text += chunk; });
    socket.on('error', reject);
    socket.on('end', () => resolve(text));
  });
}

for (const walk of [`${prefix}/../storage/config.php`, `${prefix}/../../../etc/passwd`, `${prefix}/./admin`]) {
  const answer = await raw(walk);
  const status = Number(/^HTTP\/1\.\d (\d+)/.exec(answer)?.[1] ?? 0);
  say(
    status === 200 && !answer.includes('<?php') && !answer.includes('app_key') && !answer.includes('root:x:'),
    `${walk} is answered as the site's front door, not as what it names (${status})`,
  );
}

console.log(failed === 0 ? '\nAll good.' : `\n${failed} failed.`);
process.exit(failed === 0 ? 0 : 1);

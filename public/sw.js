// Minimal service worker: enables PWA installability and Web Push
// notifications. Deliberately does NOT cache pages/API responses — this
// site's content is dynamic and often auth-gated, so an aggressive cache
// would risk showing stale or wrong-audience content offline. It only
// caches its own static shell assets, plus the videos, books, service
// orders and calendar a member explicitly saved (see the /offline-video/,
// /offline-book/, /offline-service/ and /offline-calendar/ handlers
// below), which are deliberate, member-initiated copies rather than
// opportunistic caching.
//
// /offline.html is the one exception to "no pages": it's a static,
// unauthenticated, data-free file (no Next.js build output, no server
// round trip) that reads the same localStorage indexes the app writes and
// plays or reads straight out of those caches. Without it, a failed
// navigation — including the installed PWA's own start_url on a cold
// launch — falls through to the browser's native "you're offline"
// interstitial, which has no way to reach anything already on the device.
// See the navigate branch of the fetch handler below.
//
// Port note: the only change from the original is BASE, the path the site is
// installed under ("" at a domain root). Every path below is written relative
// to the site, exactly as before, and prefixed with BASE where it is used, so
// a site at example.org/church/ keeps the same cache keys under /church/.
const BASE = self.location.pathname.replace(/\/sw\.js$/, "");
const CACHE_NAME = "marine-team-shell-v5";
const SHELL_ASSETS = ["/manifest.json", "/icon-192.png", "/icon-512.png", "/offline.html"].map((p) => BASE + p);

// Written by src/lib/offline-downloads.ts and src/lib/offline-books.ts; kept
// out of the activate-time cleanup below so a version bump of the shell never
// wipes someone's downloads or their hymnals.
const DOWNLOAD_CACHE = "marine-team-downloads-v1";
const DOWNLOAD_PATH_PREFIX = BASE + "/offline-video/";
const BOOK_CACHE = "marine-team-books-v1";
const BOOK_PATH_PREFIX = BASE + "/offline-book/";
// A hymn-per-file book is saved as its list of hymns rather than as a file,
// and lives in the same cache under its own path.
const HYMNAL_PATH_PREFIX = BASE + "/offline-hymnal/";
// A service's running order (src/lib/offline-services.ts). Its own cache
// rather than the books' one: a plan is kept for a particular Sunday and
// thrown away after it, and clearing one shouldn't take the other with it.
const SERVICE_CACHE = "marine-team-services-v1";
const SERVICE_PATH_PREFIX = BASE + "/offline-service/";
// The rota calendar (src/lib/offline-calendar.ts). One file rather than one
// per thing saved, and the only one of these that is kept up to date rather
// than saved once: it is a few kilobytes and it syncs incrementally.
const CALENDAR_CACHE = "marine-team-calendar-v1";
const CALENDAR_PATH_PREFIX = BASE + "/offline-calendar/";
// The reader libraries, saved into the book cache alongside the first book
// that needs them so the offline shell has something to draw a page with.
const VIEWER_PATH_PREFIXES = ["/pdfjs/", "/epubjs/"].map((p) => BASE + p);
// The app's own file route. A saved book is the same bytes under a different
// name, which is what lets the in-app reader survive the connection dropping
// while it is open.
const CONTENT_PATH = new RegExp("^" + BASE.replace(/[.*+?^${}()|[\]\\]/g, "\\$&") + "/api/files/([^/]+)/content$");

self.addEventListener("install", (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => cache.addAll(SHELL_ASSETS)).then(() => self.skipWaiting()),
  );
});

self.addEventListener("activate", (event) => {
  event.waitUntil(
    caches
      .keys()
      .then((keys) =>
        Promise.all(
          keys
            .filter(
              (key) =>
                key !== CACHE_NAME &&
                key !== DOWNLOAD_CACHE &&
                key !== BOOK_CACHE &&
                key !== SERVICE_CACHE &&
                key !== CALENDAR_CACHE,
            )
            .map((key) => caches.delete(key)),
        ),
      )
      .then(() => self.clients.claim()),
  );
});

/**
 * Answers from one of the saved-media caches.
 *
 * Range requests get an explicit slice of the cached blob: a media element
 * seeking in a file expects 206 Partial Content, and Cache Storage always
 * replays the whole 200 response, which several browsers refuse to seek in.
 * A PDF viewer asking for ranges is the same story.
 */
async function respondFromCache(cacheName, path, request, fallbackType) {
  const cached = await caches.open(cacheName).then((cache) => cache.match(path));
  if (!cached) return null;

  const range = request.headers.get("range");
  if (!range) return cached;

  const blob = await cached.blob();
  const match = /bytes=(\d*)-(\d*)/.exec(range);
  const start = match && match[1] ? Number(match[1]) : 0;
  const end = match && match[2] ? Number(match[2]) : blob.size - 1;

  return new Response(blob.slice(start, end + 1), {
    status: 206,
    headers: {
      "Content-Type": blob.type || fallbackType,
      "Content-Range": `bytes ${start}-${end}/${blob.size}`,
      "Content-Length": String(end - start + 1),
      "Accept-Ranges": "bytes",
    },
  });
}

self.addEventListener("fetch", (event) => {
  const url = new URL(event.request.url);
  if (url.origin !== self.location.origin) return;

  // Downloaded videos, saved books, a service order and the calendar. These
  // URLs are ours by construction (/offline-video/<id>.mp4,
  // /offline-book/<id>.pdf) and never exist on the server, so this handler is
  // the only thing that can answer them — which is what makes a plain
  // <video src> play, and a saved hymnal open, with no network at all.
  if (
    url.pathname.startsWith(DOWNLOAD_PATH_PREFIX) ||
    url.pathname.startsWith(BOOK_PATH_PREFIX) ||
    url.pathname.startsWith(HYMNAL_PATH_PREFIX) ||
    url.pathname.startsWith(SERVICE_PATH_PREFIX) ||
    url.pathname.startsWith(CALENDAR_PATH_PREFIX)
  ) {
    const isVideo = url.pathname.startsWith(DOWNLOAD_PATH_PREFIX);
    const isService = url.pathname.startsWith(SERVICE_PATH_PREFIX);
    const isCalendar = url.pathname.startsWith(CALENDAR_PATH_PREFIX);
    const isJson = isService || isCalendar || url.pathname.startsWith(HYMNAL_PATH_PREFIX);
    event.respondWith(
      respondFromCache(
        isVideo
          ? DOWNLOAD_CACHE
          : isService
            ? SERVICE_CACHE
            : isCalendar
              ? CALENDAR_CACHE
              : BOOK_CACHE,
        url.pathname,
        event.request,
        isVideo
          ? "video/mp4"
          : isJson
            ? "application/json"
            : url.pathname.endsWith(".epub")
              ? "application/epub+zip"
              : "application/pdf",
      ).then((response) => response || new Response("Not saved on this device", { status: 404 })),
    );
    return;
  }

  // The reader libraries: cache first, because the whole point of having
  // saved them is that they are there when the network isn't.
  if (VIEWER_PATH_PREFIXES.some((prefix) => url.pathname.startsWith(prefix))) {
    event.respondWith(
      caches
        .open(BOOK_CACHE)
        .then((cache) => cache.match(url.pathname))
        .then((cached) => cached || fetch(event.request)),
    );
    return;
  }

  // A book's own bytes, for the reader inside the app. The network is asked
  // first and always wins — this is an access-checked route, and a cached
  // copy must never stand in for a "no" — so this only catches the case where
  // there is no network to answer at all, and only for a book this device was
  // deliberately given.
  const contentMatch = CONTENT_PATH.exec(url.pathname);
  if (contentMatch && event.request.method === "GET") {
    event.respondWith(
      fetch(event.request).catch(async () => {
        // Either extension: the saved copy is named for the reader that
        // opens it, and this handler doesn't know which one this file is.
        for (const format of ["pdf", "epub"]) {
          const cached = await respondFromCache(
            BOOK_CACHE,
            `${BOOK_PATH_PREFIX}${contentMatch[1]}.${format}`,
            event.request,
            format === "epub" ? "application/epub+zip" : "application/pdf",
          );
          if (cached) return cached;
        }
        return Response.error();
      }),
    );
    return;
  }

  // Page loads (typing the URL, a bookmark, reopening the installed PWA)
  // try the network exactly as they would with no service worker at all —
  // this never serves a stale page. Only when the network is actually
  // unreachable does it fall back to the offline shell, so a member who
  // opens the app with no connection lands on what they've saved instead of
  // the OS's own offline error page.
  //
  // The shell is served *at the URL that was asked for* — the address bar
  // still says /categories/hymnals — so it can read its own location and
  // open the hymnals, rather than a generic list, when that is the icon
  // that was tapped.
  if (event.request.mode === "navigate") {
    event.respondWith(fetch(event.request).catch(() => caches.match(BASE + "/offline.html")));
  }
});

self.addEventListener("push", (event) => {
  if (!event.data) return;
  let payload = { title: "Marine Team", body: "" };
  try {
    payload = event.data.json();
  } catch {
    payload.body = event.data.text();
  }
  event.waitUntil(
    self.registration.showNotification(payload.title, {
      body: payload.body,
      icon: BASE + "/icon-192.png",
      badge: BASE + "/icon-192.png",
      data: { url: payload.url || BASE + "/" },
    }),
  );
});

self.addEventListener("notificationclick", (event) => {
  event.notification.close();
  const url = event.notification.data?.url || BASE + "/";
  event.waitUntil(self.clients.openWindow(url));
});

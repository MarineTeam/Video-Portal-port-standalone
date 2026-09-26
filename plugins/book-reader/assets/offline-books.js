// A book kept on this device (lib/offline-books.ts).
//
// The bytes go into Cache Storage under /offline-book/<id>.<ext> on our own
// origin, where the service worker answers for them with no network; the
// index is one localStorage entry the offline shell reads too, and the
// contents list goes beside it so the shell can draw a table of contents
// without a bundle.
//
// The reader library the book needs is saved with it — pdf.js for a PDF,
// epub.js and JSZip for an EPUB — because the offline screen is a static
// page with nothing to render a book with. Only the library the saved book
// actually needs is fetched: a shelf of EPUBs never pulls pdf.js's megabyte.
import MT from '../../../assets/js/mt.js';

export const BOOK_CACHE = 'marine-team-books-v1';
export const INDEX_KEY = 'marine-offline-books';
export const TOC_PREFIX = 'marine-toc-v1:';
export const BOOKS_CHANGED_EVENT = 'marine-offline-books-change';

/** The libraries, at the addresses the offline shell also uses. */
const VIEWERS = {
  pdf: ['/vendor-js/pdfjs/pdf.mjs', '/vendor-js/pdfjs/pdf.worker.mjs'],
  epub: ['/vendor-js/epubjs/jszip.js', '/vendor-js/epubjs/epub.js'],
};

export function bookCacheUrl(id, format) {
  return MT.url(`/offline-book/${encodeURIComponent(id)}.${String(format).toLowerCase() === 'epub' ? 'epub' : 'pdf'}`);
}

export function readIndex() {
  try {
    const items = JSON.parse(localStorage.getItem(INDEX_KEY) || '[]');
    return Array.isArray(items) ? items.filter((item) => item && item.id && item.cacheUrl) : [];
  } catch {
    return [];
  }
}

function writeIndex(items) {
  try {
    localStorage.setItem(INDEX_KEY, JSON.stringify(items));
  } catch { /* storage blocked: the bytes are still cached */ }
  window.dispatchEvent(new Event(BOOKS_CHANGED_EVENT));
}

export async function isSaved(id) {
  const entry = readIndex().find((item) => item.id === id);
  if (!entry || !('caches' in window)) return false;
  try {
    const cache = await caches.open(BOOK_CACHE);
    return (await cache.match(entry.cacheUrl)) !== undefined;
  } catch {
    return false;
  }
}

/** The library this book needs, fetched once and kept with it. */
async function saveViewer(cache, format) {
  for (const path of VIEWERS[String(format).toLowerCase() === 'epub' ? 'epub' : 'pdf']) {
    const url = MT.url(path);
    if (await cache.match(url)) continue;
    const response = await fetch(url);
    if (!response.ok) throw new Error(`Could not save the reader (${response.status}).`);
    await cache.put(url, response);
  }
}

/**
 * Saves a book — its bytes, its contents and the reader it needs.
 *
 * @param {{book: object, onProgress?: (percent: number) => void}} options
 */
export async function saveBook({ book, onProgress }) {
  if (!('caches' in window)) throw new Error('This browser can’t keep a book offline.');
  const format = String(book.format || 'PDF').toLowerCase();
  const cache = await caches.open(BOOK_CACHE);
  const response = await fetch(book.contentUrl, { credentials: 'same-origin' });
  if (!response.ok || !response.body) throw new Error(`The book couldn’t be fetched (${response.status}).`);

  // Read it through, so there is a progress bar rather than a long silence.
  const total = Number(response.headers.get('content-length')) || book.sizeBytes || 0;
  const reader = response.body.getReader();
  const parts = [];
  let read = 0;
  for (;;) {
    const { done, value } = await reader.read();
    if (done) break;
    parts.push(value);
    read += value.length;
    if (total > 0) onProgress?.(Math.min(99, Math.round((read / total) * 100)));
  }
  const bytes = new Blob(parts, { type: format === 'epub' ? 'application/epub+zip' : 'application/pdf' });
  const url = bookCacheUrl(book.id, format);
  await cache.put(url, new Response(bytes, { headers: { 'Content-Type': bytes.type, 'Content-Length': String(bytes.size) } }));
  await saveViewer(cache, format);

  // The contents, tagged with the size — a list read from a different
  // version of the file describes a different book.
  try {
    localStorage.setItem(TOC_PREFIX + book.id, JSON.stringify({
      tag: String(book.sizeBytes ?? 0),
      entries: (book.contents || []).map((entry) => ({
        label: entry.title,
        page: entry.page,
        printedPage: entry.printedPage,
        depth: entry.depth,
      })),
    }));
  } catch { /* the book still opens, at its first page */ }

  writeIndex([
    ...readIndex().filter((item) => item.id !== book.id),
    {
      id: book.id,
      kind: 'file',
      title: book.title,
      format: format === 'epub' ? 'epub' : 'pdf',
      cacheUrl: url,
      sizeBytes: book.sizeBytes ?? null,
      savedAt: new Date().toISOString(),
    },
  ]);
  onProgress?.(100);
  return url;
}

/**
 * A hymn-per-file series, which has no document to save: its hymns and their
 * words, as JSON, under the same index with a kind of its own.
 */
export async function saveHymnal(seriesId) {
  if (!('caches' in window)) throw new Error('This browser can’t keep a hymnal offline.');
  const payload = await MT.api(`/api/offline/hymnal/${encodeURIComponent(seriesId)}`);
  const url = MT.url(`/offline-hymnal/${encodeURIComponent(seriesId)}.json`);
  const cache = await caches.open(BOOK_CACHE);
  await cache.put(url, new Response(JSON.stringify(payload), { headers: { 'Content-Type': 'application/json' } }));
  writeIndex([
    ...readIndex().filter((item) => item.id !== seriesId),
    {
      id: seriesId,
      kind: 'hymnal',
      title: payload.title,
      cacheUrl: url,
      fingerprint: payload.fingerprint,
      hymns: payload.hymns.length,
      savedAt: new Date().toISOString(),
    },
  ]);
  return payload;
}

/**
 * Whether a saved hymnal is still the hymnal the server would send.
 *
 * One request that carries no words, so a device can ask on opening without
 * re-fetching the book.
 */
export async function hymnalIsCurrent(seriesId) {
  const entry = readIndex().find((item) => item.id === seriesId && item.kind === 'hymnal');
  if (!entry) return false;
  try {
    const probe = await MT.api(`/api/offline/hymnal/${encodeURIComponent(seriesId)}?probe=1`);
    return probe.fingerprint === entry.fingerprint;
  } catch {
    // No connection is not a reason to say a saved book is stale.
    return true;
  }
}

export async function forgetBook(id) {
  const entry = readIndex().find((item) => item.id === id);
  if (entry && 'caches' in window) {
    try {
      const cache = await caches.open(BOOK_CACHE);
      await cache.delete(entry.cacheUrl);
    } catch { /* already gone */ }
  }
  try {
    localStorage.removeItem(TOC_PREFIX + id);
  } catch { /* nothing to remove */ }
  writeIndex(readIndex().filter((item) => item.id !== id));
}

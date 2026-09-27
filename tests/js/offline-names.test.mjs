// The names a member's own device already holds (Appendix H), and the one
// agreement between three files that nothing else checks: the service worker
// serves the reader libraries only from the prefixes it lists, so if those
// stop matching where the saver puts them and the shell asks for them, a
// saved book opens to a blank page — on the one occasion it was saved for.
import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const root = new URL('../../', import.meta.url).pathname;
const read = (p) => readFileSync(root + p, 'utf8');

const sw = read('public/sw.js');
const shell = read('public/offline.html');
const savers = {
  'plugins/book-reader/assets/offline-books.js': read('plugins/book-reader/assets/offline-books.js'),
  'public/assets/js/downloads.js': read('public/assets/js/downloads.js'),
  'public/assets/js/offline-calendar.js': read('public/assets/js/offline-calendar.js'),
  'plugins/service-plans/assets/offline-services.js': read('plugins/service-plans/assets/offline-services.js'),
};

/** The array literal assigned to a const in a file. */
function constArray(source, name) {
  const m = source.match(new RegExp(`const ${name} = \\[([^\\]]*)\\]`));
  assert.ok(m, `${name} is not declared as an array literal`);
  return [...m[1].matchAll(/["']([^"']+)["']/g)].map((x) => x[1]);
}

describe('the service worker and the pages that fill its caches', () => {
  test('serves the reader libraries from the prefixes they are actually saved under', () => {
    const prefixes = constArray(sw, 'VIEWER_PATH_PREFIXES');
    const saved = [...read('plugins/book-reader/assets/offline-books.js').matchAll(/'(\/[\w./-]+\/(?:pdf|epub|jszip)[\w.-]*)'/g)].map((m) => m[1]);

    assert.ok(saved.length >= 4, 'the saver names the libraries it caches');
    for (const path of saved) {
      assert.ok(
        prefixes.some((prefix) => path.startsWith(prefix)),
        `${path} is saved into the book cache and no VIEWER_PATH_PREFIXES entry (${prefixes.join(', ')}) covers it`,
      );
    }
  });

  test('the offline shell asks for the libraries at those same paths', () => {
    const prefixes = constArray(sw, 'VIEWER_PATH_PREFIXES');
    for (const name of ['PDFJS_URL', 'PDFJS_WORKER_URL', 'JSZIP_URL', 'EPUBJS_URL']) {
      const m = shell.match(new RegExp(`const ${name} = BASE \\+ "([^"]+)"`));
      assert.ok(m, `offline.html declares ${name}`);
      assert.ok(
        prefixes.some((prefix) => m[1].startsWith(prefix)),
        `offline.html loads ${name} from ${m[1]}, which no VIEWER_PATH_PREFIXES entry covers`,
      );
    }
  });

  test('every cache the service worker keeps is one something writes to', () => {
    const kept = ['CACHE_NAME', 'DOWNLOAD_CACHE', 'BOOK_CACHE', 'SERVICE_CACHE', 'CALENDAR_CACHE']
      .map((name) => sw.match(new RegExp(`const ${name} = "([^"]+)"`))[1]);
    const written = Object.values(savers).join('') + sw;
    for (const cache of kept) {
      assert.ok(written.includes(cache), `nothing opens ${cache}, so the service worker keeps a cache nobody fills`);
    }
  });
});

describe('Appendix H — the names already on members’ devices', () => {
  // Value, and the file that must carry it by literal because it has no
  // bundle to import a constant from.
  const literals = [
    ['marine-device-settings', 'public/offline.html'],
    ['marine-downloads-index', 'public/offline.html'],
    ['marine-nav-tabs', 'public/offline.html'],
    ['marine-offline-books', 'public/offline.html'],
    ['marine-offline-calendar', 'public/offline.html'],
    ['marine-offline-services', 'public/offline.html'],
    ['marine-team-books-v1', 'public/offline.html'],
    ['marine-team-books-v1', 'public/sw.js'],
    ['marine-team-calendar-v1', 'public/offline.html'],
    ['marine-team-calendar-v1', 'public/sw.js'],
    ['marine-team-downloads-v1', 'public/offline.html'],
    ['marine-team-downloads-v1', 'public/sw.js'],
    ['marine-team-services-v1', 'public/offline.html'],
    ['marine-team-services-v1', 'public/sw.js'],
    ['marine-team-shell-v5', 'public/sw.js'],
  ];

  for (const [value, file] of literals) {
    test(`${file} still says ${value}`, () => {
      assert.ok(read(file).includes(value), `${file} no longer carries ${value}; a device holding it would be orphaned`);
    });
  }

  test('the path prefixes a saved thing lives under are unchanged', () => {
    for (const [name, value] of [
      ['DOWNLOAD_PATH_PREFIX', '/offline-video/'],
      ['BOOK_PATH_PREFIX', '/offline-book/'],
      ['HYMNAL_PATH_PREFIX', '/offline-hymnal/'],
      ['SERVICE_PATH_PREFIX', '/offline-service/'],
      ['CALENDAR_PATH_PREFIX', '/offline-calendar/'],
    ]) {
      assert.match(sw, new RegExp(`const ${name} = BASE \\+ "${value.replace(/\//g, '\\/')}"`), `${name} is no longer ${value}`);
    }
  });
});

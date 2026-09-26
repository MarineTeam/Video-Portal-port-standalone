// The EPUB half of the reader (src/components/reader-epub).
//
// epub.js renders into an iframe it owns, which is the fact that shapes
// everything awkward here: the selection inside that frame is not readable
// from this page, and key presses land in it rather than on the document. So
// marking an EPUB saves a bookmark at the current position rather than
// pretending to capture text it cannot see, and the arrow keys are
// registered inside the frame where the reading actually happens.

let loading = null;

/** epub.js is a UMD build that expects JSZip as a global. */
export function loadEpubJs(base) {
  if (loading) return loading;
  loading = new Promise((resolve, reject) => {
    const add = (src) => new Promise((ok, fail) => {
      const tag = document.createElement('script');
      tag.src = src;
      tag.onload = ok;
      tag.onerror = () => fail(new Error(`Could not load ${src}`));
      document.head.appendChild(tag);
    });
    (window.JSZip ? Promise.resolve() : add(`${base}/epubjs/jszip.js`))
      .then(() => (window.ePub ? Promise.resolve() : add(`${base}/epubjs/epub.js`)))
      .then(() => (window.ePub ? resolve(window.ePub) : reject(new Error('epub.js did not load'))))
      .catch(reject);
  });
  return loading;
}

/**
 * Opens an EPUB and returns the reader handle.
 *
 * `location` is a CFI. It is opaque above this file, which is why reading
 * positions are stored as strings rather than as page numbers.
 */
export async function openEpub({ base, url, container, onLocation, onKey }) {
  const ePub = await loadEpubJs(base);
  // openAs, because epub.js otherwise guesses the format from the URL's
  // extension and the content route has none.
  const book = ePub(url, { openAs: 'epub', requestCredentials: true });
  container.textContent = '';
  const rendition = book.renderTo(container, { width: '100%', height: '100%', flow: 'scrolled-doc', allowScriptedContent: false });
  await book.ready;
  await rendition.display();
  try {
    await book.locations.generate(1600);
  } catch {
    // A book too large to locate still reads; only the percentage suffers.
  }

  let here = rendition.currentLocation();
  const report = () => {
    here = rendition.currentLocation();
    const cfi = here?.start?.cfi;
    if (!cfi) return;
    const percent = book.locations.length() > 0 ? Math.round(book.locations.percentageFromCfi(cfi) * 100) : 0;
    onLocation?.({ location: cfi, page: null, pages: null, percent, chapter: chapterName() });
  };
  rendition.on('relocated', report);
  // Inside the frame epub.js owns, because that is where the keys land.
  if (onKey) rendition.on('keyup', onKey);
  report();

  function chapterName() {
    const cfi = rendition.currentLocation()?.start?.href;
    const found = book.navigation?.toc?.find?.((entry) => cfi && entry.href && entry.href.split('#')[0] === cfi.split('#')[0]);
    return found ? found.label.trim() : null;
  }

  return {
    kind: 'EPUB',
    pages: () => null,
    page: () => null,
    async goTo(location) {
      if (location) await rendition.display(location);
    },
    next: () => rendition.next(),
    previous: () => rendition.prev(),
    async zoom(by) {
      const was = Number.parseInt(String(rendition.themes?._current?.fontSize || '100'), 10) || 100;
      rendition.themes.fontSize(`${Math.min(240, Math.max(60, was + by * 20))}%`);
    },
    /** What is on screen, for read-aloud. */
    text() {
      const frame = container.querySelector('iframe');
      try {
        return frame?.contentDocument?.body?.innerText || '';
      } catch {
        // A same-origin frame is readable; anything else is not, and
        // read-aloud says so rather than reading silence.
        return '';
      }
    },
    /**
     * A bookmark at the current position. Not a highlight: the selection
     * inside the frame is not readable from here, and saving an empty
     * excerpt would be pretending otherwise.
     */
    selection() {
      const cfi = rendition.currentLocation()?.start?.cfi;
      return cfi ? { location: cfi, excerpt: null } : null;
    },
    /** In-book search, which epub.js does a section at a time. */
    async search(query) {
      const hits = [];
      for (const item of book.spine.spineItems) {
        const section = await item.load(book.load.bind(book));
        // Its own typings say find() returns elements; it returns
        // {cfi, excerpt}, which is what this actually reads.
        const found = item.find(query) || [];
        for (const one of found) hits.push({ location: one.cfi, excerpt: one.excerpt });
        item.unload();
        if (section && hits.length > 100) break;
      }
      return hits;
    },
    destroy() {
      rendition.destroy();
      book.destroy();
    },
  };
}

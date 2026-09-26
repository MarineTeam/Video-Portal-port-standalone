// The reader: contents, search, marks, read-aloud, and keeping a book on
// the device.
//
// Both engines sit behind one handle, so nothing here knows a PDF page
// number from an EPUB CFI — which is also why a reading position is an
// opaque string: only the engine that wrote one can read it.
import MT from '../../../assets/js/mt.js';
import { openPdf } from './reader-pdf.js';
import { openEpub } from './reader-epub.js';
import { saveBook, forgetBook, isSaved, bookCacheUrl } from './offline-books.js';

const VENDOR = MT.url('/vendor-js');

function ready(fn) {
  if (document.readyState !== 'loading') fn();
  else document.addEventListener('DOMContentLoaded', fn);
}

function fill(template, values) {
  return String(template ?? '').replace(/\{(\w+)\}/g, (_, key) => String(values[key] ?? ''));
}

ready(async () => {
  const root = document.querySelector('[data-reader]');
  if (!root) return;
  const labels = JSON.parse(root.dataset.labels || '{}');
  const fileId = root.dataset.fileId;
  const page = root.querySelector('[data-reader-page]');
  const say = (message) => {
    const box = root.querySelector('[data-reader-status]');
    if (box) { box.textContent = message; box.hidden = message === ''; }
  };

  let book;
  try {
    book = await MT.api(`/api/books/${fileId}`);
  } catch (e) {
    say(e.message);
    return;
  }

  // A book saved on this device is read from the device, connection or not.
  const savedUrl = (await isSaved(fileId)) ? bookCacheUrl(fileId, book.format) : null;
  const url = savedUrl || book.contentUrl;

  // -- Where we are ------------------------------------------------------
  let at = { location: book.progress?.location || '', percent: book.progress?.percent || 0, page: null };
  let saving;
  function moved(where) {
    at = where;
    const label = root.querySelector('[data-reader-where]');
    if (label) {
      label.textContent = where.page
        ? `${where.page} ${fill(labels.ofPages, { count: where.pages })}`
        : where.chapter || '';
    }
    const inside = root.querySelector('[data-reader-inside]');
    if (inside) {
      const entry = entryFor(where.page);
      inside.textContent = entry ? entry.title : '';
    }
    root.querySelectorAll('[data-toc-entry]').forEach((element) => {
      element.classList.toggle('on', where.page !== null && Number(element.dataset.tocPage) === entryFor(where.page)?.page);
    });
    // Keeping a place is worth one write a few seconds after somebody stops
    // turning pages, not one per page.
    window.clearTimeout(saving);
    saving = window.setTimeout(keepPlace, 2000);
  }

  function entryFor(pageNumber) {
    if (pageNumber === null || pageNumber === undefined) return null;
    let found = null;
    for (const entry of book.contents || []) {
      if (entry.page <= pageNumber && (found === null || entry.page >= found.page)) found = entry;
    }
    return found;
  }

  async function keepPlace() {
    if (!root.dataset.signedIn || !at.location) return;
    try {
      await MT.api(`/api/books/${fileId}/progress`, { method: 'POST', body: { location: at.location, percent: at.percent } });
    } catch { /* a lost position is not worth an error on screen */ }
  }
  window.addEventListener('pagehide', keepPlace);

  let handle = null;
  try {
    handle = book.format === 'EPUB'
      ? await openEpub({ base: VENDOR, url, container: page, onLocation: moved, onKey: (event) => onKey(event) })
      : await openPdf({ base: VENDOR, url, container: page, onLocation: moved });
  } catch (e) {
    // pdf.js needs a fairly current engine, and an older phone can open a
    // PDF perfectly well without being able to run the library that draws
    // one. Say so, and hand the book to the browser's own viewer at the page
    // they were on, rather than showing a blank sheet.
    root.querySelector('[data-reader-fallback]')?.removeAttribute('hidden');
    const wasOn = Number.parseInt(String(book.progress?.location || '1'), 10);
    const link = root.querySelector('[data-reader-fallback] a');
    if (link) link.href = `${book.contentUrl}${Number.isFinite(wasOn) && wasOn > 1 ? `#page=${wasOn}` : ''}`;
    page.hidden = true;
    return;
  }

  // A link to a page or a hymn wins over where this member had got to:
  // somebody following "hymn 119" means to land on 119.
  const asked = root.dataset.startPage;
  if (asked) await handle.goTo(asked);
  else if (book.progress?.location) await handle.goTo(book.progress.location);

  // -- Moving about -------------------------------------------------------
  const go = {
    next: () => handle.next(),
    previous: () => handle.previous(),
    in: () => handle.zoom(0.2),
    out: () => handle.zoom(-0.2),
  };
  root.querySelector('[data-reader-next]')?.addEventListener('click', go.next);
  root.querySelector('[data-reader-previous]')?.addEventListener('click', go.previous);
  root.querySelector('[data-reader-zoom-in]')?.addEventListener('click', go.in);
  root.querySelector('[data-reader-zoom-out]')?.addEventListener('click', go.out);

  function onKey(event) {
    if (event.target?.matches?.('input, textarea, select')) return;
    if (event.key === 'ArrowRight' || event.key === 'PageDown') go.next();
    else if (event.key === 'ArrowLeft' || event.key === 'PageUp') go.previous();
    else if (event.key === '+' || event.key === '=') go.in();
    else if (event.key === '-') go.out();
    else return;
    event.preventDefault?.();
  }
  document.addEventListener('keydown', onKey);

  // A swipe, which is how a phone turns a page.
  let from = null;
  page.addEventListener('touchstart', (event) => { from = event.touches[0]?.clientX ?? null; }, { passive: true });
  page.addEventListener('touchend', (event) => {
    const to = event.changedTouches[0]?.clientX ?? null;
    if (from === null || to === null || Math.abs(to - from) < 60) return;
    (to < from ? go.next : go.previous)();
    from = null;
  }, { passive: true });

  root.querySelectorAll('[data-toc-entry]').forEach((element) => {
    element.addEventListener('click', (event) => {
      event.preventDefault();
      handle.goTo(element.dataset.tocLocation || element.dataset.tocPage);
    });
  });

  root.querySelector('[data-reader-goto]')?.addEventListener('submit', (event) => {
    event.preventDefault();
    const typed = Number.parseInt(event.target.elements.page.value, 10);
    if (Number.isFinite(typed)) handle.goTo(String(typed + (book.pageOffset || 0)));
  });

  // -- Searching in the book ------------------------------------------------
  const searchForm = root.querySelector('[data-reader-search]');
  searchForm?.addEventListener('submit', async (event) => {
    event.preventDefault();
    const query = event.target.elements.q.value.trim();
    const results = root.querySelector('[data-reader-hits]');
    if (!results || query === '') return;
    results.textContent = '';
    let hits = [];
    if (book.searchable) {
      hits = (await MT.api(`/api/books/${fileId}/search?q=${encodeURIComponent(query)}`)).hits;
    } else if (handle.search) {
      hits = (await handle.search(query)).map((hit) => ({ ...hit, page: null }));
    } else {
      // Nobody has read this book's text, so only what is open can be
      // searched — which is a different answer from "nothing found".
      say(labels.searchHint || '');
      const text = handle.text();
      hits = text.toLowerCase().includes(query.toLowerCase()) ? [{ page: at.page, excerpt: text.slice(0, 200) }] : [];
    }
    if (hits.length === 0) {
      results.appendChild(Object.assign(document.createElement('p'), { className: 'muted small', textContent: labels.noHits }));
      return;
    }
    for (const hit of hits.slice(0, 100)) {
      const item = document.createElement('li');
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'linkish';
      button.textContent = `${hit.printedPage ?? hit.page ?? ''} ${hit.inside ? `· ${hit.inside}` : ''} ${hit.excerpt || ''}`.trim();
      button.addEventListener('click', () => handle.goTo(hit.location || String(hit.page)));
      item.appendChild(button);
      results.appendChild(item);
    }
  });

  // -- Read aloud ------------------------------------------------------------
  const speaker = window.speechSynthesis;
  const readButton = root.querySelector('[data-reader-aloud]');
  if (readButton && !speaker) readButton.disabled = true;
  readButton?.addEventListener('click', () => {
    if (!speaker) return;
    if (speaker.speaking) {
      speaker.cancel();
      readButton.textContent = labels.readAloud;
      return;
    }
    const text = handle.text();
    if (!text.trim()) { say(labels.noHits); return; }
    for (const chunk of chunks(text)) speaker.speak(new SpeechSynthesisUtterance(chunk));
    readButton.textContent = labels.stopReading;
  });
  window.addEventListener('pagehide', () => speaker?.cancel());

  // -- Marks -----------------------------------------------------------------
  const markList = root.querySelector('[data-reader-marks]');
  async function drawMarks(marks) {
    if (!markList) return;
    markList.textContent = '';
    if (marks.length === 0) {
      markList.appendChild(Object.assign(document.createElement('p'), { className: 'muted small', textContent: labels.noMarks }));
      return;
    }
    for (const mark of marks) {
      const item = document.createElement('li');
      const open = document.createElement('button');
      open.type = 'button';
      open.className = 'linkish';
      open.textContent = mark.excerpt || mark.note || mark.location;
      open.addEventListener('click', () => handle.goTo(mark.location));
      const remove = document.createElement('button');
      remove.type = 'button';
      remove.className = 'button small danger';
      remove.textContent = '×';
      remove.addEventListener('click', async () => {
        if (!window.confirm(labels.deleteMark)) return;
        drawMarks((await MT.api(`/api/books/${fileId}/marks/${mark.id}`, { method: 'DELETE' })).marks);
      });
      item.append(open, remove);
      markList.appendChild(item);
    }
  }
  if (root.dataset.signedIn && markList) {
    MT.api(`/api/books/${fileId}/marks`).then((answer) => drawMarks(answer.marks)).catch(() => {});
  }
  root.querySelector('[data-reader-mark]')?.addEventListener('click', async () => {
    const chosen = handle.selection();
    if (!chosen) return;
    const answer = await MT.api(`/api/books/${fileId}/marks`, {
      method: 'POST',
      body: { kind: chosen.excerpt ? 'HIGHLIGHT' : 'BOOKMARK', location: chosen.location, excerpt: chosen.excerpt },
    });
    drawMarks(answer.marks);
    say(labels.markSaved);
  });

  // -- Keeping it on the device ------------------------------------------------
  const keep = root.querySelector('[data-reader-keep]');
  if (keep && 'caches' in window) {
    keep.hidden = false;
    const show = async () => { keep.textContent = (await isSaved(fileId)) ? labels.removeOffline : labels.keepOffline; };
    await show();
    keep.addEventListener('click', async () => {
      keep.disabled = true;
      try {
        if (await isSaved(fileId)) await forgetBook(fileId);
        else await saveBook({ book, onProgress: (percent) => say(fill(labels.savingOffline, { percent })) });
        say('');
      } catch (e) {
        say(e.message);
      }
      keep.disabled = false;
      await show();
    });
  }

  say('');
});

/** The same rule the server counts with, so a chunk can be interrupted. */
export function chunks(text, max = 240) {
  const clean = String(text ?? '').replace(/\s+/g, ' ').trim();
  if (clean === '') return [];
  const out = [];
  for (const sentence of clean.split(/(?<=[.!?;:…])\s+/)) {
    let rest = sentence.trim();
    while (rest.length > max) {
      const cut = rest.lastIndexOf(' ', max);
      const at = cut < max / 2 ? max : cut;
      out.push(rest.slice(0, at).trim());
      rest = rest.slice(at).trim();
    }
    if (rest !== '') out.push(rest);
  }
  return out;
}

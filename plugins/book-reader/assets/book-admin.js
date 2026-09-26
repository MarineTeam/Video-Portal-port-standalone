// Indexing a book, from the browser where pdf.js runs.
//
// Three passes, all of them here because the server has no PDF library and
// shared hosting has no place to run one:
//
//   the outline  — a hymnal's own bookmarks, read once and PUT as contents;
//   the contents — the same rows typed by hand, for a scan with no bookmarks;
//   the text     — every page's words, from the text layer where there is
//                  one and by OCR off the image where there is not.
import MT from '../../../assets/js/mt.js';
import { loadPdfJs } from './reader-pdf.js';

const VENDOR = MT.url('/vendor-js');

function ready(fn) {
  if (document.readyState !== 'loading') fn();
  else document.addEventListener('DOMContentLoaded', fn);
}

function fill(template, values) {
  return String(template ?? '').replace(/\{(\w+)\}/g, (_, key) => String(values[key] ?? ''));
}

/** A page rendered to a canvas, for OCR to read. */
async function pageImage(pdf, at, scale = 2) {
  const page = await pdf.getPage(at);
  const viewport = page.getViewport({ scale });
  const canvas = document.createElement('canvas');
  canvas.width = Math.floor(viewport.width);
  canvas.height = Math.floor(viewport.height);
  await page.render({ canvasContext: canvas.getContext('2d'), viewport }).promise;
  return canvas;
}

/**
 * The OCR worker, pointed at this app's own copy.
 *
 * Loaded only when a page turns out to have no text layer — the engine and
 * its training data are ten megabytes, and a book with a text layer needs
 * neither.
 */
let ocr = null;
async function reader() {
  if (ocr) return ocr;
  const { createWorker } = await import(`${VENDOR}/tesseract/tesseract.mjs`);
  ocr = await createWorker('eng', 1, {
    workerPath: `${VENDOR}/tesseract/worker.js`,
    corePath: `${VENDOR}/tesseract/`,
    langPath: `${VENDOR}/tesseract/lang`,
    gzip: true,
  });
  return ocr;
}

ready(() => {
  const box = document.querySelector('[data-book-admin]');
  if (!box || !window.MT) return;
  const fileId = box.dataset.fileId;
  const labels = JSON.parse(box.dataset.labels || '{}');
  const say = (message) => {
    const status = box.querySelector('[data-book-status]');
    if (status) { status.textContent = message; status.hidden = message === ''; }
  };
  const problems = box.querySelector('[data-book-problems]');

  const openPdf = async () => {
    const pdfjs = await loadPdfJs(VENDOR);
    return pdfjs.getDocument({ url: MT.url(`/api/files/${fileId}/content`), withCredentials: true, isEvalSupported: false }).promise;
  };

  // -- The book's own bookmarks --------------------------------------------
  box.querySelector('[data-book-outline]')?.addEventListener('click', async (event) => {
    event.target.disabled = true;
    say(labels.reading || '');
    try {
      const pdf = await openPdf();
      const outline = await pdf.getOutline();
      const entries = [];
      const walk = async (items, depth) => {
        for (const item of items || []) {
          const page = await pageNumberOf(pdf, item.dest);
          if (page !== null) entries.push({ title: String(item.title || '').trim(), page, depth });
          if (item.items?.length) await walk(item.items, depth + 1);
        }
      };
      await walk(outline, 0);
      if (entries.length === 0) {
        // Declining to send an empty list: this pass runs on every cover
        // generation, and a PDF with no bookmarks would otherwise wipe a
        // contents list somebody typed by hand.
        say(labels.noOutline || '');
        event.target.disabled = false;
        return;
      }
      const saved = await MT.api(`/api/admin/files/${fileId}/contents`, { method: 'PUT', body: { entries } });
      say(fill(labels.contentsSaved, { count: saved.saved }));
      window.location.reload();
    } catch (e) {
      say(e.message);
      event.target.disabled = false;
    }
  });

  async function pageNumberOf(pdf, dest) {
    try {
      const resolved = typeof dest === 'string' ? await pdf.getDestination(dest) : dest;
      const index = await pdf.getPageIndex(resolved[0]);
      return index + 1;
    } catch {
      return null;
    }
  }

  // -- The contents typed by hand --------------------------------------------
  box.querySelector('[data-book-contents]')?.addEventListener('submit', async (event) => {
    event.preventDefault();
    say('');
    if (problems) problems.textContent = '';
    try {
      const answer = await MT.api(`/api/admin/files/${fileId}/contents`, {
        method: 'PUT',
        body: {
          text: event.target.elements.text.value,
          pageOffset: Number.parseInt(event.target.elements.pageOffset.value || '0', 10),
        },
      });
      say(fill(labels.contentsSaved, { count: answer.saved }));
      for (const problem of answer.problems || []) {
        const line = document.createElement('li');
        line.textContent = `${problem.line}: ${problem.reason}`;
        problems?.appendChild(line);
      }
    } catch (e) {
      say(e.message);
    }
  });

  // -- Reading the book's text -------------------------------------------------
  let stop = false;
  box.querySelector('[data-book-stop]')?.addEventListener('click', () => { stop = true; });
  box.querySelector('[data-book-text]')?.addEventListener('click', async (event) => {
    event.target.disabled = true;
    stop = false;
    box.querySelector('[data-book-stop]')?.removeAttribute('hidden');
    try {
      const pdf = await openPdf();
      const total = pdf.numPages;
      const from = Number.parseInt(box.dataset.readFrom || '1', 10) || 1;
      let batch = [];
      for (let at = from; at <= total; at += 1) {
        if (stop) break;
        say(fill(labels.readingText, { page: at, count: total }));
        const page = await pdf.getPage(at);
        const content = await page.getTextContent().catch(() => null);
        let text = content ? content.items.map((item) => item.str).join(' ').trim() : '';
        let source = 'TEXT';
        if (text.length < 20) {
          // A scan: no text layer worth the name, so read the image.
          say(labels.ocrNeeded || '');
          const worker = await reader();
          const canvas = await pageImage(pdf, at);
          const result = await worker.recognize(canvas);
          text = String(result?.data?.text || '').trim();
          source = 'OCR';
        }
        if (text !== '') batch.push({ page: at, text, source });
        // Written a few pages at a time, so an hour-long run over a scanned
        // hymnal is resumable: what is saved stays saved.
        if (batch.length >= 5 || at === total) {
          await MT.api(`/api/admin/files/${fileId}/pages`, { method: 'PUT', body: { pages: batch, finished: at === total && !stop } });
          batch = [];
          box.dataset.readFrom = String(at + 1);
        }
      }
      say(stop ? fill(labels.textRead, { count: (Number(box.dataset.readFrom) || 1) - 1 }) : fill(labels.textRead, { count: total }));
    } catch (e) {
      say(e.message);
    }
    box.querySelector('[data-book-stop]')?.setAttribute('hidden', '');
    event.target.disabled = false;
  });
});

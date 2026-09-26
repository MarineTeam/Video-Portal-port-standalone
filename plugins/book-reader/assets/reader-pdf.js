// The PDF half of the reader (src/components/reader-pdf).
//
// pdf.js is loaded here and nowhere else: it is 350KB plus a worker, and a
// church whose library is all EPUBs should never fetch it. Everything above
// this file talks to the handle it returns, so nothing else has to know a
// page number from a CFI.

/** @returns {Promise<object>} the pdf.js module, loaded once */
let loading = null;
export function loadPdfJs(base) {
  if (loading) return loading;
  loading = import(`${base}/pdfjs/pdf.mjs`).then((pdfjs) => {
    // The worker must come from the same release as the library; a mismatch
    // fails at the first page rather than at load.
    pdfjs.GlobalWorkerOptions.workerSrc = `${base}/pdfjs/pdf.worker.mjs`;
    return pdfjs;
  });
  return loading;
}

/**
 * Opens a PDF and returns the reader handle.
 *
 * `location` is the page number as a string. It is opaque to everything
 * above: only this file knows what is in it.
 */
export async function openPdf({ base, url, container, onLocation }) {
  const pdfjs = await loadPdfJs(base);
  const document_ = await pdfjs.getDocument({ url, withCredentials: true, isEvalSupported: false }).promise;
  const canvas = window.document.createElement('canvas');
  canvas.className = 'reader-page';
  container.textContent = '';
  container.appendChild(canvas);

  let page = 1;
  let scale = 1;
  let drawing = null;
  let lastText = '';

  async function draw() {
    const at = Math.min(Math.max(1, page), document_.numPages);
    const rendered = await document_.getPage(at);
    // Fit the width available, then apply the reader's own zoom on top.
    const unscaled = rendered.getViewport({ scale: 1 });
    const fit = Math.max(0.2, (container.clientWidth || 800) / unscaled.width);
    const viewport = rendered.getViewport({ scale: fit * scale * (window.devicePixelRatio || 1) });
    canvas.width = Math.floor(viewport.width);
    canvas.height = Math.floor(viewport.height);
    canvas.style.width = `${Math.floor(viewport.width / (window.devicePixelRatio || 1))}px`;
    canvas.style.height = 'auto';
    if (drawing) drawing.cancel();
    drawing = rendered.render({ canvasContext: canvas.getContext('2d'), viewport });
    await drawing.promise.catch(() => {});
    drawing = null;
    // The page's own text, for read-aloud and for searching a book nobody
    // has read into the database yet.
    const text = await rendered.getTextContent().catch(() => null);
    lastText = text ? text.items.map((item) => item.str).join(' ') : '';
    onLocation?.({ location: String(at), page: at, pages: document_.numPages, percent: Math.round((at / document_.numPages) * 100) });
  }

  await draw();

  return {
    kind: 'PDF',
    pages: () => document_.numPages,
    page: () => page,
    async goTo(location) {
      const at = Number.parseInt(String(location), 10);
      if (!Number.isFinite(at)) return;
      page = Math.min(Math.max(1, at), document_.numPages);
      await draw();
    },
    async next() {
      if (page >= document_.numPages) return;
      page += 1;
      await draw();
    },
    async previous() {
      if (page <= 1) return;
      page -= 1;
      await draw();
    },
    async zoom(by) {
      scale = Math.min(4, Math.max(0.5, scale + by));
      await draw();
    },
    /** The text of the page on screen — what read-aloud speaks. */
    text: () => lastText,
    /** What a highlight records: the selection, if the browser has one. */
    selection() {
      const chosen = window.getSelection?.();
      const text = chosen ? String(chosen).trim() : '';
      return text === '' ? null : { location: String(page), excerpt: text };
    },
    /** Every page's text, for searching a book nobody has indexed. */
    async eachPage(visit) {
      for (let at = 1; at <= document_.numPages; at += 1) {
        const one = await document_.getPage(at);
        const content = await one.getTextContent().catch(() => null);
        const stop = visit(at, content ? content.items.map((item) => item.str).join(' ') : '', document_.numPages);
        if (stop === false) return;
      }
    },
    destroy() {
      drawing?.cancel();
      document_.destroy?.();
    },
  };
}

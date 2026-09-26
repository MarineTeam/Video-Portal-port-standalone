# tesseract.js

The OCR engine, served from this app rather than a public CDN: a church
office behind a filter can still read a scanned hymnal, and nothing about
what is being scanned leaves the building.

  - `tesseract.mjs` — the library (ES module build).
  - `worker.js` — its worker, which it fetches by URL rather than importing.
  - `tesseract-core-simd-lstm.wasm.js` — the engine, where the browser has
    SIMD; `tesseract-core-lstm.wasm.js` is the fallback where it does not.
    Only the LSTM cores are here: they are the ones tesseract 4 and 5 use,
    and carrying the legacy engine as well would double the size for nothing.
  - `lang/eng.traineddata.gz` — the English training data, which the library
    would otherwise fetch from tessdata.projectnaptha.com on first use.

This is the largest thing committed to this repository and it earns its place
only when a church scans its own books; the reader itself never loads it.

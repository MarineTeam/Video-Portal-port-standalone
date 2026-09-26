# pdf.js

`pdf.mjs` and `pdf.worker.mjs` are the minified builds from `pdfjs-dist`,
committed rather than installed: this app runs on shared hosting with no
build step, and the offline shell has no bundler to resolve them with.

The character maps (`cmaps/`) are deliberately not here. They are a megabyte
and a half and only matter for documents using CJK encodings; pdf.js renders
everything else without them, and says so in the console rather than failing
if it ever meets one.

Update by replacing both files from the same `pdfjs-dist` release — they are
one pair, and a worker from another version refuses to load.

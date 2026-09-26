# epub.js

`epub.js` is the minified UMD build from `epubjs`, and `jszip.js` is JSZip,
which that build expects to find as a global rather than importing.

Committed rather than installed, for the same reason as pdf.js: no build step
on the host, and the offline shell has no bundler.

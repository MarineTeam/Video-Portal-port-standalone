// Points the exporter's `import pg from 'pg'` at the stand-in beside this
// file, so the real export.mjs runs unchanged and with nothing installed.
import { pathToFileURL } from 'node:url';

const fake = pathToFileURL(new URL('./fake-pg.mjs', import.meta.url).pathname).href;

export function resolve(specifier, context, next) {
  return specifier === 'pg'
    ? { url: fake, shortCircuit: true }
    : next(specifier, context);
}

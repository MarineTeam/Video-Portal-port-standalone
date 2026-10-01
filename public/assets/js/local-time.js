// Times in the reader's own zone.
//
// The server writes every instant as UTC inside a <time datetime="…">, with
// the text beside it a plain fallback for anyone without JavaScript. Turning
// that into the reader's zone is what makes a rota say when a service really
// is for the person reading it — five to eight hours out for an American
// church, an hour out for a British one in summer.
//
// Two rules this learned the hard way:
//
// A date with no time in it is a day, not an instant. "2026-10-04" put
// through a zone west of UTC becomes the 3rd, which moves a service to the
// wrong day — so a value with no time is left exactly as it is.
//
// And pages add times after they load: the prayer wall, the live chat, every
// admin table drawn from JSON. Converting only what exists at load is how
// this came to run on one page out of seventeen.

const DATE_ONLY = /^\d{4}-\d{2}-\d{2}$/;

/** Converts one <time>, and says whether it did. */
export function localise(el) {
  // Done already: an observer can meet the same node twice, when one is
  // moved rather than created, and this says so rather than relying on the
  // caller's selector to have dropped the attribute.
  if (el.hasAttribute('data-localised')) {
    return false;
  }
  const raw = el.getAttribute('datetime');
  if (raw === null || DATE_ONLY.test(raw.trim())) {
    return false;
  }
  const at = new Date(raw);
  if (Number.isNaN(at.getTime())) {
    return false;
  }
  el.textContent = 'localTime' in el.dataset
    ? at.toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' })
    : at.toLocaleDateString([], { dateStyle: 'medium' });
  // So a second pass does not reformat what it already formatted.
  delete el.dataset.localTime;
  delete el.dataset.localDate;
  el.setAttribute('data-localised', '');
  return true;
}

export const SELECTOR = 'time[data-local-time], time[data-local-date]';

/** Every one currently in the page, or under one element. */
export function localiseAll(root = document) {
  let done = 0;
  for (const el of root.querySelectorAll(SELECTOR)) {
    if (localise(el)) {
      done += 1;
    }
  }
  return done;
}

/**
 * Converts what is here now, and whatever arrives later.
 *
 * A MutationObserver rather than asking each feature to call this: a page
 * that draws a table from JSON should not have to know this exists, and the
 * one that forgot is exactly the bug this replaces.
 */
export function watch() {
  localiseAll();
  if (typeof MutationObserver !== 'function') {
    return null;
  }
  const observer = new MutationObserver((records) => {
    for (const record of records) {
      for (const node of record.addedNodes) {
        if (node.nodeType !== 1) {
          continue;
        }
        if (node.matches?.(SELECTOR)) {
          localise(node);
        }
        localiseAll(node);
      }
    }
  });
  observer.observe(document.documentElement, { childList: true, subtree: true });
  return observer;
}

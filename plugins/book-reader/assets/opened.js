// That a hymn was really opened, for "what does this congregation sing".
//
// From the browser rather than from the page's render, because hovering a
// link prefetches it and a server-side count would largely be a count of
// mice. The cost runs the other way — an opening nobody's browser could
// report goes uncounted — which is the right way round for a number nothing
// depends on, and why nothing here waits for or reacts to the answer.
import MT from '../../../assets/js/mt.js';

for (const element of document.querySelectorAll('[data-opened]')) {
  let opening;
  try {
    opening = JSON.parse(element.dataset.opened);
  } catch {
    continue;
  }
  if (!opening?.fileId || !opening?.source) continue;
  MT.api('/api/hymns/lookup', { method: 'POST', body: opening }).catch(() => {});
}

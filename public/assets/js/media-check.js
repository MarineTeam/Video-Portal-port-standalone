// /admin/media-check: checks pasted links a batch at a time, asking again
// with the cursor the server returns until there is none.
import MT from './mt.js';

const box = document.querySelector('[data-link-check]');
const start = box?.querySelector('[data-link-check-start]');

start?.addEventListener('click', async () => {
  const table = box.querySelector('[data-link-check-table]');
  const body = table.querySelector('tbody');
  const progress = box.querySelector('[data-link-check-progress]');
  const error = box.querySelector('[data-error]');
  start.disabled = true;
  error.hidden = true;
  body.textContent = '';
  table.hidden = false;
  progress.hidden = false;
  let after = null;
  let checked = 0;
  let broken = 0;
  try {
    do {
      progress.textContent = `Checked ${checked}…`;
      const answer = await MT.api('/api/admin/media-check/links', { method: 'POST', body: { after } });
      for (const row of answer.results) {
        checked += 1;
        if (!row.ok) broken += 1;
        const tr = document.createElement('tr');
        const name = document.createElement('td');
        const link = Object.assign(document.createElement('a'), { href: MT.url(`/admin/videos/${row.id}`), textContent: row.title });
        name.append(link);
        const result = document.createElement('td');
        const badge = Object.assign(document.createElement('span'), { className: row.ok ? 'badge' : 'badge error', textContent: row.ok ? 'OK' : 'Broken' });
        result.append(badge, ` ${row.detail}`);
        tr.append(name, result);
        // Broken ones first.
        if (row.ok) body.append(tr); else body.prepend(tr);
      }
      after = answer.next;
    } while (after);
    progress.textContent = broken === 0 ? `All ${checked} answer.` : `${broken} of ${checked} don’t answer.`;
  } catch (e) {
    error.textContent = e.message;
    error.hidden = false;
  } finally {
    start.disabled = false;
  }
});

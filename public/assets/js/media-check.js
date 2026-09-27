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

/** A size somebody can act on: a kilobyte orphan should not read "0.0 MB". */
function size(bytes) {
  const units = ['B', 'KB', 'MB', 'GB'];
  let n = bytes;
  let i = 0;
  while (n >= 1024 && i < units.length - 1) { n /= 1024; i += 1; }
  return `${n.toFixed(i === 0 ? 0 : 1)} ${units[i]}`;
}

// What Bunny holds against what this site thinks it holds. One request, and
// a slow one, so the page says it is working and then draws both halves.
const audit = document.querySelector('[data-bunny-audit]');
audit?.querySelector('[data-bunny-audit-start]')?.addEventListener('click', async (event) => {
  const button = event.currentTarget;
  const progress = audit.querySelector('[data-bunny-audit-progress]');
  const result = audit.querySelector('[data-bunny-audit-result]');
  const error = audit.querySelector('[data-error]');
  button.disabled = true;
  error.hidden = true;
  result.hidden = true;
  result.textContent = '';
  progress.textContent = 'Asking Bunny, a folder at a time…';
  progress.hidden = false;
  try {
    const answer = await MT.api('/api/admin/bunny-audit');
    for (const [key, noun] of [['files', 'file'], ['videos', 'video']]) {
      const half = answer[key];
      const box = document.createElement('section');
      box.append(Object.assign(document.createElement('h3'), { textContent: noun === 'file' ? 'Bunny Storage' : 'Bunny Stream' }));
      if (!half.checked) {
        box.append(Object.assign(document.createElement('p'), { className: 'small muted', textContent: half.why }));
        result.append(box);
        continue;
      }
      const say = (text, className = 'small muted') => box.append(Object.assign(document.createElement('p'), { className, textContent: text }));
      say(`${half.atBunny} at Bunny${half.partial ? ', and more than this reader will walk — what follows is only as far as it got' : ''}.`);
      if (half.orphans.length === 0 && half.missing.length === 0) {
        say('Everything here is there, and everything there is used.', 'notice ok');
      }
      if (half.missing.length > 0) {
        say(`${half.missing.length} ${noun}${half.missing.length === 1 ? '' : 's'} here point at something Bunny does not have:`, 'small');
        const list = document.createElement('ul');
        list.className = 'plain';
        for (const row of half.missing) {
          const li = document.createElement('li');
          li.className = 'small';
          li.append(Object.assign(document.createElement('a'), {
            href: MT.url(noun === 'file' ? `/admin/files?q=${encodeURIComponent(row.title)}` : `/admin/videos/${row.id}`),
            textContent: row.title,
          }), ' ', Object.assign(document.createElement('code'), { textContent: row.path || row.guid }));
          list.append(li);
        }
        box.append(list);
      }
      if (half.orphans.length > 0) {
        // Only the storage half has sizes, so only it is ordered by them.
        say(`${half.orphans.length} at Bunny that nothing here names${noun === 'file' ? ', biggest first' : ''} — paid for and unreachable:`, 'small');
        const list = document.createElement('ul');
        list.className = 'plain';
        for (const row of half.orphans) {
          const li = document.createElement('li');
          li.className = 'small';
          li.append(Object.assign(document.createElement('code'), { textContent: row.path || row.guid }));
          if (row.title) li.append(' ', row.title);
          if (row.bytes) li.append(' ', Object.assign(document.createElement('span'), { className: 'muted', textContent: size(row.bytes) }));
          list.append(li);
        }
        box.append(list);
      }
      result.append(box);
    }
    result.hidden = false;
  } catch (e) {
    error.textContent = e.message;
    error.hidden = false;
  } finally {
    progress.hidden = true;
    button.disabled = false;
  }
});

// Admin → Backup & import: makes the database backup a step per request,
// then downloads it.
import MT from './mt.js';

const box = document.querySelector('[data-backup]');
const start = box?.querySelector('[data-backup-start]');
const progress = box?.querySelector('[data-backup-progress]');
const status = box?.querySelector('[data-backup-status]');
const errorLine = box?.querySelector('[data-error]');

function size(bytes) {
  return bytes >= 1048576 ? `${(bytes / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(bytes / 1024))} KB`;
}

start?.addEventListener('click', async () => {
  start.disabled = true;
  errorLine.hidden = true;
  progress.hidden = false;
  try {
    let state = await MT.api('/api/admin/tools/backup', { method: 'POST' });
    while (state.stage !== 'ready') {
      state = await MT.api(`/api/admin/tools/backup/${state.id}/step`, { method: 'POST' });
      progress.value = state.tables ? Math.round((state.table / state.tables) * 100) : 100;
      status.textContent = `${state.rows.toLocaleString()} rows from ${state.table} of ${state.tables} tables, ${size(state.bytes)} so far…`;
    }
    status.textContent = `Done: ${state.rows.toLocaleString()} rows, ${size(state.bytes)}. The download should start now; the file is removed from the server once it has.`;
    window.location.href = MT.url(`/api/admin/tools/backup/${state.id}/download`);
  } catch (error) {
    errorLine.textContent = error.message;
    errorLine.hidden = false;
  } finally {
    start.disabled = false;
  }
});

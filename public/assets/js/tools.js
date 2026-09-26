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

// The import from the Next.js site: the export is uploaded in chunks, then
// read a batch per request so no single request has to finish the job.
const importBox = document.querySelector('[data-import]');
const importForm = importBox?.querySelector('[data-import-form]');
const importFile = importBox?.querySelector('[data-import-file]');
const uploadProgress = importBox?.querySelector('[data-upload-progress]');
const importProgress = importBox?.querySelector('[data-import-progress]');
const importStatus = importBox?.querySelector('[data-import-status]');
const importReport = importBox?.querySelector('[data-import-report]');
const importRows = importBox?.querySelector('[data-import-rows]');
const importError = importBox?.querySelector('[data-error]');

const PHASES = {
  unpack: 'Unpacking the export',
  load: 'Reading rows in',
  relink: 'Putting the tree back together',
  done: 'Done',
};

/** The counts, worst first, so a table that did not come over is the first thing read. */
function showReport(state) {
  if (!importRows) return;
  importRows.replaceChildren();
  for (const row of state.report) {
    const tr = document.createElement('tr');
    for (const [text, title] of [
      [row.table, row.model],
      [row.expected.toLocaleString(), ''],
      [row.written.toLocaleString(), ''],
      [row.present.toLocaleString(), ''],
      [row.refused.toLocaleString(), ''],
    ]) {
      const td = document.createElement('td');
      td.textContent = text;
      if (title) td.title = title;
      tr.append(td);
    }
    if (!row.ok) tr.classList.add('warn');
    importRows.append(tr);
  }
  importReport.hidden = false;
}

/** What the screen says when it is over, including what was deliberately left behind. */
function finished(state) {
  const short = state.report.filter((r) => !r.ok);
  const lines = [
    short.length === 0
      ? 'Every table matches the export’s own counts.'
      : `${short.length} table${short.length === 1 ? '' : 's'} came in short of the export’s counts — they are at the top of the list.`,
  ];
  for (const one of state.skipped) lines.push(one.why);
  if (state.unknown.length) lines.push(`The export also held ${state.unknown.join(', ')}, which this site has no table for.`);
  for (const one of state.dropped) lines.push(`${one.model}: no column here for ${one.fields.join(', ')}.`);
  if (state.errors.length) {
    lines.push(`${state.errors.length} row${state.errors.length === 1 ? '' : 's'} were refused, the first being: ${state.errors[0].model} ${state.errors[0].id} — ${state.errors[0].message}`);
  }
  importStatus.textContent = lines.join(' ');
}

importForm?.addEventListener('submit', async (event) => {
  event.preventDefault();
  if (!importFile?.files?.length) return;
  const button = importForm.querySelector('button[type=submit]');
  button.disabled = true;
  importError.hidden = true;
  uploadProgress.hidden = false;
  try {
    const { uploadInChunks } = await import('./forms.js');
    const upload = await uploadInChunks(importFile.files[0], 'import', (p) => {
      uploadProgress.value = Math.round(p * 100);
    });
    uploadProgress.hidden = true;
    importProgress.hidden = false;
    let state = await MT.api('/api/admin/tools/import', { method: 'POST', body: { upload } });
    while (state.phase !== 'done') {
      importStatus.textContent = `${PHASES[state.phase]}: ${state.model ?? ''} (${state.at} of ${state.models} tables)`;
      importProgress.value = state.models ? Math.round((state.at / state.models) * 100) : 0;
      showReport(state);
      state = await MT.api(`/api/admin/tools/import/${state.id}/step`, { method: 'POST' });
    }
    importProgress.value = 100;
    showReport(state);
    finished(state);
  } catch (error) {
    importError.textContent = error.message;
    importError.hidden = false;
  } finally {
    button.disabled = false;
  }
});

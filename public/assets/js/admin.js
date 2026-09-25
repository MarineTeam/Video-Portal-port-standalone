// Admin-only behaviour. The data-api forms and the chunked uploader live in
// forms.js, which member pages share; this file re-exports them for plugins
// written against it, and adds what only the library admin screens need.
import MT from './mt.js';
import { uploadInChunks, formToJson } from './forms.js';

export { uploadInChunks, formToJson };

// A datetime-local field shows local time; the server stores UTC. Fill each
// from its ISO value so an edit form opens showing what's saved.
function toLocalInput(iso) {
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return '';
  const pad = (n) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}
for (const input of document.querySelectorAll('input[type="datetime-local"][data-iso]')) {
  if (input.dataset.iso) input.value = toLocalInput(input.dataset.iso);
}

// An image field's upload: stored here, and its address put in the field.
for (const picker of document.querySelectorAll('input[type=file][data-image-upload]')) {
  picker.addEventListener('change', async () => {
    const file = picker.files?.[0];
    if (!file) return;
    const target = document.getElementById(picker.dataset.imageUpload);
    const preview = document.querySelector(`[data-image-preview="${picker.dataset.imageUpload}"]`);
    const label = picker.closest('label');
    label?.classList.add('busy');
    try {
      const upload = await uploadInChunks(file, 'image');
      const { url } = await MT.api('/api/admin/media', { method: 'POST', body: { upload, kind: picker.dataset.kind || 'covers' } });
      if (target) target.value = url;
      if (preview) {
        preview.src = url;
        preview.hidden = false;
      }
    } catch (error) {
      window.alert(error.message);
    } finally {
      label?.classList.remove('busy');
      picker.value = '';
    }
  });
}

// Bulk actions on a list: tick rows, then act on all of them, one request
// each (the same PATCH/DELETE a single row uses), then reload.
for (const box of document.querySelectorAll('[data-bulk]')) {
  const template = box.dataset.bulk;
  const selected = () => [...box.querySelectorAll('input[data-bulk-id]:checked')].map((c) => c.dataset.bulkId);
  const count = box.querySelector('[data-bulk-count]');
  const refresh = () => { if (count) count.textContent = `${selected().length} selected`; };
  box.addEventListener('change', (e) => {
    if (e.target.matches('[data-bulk-all]')) {
      for (const c of box.querySelectorAll('input[data-bulk-id]')) c.checked = e.target.checked;
    }
    refresh();
  });
  const run = async (method, body) => {
    const ids = selected();
    if (ids.length === 0) return;
    try {
      for (const id of ids) await MT.api(template.replace('{id}', id), { method, body });
      window.location.reload();
    } catch (error) {
      window.alert(error.message);
    }
  };
  for (const button of box.querySelectorAll('[data-bulk-action]')) {
    button.addEventListener('click', () => {
      if (button.dataset.confirm && !window.confirm(button.dataset.confirm)) return;
      const action = button.dataset.bulkAction;
      if (action === 'delete') run('DELETE');
      else run('PATCH', JSON.parse(action));
    });
  }
  box.querySelector('[data-bulk-move]')?.addEventListener('change', (e) => {
    const value = e.target.value;
    if (value === '') return;
    run('PATCH', { categoryId: value === 'none' ? null : value });
  });
}

// "Save as draft": the same form, PUT to the draft route instead.
for (const button of document.querySelectorAll('[data-draft-save]')) {
  button.addEventListener('click', async () => {
    const form = button.closest('form');
    try {
      await MT.api(button.dataset.draftSave, { method: 'PUT', body: formToJson(form) });
      window.location.reload();
    } catch (error) {
      const target = form.querySelector('[data-error]');
      if (target) {
        target.textContent = error.message;
        target.hidden = false;
      }
    }
  });
}
// "Load into form": the staged values over the live ones.
for (const banner of document.querySelectorAll('[data-draft]')) {
  banner.querySelector('[data-draft-load]')?.addEventListener('click', () => {
    const data = JSON.parse(banner.dataset.draft || '{}');
    const form = document.getElementById('series-form');
    for (const [name, value] of Object.entries(data)) {
      const field = form?.elements.namedItem(name);
      if (!field) continue;
      if (field.type === 'checkbox') field.checked = value === true;
      else if (field.type === 'datetime-local') field.value = value ? toLocalInput(value) : '';
      else if (Array.isArray(value)) field.value = value.join(', ');
      else field.value = value === null ? '' : String(value);
    }
  });
}

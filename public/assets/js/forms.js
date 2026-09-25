// Forms and buttons that talk to the JSON API, and the chunked uploader —
// shared by the member pages and the admin area. A module evaluates once,
// so importing it from both app.js and admin.js registers each listener once.
import MT from './mt.js';

export async function uploadInChunks(file, purpose, onProgress) {
  const created = await MT.api('/api/uploads', { method: 'POST', body: { purpose, fileName: file.name, size: file.size } });
  const { id, chunkSize } = created;
  let offset = 0;
  while (offset < file.size) {
    const slice = file.slice(offset, offset + chunkSize);
    let result;
    try {
      result = await MT.api(`/api/uploads/${id}/chunk?offset=${offset}`, { method: 'PUT', body: slice, headers: { 'Content-Type': 'application/octet-stream' } });
    } catch (error) {
      if (error.status === 409 && error.data && typeof error.data.received === 'number') {
        offset = error.data.received;
        continue;
      }
      throw error;
    }
    offset = result.received;
    onProgress?.(offset / file.size);
  }
  return id;
}

for (const form of document.querySelectorAll('form[data-chunked-upload]')) {
  form.addEventListener('submit', async (event) => {
    const input = form.querySelector('[data-upload-file]');
    const target = form.querySelector('[data-upload-id]');
    if (!input?.files?.length || target.value) return;
    event.preventDefault();
    // A data-api form is sent by the handler below once the id is in place.
    event.stopPropagation();
    const progress = form.querySelector('[data-upload-progress]');
    if (progress) progress.hidden = false;
    const button = form.querySelector('button[type=submit]');
    if (button) button.disabled = true;
    try {
      target.value = await uploadInChunks(input.files[0], form.dataset.chunkedUpload, (p) => { if (progress) progress.value = Math.round(p * 100); });
      input.disabled = true;
      if (form.dataset.api) {
        // requestSubmit fires the submit event, whose JSON body is read
        // synchronously; after it the form is ready for another file.
        form.requestSubmit();
        target.value = '';
        input.disabled = false;
        if (button) button.disabled = false;
      } else {
        form.submit();
      }
    } catch (error) {
      window.alert(error.message);
      if (button) button.disabled = false;
    }
  });
}

// Forms and buttons that talk to the JSON API directly:
//   <form data-api="/api/admin/authorized-emails" data-method="POST">
//   <button data-api="/api/admin/users/ID" data-method="PATCH" data-body='{"role":"ADMIN"}'>
// A field's data-type says how to send it: bool (a checkbox), int, json,
// list (comma or newline separated) — otherwise a string; data-null sends an
// empty value as null. On success the page reloads (or follows data-redirect);
// on failure the API's own sentence is shown in the form's [data-error].
export function formToJson(form) {
  const body = {};
  for (const field of form.elements) {
    if (!field.name || field.disabled || field.name === '_csrf' || field.type === 'submit' || field.type === 'button') continue;
    const type = field.dataset.type || '';
    if (field.type === 'checkbox' && type !== 'multi') {
      body[field.name] = field.checked;
      continue;
    }
    if (field.type === 'checkbox' && type === 'multi') {
      body[field.name] = body[field.name] || [];
      if (field.checked) body[field.name].push(field.value);
      continue;
    }
    if (field.type === 'radio') {
      if (field.checked) body[field.name] = field.value;
      continue;
    }
    let value = field.type === 'select-multiple' ? [...field.selectedOptions].map((o) => o.value) : field.value;
    if (value === '' && 'null' in field.dataset) value = null;
    else if (type === 'int' && value !== '') value = Number.parseInt(value, 10);
    else if (type === 'bool') value = value === '1' || value === 'true';
    else if (type === 'json' && value !== '') value = JSON.parse(value);
    else if (type === 'list') value = String(value).split(/[\n,]+/).map((s) => s.trim()).filter(Boolean);
    else if (type === 'datetime' && value) value = new Date(value).toISOString();
    body[field.name] = value;
  }
  return body;
}

function showError(scope, message) {
  const target = scope?.querySelector?.('[data-error]');
  if (target) {
    target.textContent = message;
    target.hidden = false;
  } else {
    window.alert(message);
  }
}

async function send(element, body) {
  const confirmText = element.dataset.confirm;
  if (confirmText && !window.confirm(confirmText)) return;
  const method = (element.dataset.method || 'POST').toUpperCase();
  try {
    const result = await MT.api(element.dataset.api, { method, body: method === 'GET' || method === 'DELETE' && body === undefined ? undefined : body });
    MT.hooks.do('admin.saved', element, result);
    MT.hooks.do('api.saved', element, result);
    if (element.dataset.done) {
      // Say it worked, in place, and clear the form (a password form).
      const scope = element.closest('form') || element.parentElement;
      const message = scope?.querySelector('[data-done-message]');
      if (message) {
        message.textContent = element.dataset.done;
        message.hidden = false;
      }
      scope?.querySelector('[data-error]')?.setAttribute('hidden', '');
      if (element instanceof HTMLFormElement) element.reset();
    }
    if ('follow' in element.dataset && result && typeof result.redirect === 'string') {
      // The server names where to go next (a share link's content, once unlocked).
      window.location.href = result.redirect;
    } else if (element.dataset.redirect) {
      window.location.href = MT.url(element.dataset.redirect.replace('{id}', result && result.id ? result.id : ''));
    } else if (!('noReload' in element.dataset)) {
      window.location.reload();
    }
  } catch (error) {
    const fields = error.data && error.data.fields ? Object.values(error.data.fields).join(' ') : '';
    showError(element.closest('form') || element.parentElement, fields || error.message);
  }
}

document.addEventListener('submit', (event) => {
  const form = event.target;
  if (!(form instanceof HTMLFormElement) || !form.dataset.api) return;
  event.preventDefault();
  send(form, formToJson(form));
});

document.addEventListener('click', (event) => {
  const button = event.target.closest?.('button[data-api], a[data-api]');
  if (!button || button.closest('form[data-api]') === button.form && button.type === 'submit' && button.form) return;
  event.preventDefault();
  send(button, button.dataset.body ? JSON.parse(button.dataset.body) : undefined);
});

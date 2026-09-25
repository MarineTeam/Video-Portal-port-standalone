// The videos and files admin: adding a video by upload (whatever the Video
// slot's service asks the browser to do), the Bunny import, bulk actions, a
// video's thumbnail and captions, and replacing a file. The server hands each
// upload a ticket; no long-lived credential reaches this page.
import MT from './mt.js';
import { uploadInChunks, formToJson } from './forms.js';

const RESUMABLE_CHUNK = 10 * 1024 * 1024; // a multiple of 256 KiB (Google) and 320 KiB (OneDrive)

function showError(form, message) {
  const target = form.querySelector('[data-error]');
  if (target) {
    target.textContent = message;
    target.hidden = false;
  } else {
    window.alert(message);
  }
}

function loadScript(src) {
  return new Promise((resolve, reject) => {
    const script = document.createElement('script');
    script.src = MT.url(src);
    script.onload = resolve;
    script.onerror = () => reject(new Error('The uploader couldn’t be loaded.'));
    document.head.append(script);
  });
}

// One request through XMLHttpRequest, for its upload progress events.
function xhr(method, url, body, headers, onProgress) {
  return new Promise((resolve, reject) => {
    const request = new XMLHttpRequest();
    request.open(method, url);
    for (const [name, value] of Object.entries(headers || {})) request.setRequestHeader(name, value);
    request.upload.onprogress = (e) => { if (e.lengthComputable) onProgress?.(e.loaded); };
    request.onload = () => resolve(request);
    request.onerror = () => reject(new Error('The upload was interrupted. Check the connection and try again.'));
    request.send(body);
  });
}

const kinds = {
  async tus(file, ticket, progress) {
    if (!window.tus) await loadScript('/vendor-js/tus/tus.min.js');
    await new Promise((resolve, reject) => {
      const upload = new window.tus.Upload(file, {
        endpoint: ticket.endpoint,
        uploadUrl: ticket.uploadUrl,
        headers: ticket.headers || {},
        metadata: ticket.metadata || {},
        chunkSize: 50 * 1024 * 1024,
        retryDelays: [0, 3000, 10000, 30000],
        removeFingerprintOnSuccess: true,
        onProgress: (sent, total) => progress(sent / total),
        onError: (error) => reject(new Error(`The upload failed: ${error.message || error}`)),
        onSuccess: resolve,
      });
      upload.start();
    });
    return { completed: true };
  },

  async put(file, ticket, progress) {
    const r = await xhr('PUT', ticket.url, file, ticket.headers, (sent) => progress(sent / file.size));
    if (r.status < 200 || r.status >= 300) throw new Error(`The storage service refused the upload (${r.status}).`);
    return { completed: true };
  },

  async multipart(file, ticket, progress) {
    const parts = [];
    let done = 0;
    for (const part of ticket.parts) {
      const start = (part.number - 1) * ticket.partSize;
      const slice = file.slice(start, Math.min(start + ticket.partSize, file.size));
      let r;
      for (let attempt = 0; attempt < 3; attempt++) {
        try {
          r = await xhr('PUT', part.url, slice, {}, (sent) => progress((done + sent) / file.size));
          if (r.status >= 200 && r.status < 300) break;
        } catch (error) {
          if (attempt === 2) throw error;
        }
      }
      if (!r || r.status < 200 || r.status >= 300) throw new Error(`Part ${part.number} was refused (${r ? r.status : 'no answer'}).`);
      const etag = r.getResponseHeader('ETag');
      if (!etag) throw new Error('The bucket doesn’t expose ETag to this site. Add the CORS rule shown under Services.');
      parts.push({ number: part.number, etag });
      done += slice.size;
    }
    return { parts };
  },

  // A session URL the server opened: PUT the file in ranges, and read the
  // service's id for it from the last answer.
  async resumable(file, ticket, progress) {
    let offset = 0;
    let last;
    while (offset < file.size) {
      const end = Math.min(offset + RESUMABLE_CHUNK, file.size);
      last = await xhr(ticket.method || 'PUT', ticket.url, file.slice(offset, end), {
        'Content-Range': `bytes ${offset}-${end - 1}/${file.size}`,
      }, (sent) => progress((offset + sent) / file.size));
      if (last.status === 308 || last.status === 202) {
        const range = last.getResponseHeader('Range');
        const next = range ? Number(range.split('-')[1]) + 1 : end;
        offset = Number.isFinite(next) ? next : end;
        continue;
      }
      if (last.status < 200 || last.status >= 300) throw new Error(`The upload was refused (${last.status}).`);
      offset = end;
    }
    let body = {};
    try { body = JSON.parse(last.responseText || '{}'); } catch { /* no body */ }
    return body.id ? { externalId: String(body.id) } : { completed: true };
  },

  async chunked(file, ticket, progress) {
    return { upload: await uploadInChunks(file, ticket.purpose || 'video', progress) };
  },
};

// Tabs: link, upload, Bunny import.
const add = document.querySelector('[data-video-add]');
if (add) {
  for (const tab of add.querySelectorAll('[data-video-tab]')) {
    tab.addEventListener('click', () => {
      for (const other of add.querySelectorAll('[data-video-tab]')) other.setAttribute('aria-selected', String(other === tab));
      for (const panel of add.querySelectorAll('[data-video-panel]')) panel.hidden = panel.dataset.videoPanel !== tab.dataset.videoTab;
    });
  }
}

const uploadForm = document.querySelector('form[data-video-upload]');
uploadForm?.addEventListener('submit', async (event) => {
  event.preventDefault();
  const input = uploadForm.elements.namedItem('file');
  const file = input?.files?.[0];
  if (!file) return;
  const bar = uploadForm.querySelector('[data-upload-progress]');
  const status = uploadForm.querySelector('[data-upload-status]');
  const button = uploadForm.querySelector('button[type=submit]');
  const say = (text) => { status.textContent = text; status.hidden = false; };
  const progress = (p) => { bar.hidden = false; bar.value = Math.round(p * 100); };
  button.disabled = true;
  uploadForm.querySelector('[data-error]')?.setAttribute('hidden', '');
  const fields = formToJson(uploadForm);
  delete fields.file;
  let video;
  try {
    const created = await MT.api('/api/admin/videos', {
      method: 'POST',
      body: { ...fields, mode: 'upload', fileName: file.name, size: file.size, mimeType: file.type || 'video/mp4' },
    });
    video = created.video;
    const handler = kinds[created.upload.kind];
    if (!handler) throw new Error('This service’s uploads aren’t supported from the browser yet.');
    say('Uploading…');
    const report = await handler(file, created.upload, progress);
    say('Finishing…');
    await MT.api(`/api/admin/videos/${video.id}/sync-status`, { method: 'POST', body: report });
    window.location.href = MT.url(`/admin/videos/${video.id}`);
  } catch (error) {
    showError(uploadForm, video
      ? `${error.message} The video was added as “${video.title}” and is marked processing; delete it or try “Check status” later.`
      : error.message);
    button.disabled = false;
  }
});

// The Bunny import: list what the library holds that this site doesn't.
const bunnyForm = document.querySelector('form[data-bunny-import]');
bunnyForm?.querySelector('[data-bunny-load]')?.addEventListener('click', async () => {
  const list = bunnyForm.querySelector('[data-bunny-list]');
  list.textContent = 'Looking…';
  try {
    const items = await MT.api('/api/admin/videos/bunny-library');
    list.textContent = '';
    if (items.length === 0) list.textContent = 'Everything in the library is already here.';
    for (const item of items) {
      const label = document.createElement('label');
      label.className = 'check';
      const box = document.createElement('input');
      box.type = 'checkbox';
      box.value = item.guid;
      box.dataset.guid = '';
      label.append(box, ` ${item.title || item.guid}`);
      list.append(label);
    }
  } catch (error) {
    list.textContent = '';
    showError(bunnyForm, error.message);
  }
});
bunnyForm?.addEventListener('submit', async (event) => {
  event.preventDefault();
  const guids = [...bunnyForm.querySelectorAll('input[data-guid]:checked')].map((b) => b.value);
  if (guids.length === 0) return;
  const seriesId = bunnyForm.elements.namedItem('seriesId').value || null;
  try {
    await MT.api('/api/admin/videos/import', { method: 'POST', body: { guids, seriesId } });
    window.location.reload();
  } catch (error) {
    showError(bunnyForm, error.message);
  }
});

// Bulk actions: one request for all the ticked rows.
for (const box of document.querySelectorAll('[data-bulk-endpoint]')) {
  const selected = () => [...box.querySelectorAll('input[data-bulk-id]:checked')].map((c) => c.dataset.bulkId);
  const count = box.querySelector('[data-bulk-count]');
  box.addEventListener('change', (e) => {
    if (e.target.matches('[data-bulk-all]')) {
      for (const c of box.querySelectorAll('input[data-bulk-id]')) c.checked = e.target.checked;
    }
    if (count) count.textContent = `${selected().length} selected`;
  });
  const run = async (body) => {
    const ids = selected();
    if (ids.length === 0) return;
    try {
      await MT.api(box.dataset.bulkEndpoint, { method: 'POST', body: { ids, ...body } });
      window.location.reload();
    } catch (error) {
      window.alert(error.message);
    }
  };
  for (const button of box.querySelectorAll('[data-bulk-op]')) {
    button.addEventListener('click', () => {
      if (button.dataset.confirm && !window.confirm(button.dataset.confirm)) return;
      run({ action: button.dataset.bulkOp });
    });
  }
  box.querySelector('[data-bulk-op-move]')?.addEventListener('change', (e) => {
    if (e.target.value === '') return;
    run({ action: 'move', seriesId: e.target.value === 'none' ? null : e.target.value });
  });
}

// An upload handed to one endpoint as {upload}: a file's replacement.
for (const picker of document.querySelectorAll('input[type=file][data-upload-to]')) {
  picker.addEventListener('change', async () => {
    const file = picker.files?.[0];
    if (!file) return;
    if (picker.dataset.confirm && !window.confirm(picker.dataset.confirm)) {
      picker.value = '';
      return;
    }
    const label = picker.closest('label');
    label?.classList.add('busy');
    try {
      const upload = await uploadInChunks(file, picker.dataset.purpose || 'file');
      await MT.api(picker.dataset.uploadTo, { method: 'POST', body: { upload } });
      window.location.reload();
    } catch (error) {
      window.alert(error.message);
      picker.value = '';
    } finally {
      label?.classList.remove('busy');
    }
  });
}

// A new thumbnail: uploaded here, then handed to the video.
for (const picker of document.querySelectorAll('input[type=file][data-video-thumbnail]')) {
  picker.addEventListener('change', async () => {
    const file = picker.files?.[0];
    if (!file) return;
    try {
      const upload = await uploadInChunks(file, 'image');
      await MT.api(picker.dataset.videoThumbnail, { method: 'POST', body: { upload } });
      window.location.reload();
    } catch (error) {
      window.alert(error.message);
      picker.value = '';
    }
  });
}

// Captions: a .vtt or .srt file, a language code and a label.
for (const form of document.querySelectorAll('form[data-video-captions]')) {
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const file = form.elements.namedItem('file').files?.[0];
    if (!file) return;
    try {
      const upload = await uploadInChunks(file, 'caption');
      await MT.api(form.dataset.videoCaptions, {
        method: 'POST',
        body: { srclang: form.elements.namedItem('srclang').value, label: form.elements.namedItem('label').value, upload },
      });
      window.location.reload();
    } catch (error) {
      showError(form, error.message);
    }
  });
}

// Bunny Storage browser: folders to walk, files to tick and import.
const storage = document.querySelector('[data-storage-browser]');
if (storage) {
  let dir = '';
  const list = storage.querySelector('[data-storage-entries]');
  const load = async () => {
    storage.querySelector('[data-storage-dir]').textContent = `/${dir}`;
    storage.querySelector('[data-storage-up]').hidden = dir === '';
    list.textContent = '';
    try {
      const { entries } = await MT.api(`/api/admin/files/bunny-storage?dir=${encodeURIComponent(dir)}`);
      if (entries.length === 0) list.innerHTML = '<li class="muted">Empty.</li>';
      for (const entry of entries) {
        const li = document.createElement('li');
        if (entry.isDirectory) {
          const open = document.createElement('button');
          open.type = 'button';
          open.className = 'link';
          open.textContent = `📁 ${entry.name}`;
          open.addEventListener('click', () => { dir = entry.path; load(); });
          li.append(open);
        } else {
          const label = document.createElement('label');
          label.className = 'check';
          const box = document.createElement('input');
          box.type = 'checkbox';
          box.value = entry.path;
          box.disabled = entry.imported || !entry.importable;
          label.append(box, ` ${entry.name} `);
          const note = document.createElement('span');
          note.className = 'muted';
          note.textContent = entry.imported ? '(already here)' : !entry.importable ? '(not a type this site takes)' : `${Math.round(entry.size / 1048576)} MB`;
          label.append(note);
          li.append(label);
        }
        list.append(li);
      }
    } catch (error) {
      showError(storage, error.message);
    }
  };
  storage.addEventListener('toggle', () => { if (storage.open && !list.dataset.loaded) { list.dataset.loaded = '1'; load(); } });
  storage.querySelector('[data-storage-up]').addEventListener('click', () => { dir = dir.split('/').slice(0, -1).join('/'); load(); });
  storage.querySelector('[data-storage-import]').addEventListener('click', async () => {
    const paths = [...list.querySelectorAll('input:checked')].map((b) => b.value);
    if (paths.length === 0) return;
    try {
      await MT.api('/api/admin/files/import', { method: 'POST', body: { paths, seriesId: storage.querySelector('[data-storage-series]').value || null } });
      window.location.reload();
    } catch (error) {
      showError(storage, error.message);
    }
  });
}

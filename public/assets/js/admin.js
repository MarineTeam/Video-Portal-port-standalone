// Admin-only behaviour: the chunked uploader for forms marked
// data-chunked-upload="<purpose>". The file goes up in slices no larger than
// half the host's upload limit, resuming from what the server says it holds.
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
    const progress = form.querySelector('[data-upload-progress]');
    if (progress) progress.hidden = false;
    const button = form.querySelector('button[type=submit]');
    if (button) button.disabled = true;
    try {
      target.value = await uploadInChunks(input.files[0], form.dataset.chunkedUpload, (p) => { if (progress) progress.value = Math.round(p * 100); });
      input.disabled = true;
      form.submit();
    } catch (error) {
      window.alert(error.message);
      if (button) button.disabled = false;
    }
  });
}

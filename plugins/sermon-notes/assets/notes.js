// The note sheet saves as it is typed (a Save someone forgets while
// listening is a lost sheet); timestamped notes add and go without
// reloading the page, which would stop the video.
function ready(fn) {
  if (window.MT) fn(window.MT);
  else document.addEventListener('DOMContentLoaded', () => fn(window.MT));
}

const format = (s) => {
  const h = Math.floor(s / 3600);
  const m = Math.floor((s % 3600) / 60);
  const sec = String(s % 60).padStart(2, '0');
  return h > 0 ? `${h}:${String(m).padStart(2, '0')}:${sec}` : `${m}:${sec}`;
};

ready((MT) => {
  if (!MT || window.__sermonNotes) return;
  window.__sermonNotes = true;

  const sheet = document.querySelector('[data-note-sheet]');
  if (sheet) {
    const status = sheet.querySelector('[data-sheet-status]');
    let timer = null;
    const save = async () => {
      const answers = {};
      for (const input of sheet.querySelectorAll('[data-gap]')) {
        if (input.value.trim() !== '') answers[input.dataset.gap] = input.value;
      }
      try {
        await MT.api('/api/videos/outline', { method: 'PUT', body: { videoId: sheet.dataset.noteSheet, outlineVersion: sheet.dataset.version, answers } });
        if (status) status.textContent = status.dataset.saved || '✓';
      } catch (error) {
        if (status) status.textContent = error.message;
      }
    };
    sheet.addEventListener('input', (event) => {
      if (!event.target.matches('[data-gap]')) return;
      clearTimeout(timer);
      timer = setTimeout(save, 700);
    });
  }

  const panel = document.querySelector('[data-notes]');
  if (!panel) return;
  const list = panel.querySelector('[data-note-list]');
  const form = panel.querySelector('[data-note-form]');
  const time = form.querySelector('[data-note-time]');
  const error = panel.querySelector('[data-error]');
  // Prefilled once from where the player is, then the member's to change.
  time.addEventListener('focus', () => {
    const player = document.querySelector('[data-player]')?.mtPlayer;
    if (player && !time.value) time.value = format(Math.floor(player.position()));
  });
  form.querySelector('[name=body]').addEventListener('focus', () => time.dispatchEvent(new Event('focus')));
  const row = (note) => {
    const li = document.createElement('li');
    li.dataset.note = note.id;
    const at = Object.assign(document.createElement('button'), { type: 'button', className: 'link chapter-time', textContent: format(note.timestampSeconds) });
    at.dataset.seek = String(note.timestampSeconds);
    const body = Object.assign(document.createElement('span'), { textContent: note.body });
    const del = Object.assign(document.createElement('button'), { type: 'button', className: 'link small', textContent: panel.dataset.deleteLabel });
    del.dataset.noteDelete = '';
    li.append(at, ' ', body, ' ', del);
    return li;
  };
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    error.hidden = true;
    try {
      const note = await MT.api('/api/notes', { method: 'POST', body: { videoId: panel.dataset.notes, timestamp: time.value, body: form.elements.namedItem('body').value } });
      const after = [...list.children].find((li) => Number(li.querySelector('[data-seek]').dataset.seek) > note.timestampSeconds);
      list.insertBefore(row(note), after || null);
      form.elements.namedItem('body').value = '';
      time.value = '';
    } catch (e) {
      error.textContent = e.message;
      error.hidden = false;
    }
  });
  list.addEventListener('click', async (event) => {
    const del = event.target.closest('[data-note-delete]');
    if (!del) return;
    const li = del.closest('[data-note]');
    try {
      await MT.api(`/api/notes/${li.dataset.note}`, { method: 'DELETE' });
      li.remove();
    } catch (e) {
      error.textContent = e.message;
      error.hidden = false;
    }
  });
});

// "Add to playlist" under a video: the member's playlists as checkboxes,
// ticking adds and unticking removes; a new playlist starts with this video.
const box = document.querySelector('[data-playlist-add]');

function ready(fn) {
  if (window.MT) fn(window.MT);
  else document.addEventListener('DOMContentLoaded', () => fn(window.MT));
}

ready((MT) => {
  if (!box || !MT) return;
  const videoId = box.dataset.playlistAdd;
  const list = box.querySelector('[data-playlist-choices]');
  const error = box.querySelector('[data-error]');
  const fail = (e) => { error.textContent = e.message; error.hidden = false; };
  const draw = (playlists) => {
    list.textContent = '';
    if (playlists.length === 0) {
      list.append(Object.assign(document.createElement('p'), { className: 'small muted', textContent: list.dataset.empty }));
    }
    list.dataset.loaded = '';
    for (const p of playlists) {
      const label = document.createElement('label');
      label.className = 'check';
      const tick = Object.assign(document.createElement('input'), { type: 'checkbox', checked: p.contains });
      tick.addEventListener('change', async () => {
        error.hidden = true;
        try {
          if (tick.checked) await MT.api(`/api/playlists/${p.id}/items`, { method: 'POST', body: { videoId } });
          else await MT.api(`/api/playlists/${p.id}/items?videoId=${encodeURIComponent(videoId)}`, { method: 'DELETE' });
        } catch (e) {
          tick.checked = !tick.checked;
          fail(e);
        }
      });
      label.append(tick, ` ${p.title}`);
      list.append(label);
    }
  };
  const load = () => MT.api(`/api/playlists/for-video?videoId=${encodeURIComponent(videoId)}`).then(draw).catch(fail);
  box.addEventListener('toggle', () => { if (box.open) load(); });
  box.querySelector('[data-playlist-new]').addEventListener('submit', async (event) => {
    event.preventDefault();
    const input = event.target.elements.namedItem('title');
    try {
      await MT.api('/api/playlists', { method: 'POST', body: { title: input.value, videoId } });
      input.value = '';
      load();
    } catch (e) {
      fail(e);
    }
  });
});

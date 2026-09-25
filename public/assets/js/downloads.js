// Videos saved to this device (lib/offline-downloads.ts). The file goes into
// Cache Storage under /offline-video/<id>.mp4 on our own origin, where the
// service worker answers it (ranges included) with no network; the list
// lives in localStorage under marine-downloads-index, which offline.html
// reads too. None of it ever reaches the server.
import MT from './mt.js';

export const DOWNLOAD_CACHE = 'marine-team-downloads-v1';
export const INDEX_KEY = 'marine-downloads-index';
export const DOWNLOADS_CHANGED_EVENT = 'marine-downloads-change';

export function cacheUrlFor(videoId) {
  return MT.url(`/offline-video/${encodeURIComponent(videoId)}.mp4`);
}

export function readIndex() {
  try {
    const items = JSON.parse(localStorage.getItem(INDEX_KEY) || '[]');
    return Array.isArray(items) ? items.filter((i) => i && i.videoId && i.cacheUrl) : [];
  } catch {
    return [];
  }
}

function writeIndex(items) {
  try {
    localStorage.setItem(INDEX_KEY, JSON.stringify(items));
  } catch { /* storage full or blocked: the file is still cached */ }
  window.dispatchEvent(new Event(DOWNLOADS_CHANGED_EVENT));
}

export function isStandalone() {
  return window.matchMedia?.('(display-mode: standalone)').matches || window.navigator.standalone === true;
}

export function isSaved(videoId) {
  return readIndex().some((i) => i.videoId === videoId);
}

/** On a metered connection with "Wi-Fi only" chosen, say so rather than spend the data. */
export function networkAllows() {
  const settings = MT.settings.read();
  const connection = navigator.connection;
  if (settings.downloadNetwork !== 'wifi' || !connection) return true;
  return !(connection.saveData || connection.type === 'cellular');
}

/**
 * Downloads one video to the device. Throws with a sentence to show when the
 * server or the network says no.
 */
export async function download(videoId, onProgress) {
  const info = await MT.api(`/api/downloads/${encodeURIComponent(videoId)}`);
  if (info.platform === 'PWA' && !isStandalone()) throw new Error('Downloads work in the installed app. Add this site to your home screen first.');
  if (info.platform === 'WEB' && isStandalone()) throw new Error('Downloads work in the browser, not the installed app.');
  if (!('caches' in window)) throw new Error('This browser can’t keep videos offline.');
  const response = await fetch(info.url, { credentials: info.url.startsWith(location.origin) ? 'same-origin' : 'omit' });
  if (!response.ok || !response.body) throw new Error(`The video file couldn’t be fetched (${response.status}).`);
  const total = Number(response.headers.get('content-length')) || 0;
  const reader = response.body.getReader();
  const chunks = [];
  let received = 0;
  for (;;) {
    const { done, value } = await reader.read();
    if (done) break;
    chunks.push(value);
    received += value.length;
    if (total) onProgress?.(received / total);
  }
  const blob = new Blob(chunks, { type: 'video/mp4' });
  const cacheUrl = cacheUrlFor(videoId);
  const cache = await caches.open(DOWNLOAD_CACHE);
  await cache.put(cacheUrl, new Response(blob, { headers: { 'Content-Type': 'video/mp4', 'Content-Length': String(blob.size), 'Accept-Ranges': 'bytes' } }));
  const entry = {
    videoId,
    title: info.title,
    seriesTitle: info.seriesTitle,
    durationSeconds: info.durationSeconds,
    bytes: blob.size,
    cacheUrl,
    savedAt: new Date().toISOString(),
  };
  writeIndex([...readIndex().filter((i) => i.videoId !== videoId), entry]);
  return entry;
}

export async function remove(videoId) {
  const entry = readIndex().find((i) => i.videoId === videoId);
  if (entry && 'caches' in window) {
    await (await caches.open(DOWNLOAD_CACHE)).delete(entry.cacheUrl);
  }
  writeIndex(readIndex().filter((i) => i.videoId !== videoId));
}

/** Browsers evict caches under pressure without telling anyone: drop entries whose file has gone. */
export async function heal() {
  if (!('caches' in window)) return readIndex();
  const cache = await caches.open(DOWNLOAD_CACHE);
  const kept = [];
  for (const item of readIndex()) {
    if (await cache.match(item.cacheUrl)) kept.push(item);
  }
  if (kept.length !== readIndex().length) writeIndex(kept);
  return kept;
}

function formatBytes(n) {
  if (!n) return '';
  const units = ['B', 'KB', 'MB', 'GB'];
  let i = 0;
  while (n >= 1024 && i < units.length - 1) { n /= 1024; i++; }
  return `${n.toFixed(i === 0 ? 0 : 1)} ${units[i]}`;
}

// The ⬇ button under a video: <button data-download-video="id">.
for (const button of document.querySelectorAll('[data-download-video]')) {
  const id = button.dataset.downloadVideo;
  const label = button.textContent;
  const status = document.querySelector('[data-download-status]');
  const refresh = () => {
    const saved = isSaved(id);
    button.textContent = saved ? button.dataset.savedLabel || 'Saved on this device' : label;
    button.disabled = saved;
  };
  refresh();
  window.addEventListener(DOWNLOADS_CHANGED_EVENT, refresh);
  button.addEventListener('click', async () => {
    if (!networkAllows() && !window.confirm('You’re not on Wi-Fi. Download anyway?')) return;
    button.disabled = true;
    try {
      await download(id, (p) => { button.textContent = `${Math.round(p * 100)}%`; });
      refresh();
    } catch (error) {
      if (status) { status.textContent = error.message; status.hidden = false; } else window.alert(error.message);
      button.textContent = label;
      button.disabled = false;
    }
  });
}

// /profile/downloads: what this device holds.
const list = document.querySelector('[data-downloads-list]');
if (list) {
  const empty = document.querySelector('[data-downloads-empty]');
  const usage = document.querySelector('[data-downloads-usage]');
  const draw = async () => {
    const items = await heal();
    list.textContent = '';
    if (empty) empty.hidden = items.length > 0;
    for (const item of items) {
      const li = document.createElement('li');
      li.className = 'file-item';
      const title = document.createElement('span');
      title.className = 'file-title';
      title.textContent = [item.title, item.seriesTitle].filter(Boolean).join(' — ');
      const size = document.createElement('span');
      size.className = 'small muted';
      size.textContent = formatBytes(item.bytes);
      const play = document.createElement('a');
      play.className = 'button small';
      play.href = item.cacheUrl;
      play.textContent = list.dataset.playLabel || 'Play offline';
      const drop = document.createElement('button');
      drop.type = 'button';
      drop.className = 'button small danger';
      drop.textContent = list.dataset.removeLabel || 'Remove';
      drop.addEventListener('click', async () => { await remove(item.videoId); draw(); });
      li.append(title, size, play, drop);
      list.append(li);
    }
    if (usage) {
      const used = items.reduce((sum, i) => sum + (i.bytes || 0), 0);
      let text = `${formatBytes(used) || '0 B'} of ${usage.dataset.capGb} GB suggested`;
      try {
        const estimate = await navigator.storage?.estimate?.();
        if (estimate?.quota) text += ` · this browser allows ${formatBytes(estimate.quota)}`;
      } catch { /* not reported */ }
      usage.textContent = text;
    }
  };
  draw();
  window.addEventListener(DOWNLOADS_CHANGED_EVENT, draw);
  const network = document.querySelector('[data-download-network]');
  if (network) {
    network.value = MT.settings.read().downloadNetwork;
    network.addEventListener('change', () => MT.settings.write({ downloadNetwork: network.value }));
  }
}

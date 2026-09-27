// A service's running order kept on this device (lib/offline-services.ts).
//
// The order goes into Cache Storage as JSON under /offline-service/<id>.json
// on our own origin, where the service worker answers for it with no network
// and offline.html reads it; the index is one localStorage entry that same
// page reads. It is its own cache, because an order is thrown away after the
// Sunday it is for and clearing one should not take the saved books with it.
//
// An order can change after it is saved — a hymn swapped on the Saturday — so
// the page asks the server for the fingerprint alone when it loads and offers
// to save it again when the two differ. Nothing is re-fetched to find out.
import MT from '../../../assets/js/mt.js';

export const SERVICE_CACHE = 'marine-team-services-v1';
export const INDEX_KEY = 'marine-offline-services';
export const SERVICES_CHANGED_EVENT = 'marine-offline-services-change';

export function serviceCacheUrl(id) {
  return MT.url(`/offline-service/${encodeURIComponent(id)}.json`);
}

export function readIndex() {
  try {
    const items = JSON.parse(localStorage.getItem(INDEX_KEY) || '[]');
    return Array.isArray(items) ? items.filter((item) => item && item.id && item.cacheUrl) : [];
  } catch {
    return [];
  }
}

function writeIndex(items) {
  try {
    localStorage.setItem(INDEX_KEY, JSON.stringify(items));
  } catch { /* storage blocked: the order is still cached */ }
  window.dispatchEvent(new Event(SERVICES_CHANGED_EVENT));
}

export function savedEntry(id) {
  return readIndex().find((item) => item.id === id) ?? null;
}

/** Saves the order, and returns the entry the offline shell will list. */
export async function saveService(id) {
  if (!('caches' in window)) throw new Error('This browser can’t keep a service order offline.');
  const payload = await MT.api(`/api/offline/service/${encodeURIComponent(id)}`);
  const cacheUrl = serviceCacheUrl(id);
  const cache = await caches.open(SERVICE_CACHE);
  await cache.put(cacheUrl, new Response(JSON.stringify(payload), { headers: { 'Content-Type': 'application/json' } }));
  const entry = {
    id,
    title: payload.title,
    // offline.html reads it under this name; the payload calls it date.
    serviceDate: payload.date,
    itemCount: Array.isArray(payload.items) ? payload.items.length : 0,
    cacheUrl,
    fingerprint: payload.fingerprint,
    savedAt: new Date().toISOString(),
  };
  writeIndex([...readIndex().filter((item) => item.id !== id), entry]);
  return entry;
}

/** Whether what is on the device is still the order the server would send. */
export async function serviceIsCurrent(id) {
  const entry = savedEntry(id);
  if (entry === null) return false;
  try {
    const probe = await MT.api(`/api/offline/service/${encodeURIComponent(id)}?probe=1`);
    return probe.fingerprint === entry.fingerprint;
  } catch {
    // No connection is not a reason to call a saved order out of date.
    return true;
  }
}

export async function forgetService(id) {
  const entry = savedEntry(id);
  if (entry !== null && 'caches' in window) {
    try {
      await (await caches.open(SERVICE_CACHE)).delete(entry.cacheUrl);
    } catch { /* already gone */ }
  }
  writeIndex(readIndex().filter((item) => item.id !== id));
}

// The button under a service's order: <button data-keep-service="id">.
const button = typeof document !== 'undefined' ? document.querySelector('[data-keep-service]') : null;
if (button) {
  const id = button.dataset.keepService;
  const status = document.querySelector('[data-keep-service-status]');
  const say = (message, isError = true) => {
    if (!status) return;
    status.textContent = message;
    status.classList.toggle('error', isError);
    status.hidden = message === '';
  };
  const draw = () => {
    const saved = savedEntry(id) !== null;
    button.textContent = saved ? button.dataset.removeLabel : button.dataset.keepLabel;
    button.classList.toggle('danger', saved);
  };
  draw();
  window.addEventListener(SERVICES_CHANGED_EVENT, draw);

  button.addEventListener('click', async () => {
    button.disabled = true;
    say('');
    try {
      if (savedEntry(id) === null) {
        await saveService(id);
        say(button.dataset.savedLabel, false);
      } else {
        await forgetService(id);
      }
      draw();
    } catch (error) {
      say(error.message);
    } finally {
      button.disabled = false;
    }
  });

  // A hymn swapped after it was saved: say so rather than let somebody stand
  // up on Sunday with last week's order.
  if (savedEntry(id) !== null) {
    serviceIsCurrent(id).then((current) => {
      if (!current) say(button.dataset.staleLabel);
    });
  }
}

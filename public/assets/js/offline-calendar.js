// The rota calendar on this device (lib/offline-calendar.ts). One file under
// /offline-calendar/snapshot.json in Cache Storage, where the service worker
// answers it with no network and the static offline shell reads it with no
// bundle; the index is one entry in localStorage under
// marine-offline-calendar, because there is only ever one calendar.
//
// It is the smallest thing this app can save — a few kilobytes of text
// against a hymnal's forty megabytes — and the only one that keeps itself
// current: once saved, opening the calendar with a connection quietly asks
// the server what has changed and folds it in, so nobody has to remember to
// press update to find out they are on for Sunday.
// No import: mergeSnapshot is pure, and is tested on its own in Node, where
// there is no window for mt.js to hang itself on. What this file needs of MT
// it reads lazily, off the page that loaded it.
const mt = () => window.MT;

export const CALENDAR_CACHE = 'marine-team-calendar-v1';
export const CALENDAR_PATH = '/offline-calendar/snapshot.json';
export const INDEX_KEY = 'marine-offline-calendar';
export const OFFLINE_CALENDAR_CHANGED_EVENT = 'marine-offline-calendar-change';

export function cacheUrl() {
  return mt().url(CALENDAR_PATH);
}

/** There is one calendar, so its index is one entry rather than a list. */
export function readIndex() {
  try {
    const entry = JSON.parse(localStorage.getItem(INDEX_KEY) || 'null');
    return entry && entry.cacheUrl ? entry : null;
  } catch {
    return null;
  }
}

function writeIndex(entry) {
  try {
    if (entry) localStorage.setItem(INDEX_KEY, JSON.stringify(entry));
    else localStorage.removeItem(INDEX_KEY);
  } catch { /* storage blocked: what is cached is still readable */ }
  window.dispatchEvent(new Event(OFFLINE_CALENDAR_CHANGED_EVENT));
}

/**
 * What the device keeps, after the server's answer is folded into it.
 *
 * Pure, and tested on its own, because it carries two rules a delta cannot
 * state — getting either wrong means somebody turning up when they
 * shouldn't:
 *
 *  - A schedule that was turned off takes its dates with it. Disabling a
 *    schedule touches no event row, so those dates are never reported as
 *    changed or deleted; they would otherwise sit on the phone for good.
 *  - Days that have fallen out behind the window are dropped, for the same
 *    reason: nothing deleted them, they simply stop being sent.
 */
export function mergeSnapshot(saved, delta) {
  if (!delta || typeof delta !== 'object') return saved || null;
  const schedules = Array.isArray(delta.schedules) ? delta.schedules : [];
  const live = new Set(schedules.map((schedule) => schedule.id));
  const from = typeof delta.from === 'string' ? delta.from : null;
  const to = typeof delta.to === 'string' ? delta.to : null;

  const byId = new Map();
  if (!delta.full && saved && Array.isArray(saved.events)) {
    saved.events.forEach((event) => byId.set(event.id, event));
  }
  (Array.isArray(delta.events) ? delta.events : []).forEach((event) => byId.set(event.id, event));
  (Array.isArray(delta.deleted) ? delta.deleted : []).forEach((id) => byId.delete(id));

  const events = [...byId.values()].filter((event) => {
    if (!live.has(event.scheduleId)) return false;
    // A multi-day event is kept until the day it ends.
    const ends = event.endDate || event.date;
    if (from && ends < from) return false;
    if (to && event.date > to) return false;
    return true;
  });
  events.sort((a, b) => (a.date === b.date ? (a.startTime || '') .localeCompare(b.startTime || '') : a.date < b.date ? -1 : 1));

  return {
    full: true,
    syncedAt: delta.syncedAt || (saved && saved.syncedAt) || null,
    from,
    to,
    // Sorted into place by the order an admin arranged, not appended.
    schedules: [...schedules].sort((a, b) => (a.displayOrder ?? 0) - (b.displayOrder ?? 0) || String(a.name).localeCompare(String(b.name))),
    people: Array.isArray(delta.people) ? delta.people : (!delta.full && saved && Array.isArray(saved.people) ? saved.people : []),
    events,
    namesWithheld: delta.namesWithheld === true,
  };
}

export function isSaved() {
  return readIndex() !== null;
}

async function readSaved() {
  const entry = readIndex();
  if (!entry || !('caches' in window)) return null;
  try {
    const cache = await caches.open(CALENDAR_CACHE);
    const response = await cache.match(entry.cacheUrl);
    return response ? await response.json() : null;
  } catch {
    return null;
  }
}

async function put(snapshot) {
  const url = cacheUrl();
  const cache = await caches.open(CALENDAR_CACHE);
  await cache.put(url, new Response(JSON.stringify(snapshot), { headers: { 'Content-Type': 'application/json' } }));
  writeIndex({ cacheUrl: url, syncedAt: snapshot.syncedAt, eventCount: snapshot.events.length });
  return snapshot;
}

/**
 * Save it, or bring it up to date. A snapshot the device already holds asks
 * only for what has changed since; anything else asks for the lot.
 */
export async function sync({ force = false } = {}) {
  if (!('caches' in window)) throw new Error('This browser can’t keep the calendar offline.');
  const saved = force ? null : await readSaved();
  const since = saved && saved.syncedAt && !force ? `?since=${encodeURIComponent(saved.syncedAt)}` : '';
  const delta = await mt().api(`/api/sync/snapshot${since}`);
  return put(mergeSnapshot(saved, delta));
}

export async function forget() {
  const entry = readIndex();
  if (entry && 'caches' in window) {
    try {
      const cache = await caches.open(CALENDAR_CACHE);
      await cache.delete(entry.cacheUrl);
    } catch { /* already gone */ }
  }
  writeIndex(null);
}

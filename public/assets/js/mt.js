// The small public API plugins and themes use in the browser:
//   MT.hooks     on/filter/do/apply, mirroring the PHP side
//   MT.api       fetch with the CSRF header and the base path applied
//   MT.settings  device settings read/write
// No framework, no bundler: a file edited over FTP is the file the browser gets.
import { readDeviceSettings, writeDeviceSettings } from './device-settings.js';

const actions = new Map();
const filters = new Map();

function add(table, name, fn, priority = 10) {
  const list = table.get(name) || [];
  list.push({ fn, priority, seq: list.length });
  list.sort((a, b) => a.priority - b.priority || a.seq - b.seq);
  table.set(name, list);
}

export const hooks = {
  on: (name, fn, priority) => add(actions, name, fn, priority),
  filter: (name, fn, priority) => add(filters, name, fn, priority),
  do(name, ...args) {
    for (const { fn } of actions.get(name) || []) {
      try { fn(...args); } catch (e) { console.error(`[MT] hook ${name} failed`, e); }
    }
  },
  apply(name, value, ...args) {
    for (const { fn } of filters.get(name) || []) {
      try { value = fn(value, ...args); } catch (e) { console.error(`[MT] filter ${name} failed`, e); }
    }
    return value;
  },
};

function meta(name) {
  const el = document.querySelector(`meta[name="${name}"]`);
  return el ? el.getAttribute('content') || '' : '';
}

export function basePath() {
  return meta('base-path').replace(/\/$/, '');
}

export function url(path) {
  if (/^https?:/i.test(path)) return path;
  return basePath() + '/' + String(path).replace(/^\//, '');
}

export async function api(path, options = {}) {
  const init = { credentials: 'same-origin', ...options, headers: { Accept: 'application/json', ...(options.headers || {}) } };
  const method = (init.method || 'GET').toUpperCase();
  if (method !== 'GET' && method !== 'HEAD') {
    init.headers['X-CSRF-Token'] = meta('csrf-token');
    if (init.body && typeof init.body === 'object' && !(init.body instanceof FormData) && !(init.body instanceof Blob) && !(init.body instanceof ArrayBuffer)) {
      init.headers['Content-Type'] = 'application/json';
      init.body = JSON.stringify(init.body);
    }
  }
  const response = await fetch(url(path), init);
  const type = response.headers.get('content-type') || '';
  const data = type.includes('application/json') ? await response.json().catch(() => null) : null;
  if (!response.ok) {
    const error = new Error((data && data.error) || `Request failed (${response.status})`);
    error.status = response.status;
    error.data = data;
    throw error;
  }
  return data;
}

export const settings = { read: readDeviceSettings, write: writeDeviceSettings };

const MT = { hooks, api, url, basePath, settings };
window.MT = window.MT || MT;
export default MT;

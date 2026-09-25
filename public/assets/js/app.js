// Core page behaviour, attached to server-rendered HTML through data-*
// attributes. Every page loads this and nothing else by default.
import MT from './mt.js';
import { readDeviceSettings, applyTheme, DEVICE_SETTINGS_EVENT, DEVICE_SETTINGS_KEY } from './device-settings.js';
import { toSnapshot, resolveTabs, NAV_TABS_SNAPSHOT_KEY, TABS_ACROSS } from './nav-tabs.js';
import './forms.js';

// Keep "System" following the OS while the page is open, and pick up a
// change made in another tab.
const media = window.matchMedia('(prefers-color-scheme: dark)');
const sync = () => applyTheme(readDeviceSettings());
media.addEventListener?.('change', sync);
window.addEventListener(DEVICE_SETTINGS_EVENT, sync);
window.addEventListener('storage', (e) => { if (e.key === DEVICE_SETTINGS_KEY) sync(); });

// The bottom bar: the server draws the suggested tabs; a choice made on this
// device (Profile → Settings → Bottom bar) redraws it from every destination
// this viewer may use, dropping any that has gone. It leaves a snapshot for
// the offline shell, which has no server to ask what the tabs are.
const tabbar = document.querySelector('[data-tabbar]');
const tabOptions = document.querySelector('template[data-tab-options]');
// The bar as the server drew it: what "use the suggested bar" goes back to.
const serverTabs = tabbar ? [...tabbar.children].map((a) => a.cloneNode(true)) : [];

export function drawTabs(settings = readDeviceSettings()) {
  if (!tabbar) return;
  let suggested = [];
  try {
    suggested = JSON.parse(tabbar.getAttribute('data-tabs') || '[]');
  } catch (e) {
    suggested = [];
  }
  const anchors = new Map();
  for (const a of tabOptions?.content.querySelectorAll('a[data-href]') || []) anchors.set(a.dataset.href, a);
  const options = [...anchors.keys()].map((href) => {
    const a = anchors.get(href);
    return { href, label: a.textContent.trim(), icon: a.dataset.icon || 'folder' };
  });
  const suggestedItems = suggested.map((t) => ({ ...t }));
  const tabs = resolveTabs(settings.bottomTabs, suggestedItems, options);
  if (settings.bottomTabs !== null && tabOptions) {
    tabbar.replaceChildren(...tabs.map((t) => anchors.get(t.href)?.cloneNode(true)).filter(Boolean));
  } else {
    tabbar.replaceChildren(...serverTabs.map((a) => a.cloneNode(true)));
  }
  tabbar.classList.toggle('scrolls', tabbar.children.length > TABS_ACROSS);
  try {
    const drawn = [...tabbar.querySelectorAll('a[data-href]')].map((a) => {
      const known = [...suggestedItems, ...options].find((t) => t.href === a.dataset.href);
      return { href: MT.url(a.dataset.href), label: a.querySelector('span')?.textContent || '', icon: known?.icon || 'folder' };
    });
    window.localStorage.setItem(NAV_TABS_SNAPSHOT_KEY, JSON.stringify(toSnapshot(drawn)));
  } catch (e) {
    // Storage blocked: the offline shell simply draws no bar.
  }
  tabbar.querySelector('a.active')?.scrollIntoView({ block: 'nearest', inline: 'nearest' });
}
drawTabs();
window.addEventListener(DEVICE_SETTINGS_EVENT, (e) => drawTabs(e.detail || readDeviceSettings()));

// Forms that ask before doing something irreversible.
document.addEventListener('submit', (event) => {
  const form = event.target;
  // (forms.js asks for its own data-api forms.)
  if (form instanceof HTMLFormElement && form.dataset.confirm && !form.dataset.api && !window.confirm(form.dataset.confirm)) {
    event.preventDefault();
  }
});

// The service worker: installability, push, and the offline shell.
if ('serviceWorker' in navigator && window.isSecureContext) {
  navigator.serviceWorker.register(MT.url('/sw.js'), { scope: MT.url('/') }).catch(() => {});
}

MT.hooks.do('ready', document);

// The view beacon: <body data-view-event='{"videoId":"…"}'> or on any element.
// The server throttles repeats; a failure here is never the reader's problem.
const viewed = document.querySelector('[data-view-event]');
if (viewed) {
  try {
    MT.api('/api/view-events', { method: 'POST', body: JSON.parse(viewed.dataset.viewEvent) }).catch(() => {});
  } catch { /* malformed attribute */ }
}

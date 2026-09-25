// Core page behaviour, attached to server-rendered HTML through data-*
// attributes. Every page loads this and nothing else by default.
import MT from './mt.js';
import { readDeviceSettings, applyTheme, DEVICE_SETTINGS_EVENT, DEVICE_SETTINGS_KEY } from './device-settings.js';
import { toSnapshot, NAV_TABS_SNAPSHOT_KEY } from './nav-tabs.js';

// Keep "System" following the OS while the page is open, and pick up a
// change made in another tab.
const media = window.matchMedia('(prefers-color-scheme: dark)');
const sync = () => applyTheme(readDeviceSettings());
media.addEventListener?.('change', sync);
window.addEventListener(DEVICE_SETTINGS_EVENT, sync);
window.addEventListener('storage', (e) => { if (e.key === DEVICE_SETTINGS_KEY) sync(); });

// The bottom bar leaves a snapshot for the offline shell, which has no server
// to ask what the tabs are.
const tabbar = document.querySelector('[data-tabbar]');
if (tabbar) {
  try {
    const tabs = JSON.parse(tabbar.getAttribute('data-tabs') || '[]');
    const snapshot = toSnapshot(tabs.map((t) => ({ ...t, href: MT.url(t.href) })));
    window.localStorage.setItem(NAV_TABS_SNAPSHOT_KEY, JSON.stringify(snapshot));
  } catch (e) {
    // Storage blocked: the offline shell simply draws no bar.
  }
  tabbar.querySelector('a.active')?.scrollIntoView({ block: 'nearest', inline: 'nearest' });
}

// Forms that ask before doing something irreversible.
document.addEventListener('submit', (event) => {
  const form = event.target;
  if (form instanceof HTMLFormElement && form.dataset.confirm && !window.confirm(form.dataset.confirm)) {
    event.preventDefault();
  }
});

// The service worker: installability, push, and the offline shell.
if ('serviceWorker' in navigator && window.isSecureContext) {
  navigator.serviceWorker.register(MT.url('/sw.js'), { scope: MT.url('/') }).catch(() => {});
}

MT.hooks.do('ready', document);

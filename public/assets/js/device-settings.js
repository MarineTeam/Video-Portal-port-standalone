// Device settings: how the site behaves on *this* device — theme, language,
// autoplay, playback speed, the bottom bar, reading sizes. Stored per device
// in localStorage under marine-device-settings (the same key and shape the
// original app and the offline shell use), deliberately not on the account.
//
// Pure where it can be: parseDeviceSettings is what the tests pin, and the
// offline shell (offline.html) keeps a hand-copied subset of these rules.

export const DEVICE_SETTINGS_KEY = 'marine-device-settings';
export const DEVICE_SETTINGS_EVENT = 'marine-device-settings-change';

// Kept here, not imported from the catalogues, so the module every page loads
// to read a preference doesn't pull two catalogues with it. A PHP test checks
// this list against app/Lang.
export const LANGUAGES = [{ "value": "en", "label": "English" }, { "value": "es", "label": "Español" }];

export const THEMES = ['system', 'light', 'dark'];
export const PLAYBACK_SPEEDS = [0.5, 0.75, 1, 1.25, 1.5, 1.75, 2];
export const DOWNLOAD_NETWORKS = ['wifi', 'any'];

export const MIN_READING_SCALE = 0.75;
export const MAX_READING_SCALE = 2;
export const MIN_PRESENT_SCALE = 0.5;
export const MAX_PRESENT_SCALE = 3;
export const MAX_TABS = 10;

export const DEFAULT_SETTINGS = Object.freeze({
  theme: 'system',
  language: null,
  autoplay: false,
  playbackSpeed: 1,
  downloadNetwork: 'wifi',
  keepScreenOn: true,
  swipePages: true,
  readingTextScale: 1,
  presentTextScale: 1,
  presentPalette: 'dark',
  bottomTabs: null,
  calendarPersonId: null,
});

function clamp(value, min, max) {
  return Math.min(max, Math.max(min, value));
}

function bool(value, fallback) {
  return typeof value === 'boolean' ? value : fallback;
}

// Reads what localStorage held into a complete settings object. Each field is
// checked on its own: one bad value falls back to its default without taking
// the rest with it. Flags are never coerced — "false" is not false.
export function parseDeviceSettings(raw) {
  let stored = null;
  if (typeof raw === 'string') {
    try {
      stored = JSON.parse(raw);
    } catch (e) {
      stored = null;
    }
  } else if (raw && typeof raw === 'object') {
    stored = raw;
  }
  const out = { ...DEFAULT_SETTINGS };
  if (!stored || typeof stored !== 'object' || Array.isArray(stored)) return out;

  if (THEMES.includes(stored.theme)) out.theme = stored.theme;
  if (LANGUAGES.some((l) => l.value === stored.language)) out.language = stored.language;
  out.autoplay = bool(stored.autoplay, out.autoplay);
  if (PLAYBACK_SPEEDS.includes(stored.playbackSpeed)) out.playbackSpeed = stored.playbackSpeed;
  if (DOWNLOAD_NETWORKS.includes(stored.downloadNetwork)) out.downloadNetwork = stored.downloadNetwork;
  // The screen stays on unless somebody deliberately turned it off.
  out.keepScreenOn = stored.keepScreenOn === false ? false : true;
  out.swipePages = stored.swipePages === false ? false : true;
  if (typeof stored.readingTextScale === 'number' && Number.isFinite(stored.readingTextScale)) {
    out.readingTextScale = clamp(stored.readingTextScale, MIN_READING_SCALE, MAX_READING_SCALE);
  }
  if (typeof stored.presentTextScale === 'number' && Number.isFinite(stored.presentTextScale)) {
    out.presentTextScale = clamp(stored.presentTextScale, MIN_PRESENT_SCALE, MAX_PRESENT_SCALE);
  }
  if (stored.presentPalette === 'light' || stored.presentPalette === 'dark') out.presentPalette = stored.presentPalette;
  // null means "never customised", which is different from an empty choice.
  if (Array.isArray(stored.bottomTabs)) {
    out.bottomTabs = stored.bottomTabs.filter((h) => typeof h === 'string' && h.startsWith('/')).slice(0, MAX_TABS);
  }
  if (typeof stored.calendarPersonId === 'string' && stored.calendarPersonId !== '') {
    out.calendarPersonId = stored.calendarPersonId;
  }
  return out;
}

export function readDeviceSettings() {
  try {
    return parseDeviceSettings(window.localStorage.getItem(DEVICE_SETTINGS_KEY));
  } catch (e) {
    return { ...DEFAULT_SETTINGS };
  }
}

// Merges a change into what is stored, so a page that only knows about one
// setting never resets the others.
export function writeDeviceSettings(patch) {
  const next = parseDeviceSettings({ ...readDeviceSettings(), ...patch });
  try {
    window.localStorage.setItem(DEVICE_SETTINGS_KEY, JSON.stringify(next));
  } catch (e) {
    // Private mode or blocked storage: the setting applies to this page only.
  }
  try {
    window.dispatchEvent(new CustomEvent(DEVICE_SETTINGS_EVENT, { detail: next }));
  } catch (e) {
    // Not in a browser.
  }
  return next;
}

export function resolvedTheme(settings, prefersDark) {
  return settings.theme === 'dark' || (settings.theme === 'system' && prefersDark) ? 'dark' : 'light';
}

export function applyTheme(settings) {
  const dark = resolvedTheme(settings, window.matchMedia('(prefers-color-scheme: dark)').matches);
  const root = document.documentElement;
  root.classList.toggle('dark', dark === 'dark');
  root.classList.toggle('light', dark !== 'dark');
}

// The blocking script the layout inlines before first paint, so the page never
// flashes the wrong theme. Same key as parseDeviceSettings, and it swallows its
// own errors: blocked localStorage must not halt the page.
export const THEME_INIT_SCRIPT =
  '(function(){try{var raw=localStorage.getItem("marine-device-settings");' +
  'var theme=raw?(JSON.parse(raw)||{}).theme:"system";' +
  'if(theme!=="light"&&theme!=="dark")theme="system";' +
  'var dark=theme==="dark"||(theme==="system"&&window.matchMedia("(prefers-color-scheme: dark)").matches);' +
  'document.documentElement.classList.add(dark?"dark":"light");}catch(e){}})();';

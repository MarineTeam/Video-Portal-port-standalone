// Profile pages: the device settings (stored in this browser only), the
// bottom-bar editor, and marking an inbox item read when it is opened.
import MT from './mt.js';
import { readDeviceSettings, writeDeviceSettings } from './device-settings.js';
import { parseTabHrefs, MAX_TABS } from './nav-tabs.js';

// This device ------------------------------------------------------------------

const device = document.querySelector('[data-device-settings]');
if (device) {
  const saved = device.querySelector('[data-device-saved]');
  const fill = () => {
    const settings = readDeviceSettings();
    for (const field of device.querySelectorAll('input, select')) {
      const name = field.name;
      if (!(name in settings) || name === 'language') continue;
      if (field.type === 'radio') field.checked = String(settings[name]) === field.value;
      else if (field.type === 'checkbox') field.checked = settings[name] === true;
      else field.value = String(settings[name]);
    }
  };
  fill();
  device.addEventListener('change', async (event) => {
    const field = event.target;
    if (!(field instanceof HTMLInputElement || field instanceof HTMLSelectElement) || !field.name) return;
    if (field.name === 'language') {
      writeDeviceSettings({ language: field.value });
      try {
        await MT.api('/api/locale', { method: 'POST', body: { locale: field.value } });
      } finally {
        window.location.reload();
      }
      return;
    }
    let value = field.value;
    if (field.type === 'checkbox') value = field.checked;
    else if (field.dataset.type === 'number') value = Number(field.value);
    writeDeviceSettings({ [field.name]: value });
    if (saved) {
      saved.hidden = false;
      window.clearTimeout(saved._t);
      saved._t = window.setTimeout(() => { saved.hidden = true; }, 2000);
    }
  });
}

// The bottom bar ---------------------------------------------------------------

const editor = document.querySelector('[data-tab-editor]');
if (editor) {
  const options = JSON.parse(editor.dataset.options || '[]');
  const suggested = JSON.parse(editor.dataset.suggested || '[]');
  const list = editor.querySelector('[data-tab-list]');
  const add = editor.querySelector('[data-tab-add]');
  const labelOf = (href) => options.find((o) => o.href === href)?.label || href;

  const current = () => {
    const stored = parseTabHrefs(readDeviceSettings().bottomTabs);
    const hrefs = stored === null ? suggested : stored;
    return hrefs.filter((h) => options.some((o) => o.href === h));
  };
  const save = (hrefs) => {
    writeDeviceSettings({ bottomTabs: hrefs });
    render();
  };
  const button = (text, label, onClick, disabled = false) => {
    const b = document.createElement('button');
    b.type = 'button';
    b.className = 'button small';
    b.textContent = text;
    b.setAttribute('aria-label', label);
    b.disabled = disabled;
    b.addEventListener('click', onClick);
    return b;
  };
  function render() {
    const hrefs = current();
    list.replaceChildren(...hrefs.map((href, i) => {
      const li = document.createElement('li');
      const name = document.createElement('span');
      name.textContent = labelOf(href);
      li.append(
        name,
        button('↑', `Move ${labelOf(href)} up`, () => { const next = [...hrefs]; [next[i - 1], next[i]] = [next[i], next[i - 1]]; save(next); }, i === 0),
        button('↓', `Move ${labelOf(href)} down`, () => { const next = [...hrefs]; [next[i + 1], next[i]] = [next[i], next[i + 1]]; save(next); }, i === hrefs.length - 1),
        // The bar keeps at least one destination: an installed app with none can go nowhere.
        button('×', `Remove ${labelOf(href)}`, () => save(hrefs.filter((h) => h !== href)), hrefs.length <= 1),
      );
      return li;
    }));
    const left = options.filter((o) => !hrefs.includes(o.href));
    const placeholder = document.createElement('option');
    placeholder.value = '';
    placeholder.textContent = '+';
    add.replaceChildren(placeholder, ...left.map((o) => {
      const opt = document.createElement('option');
      opt.value = o.href;
      opt.textContent = o.label;
      return opt;
    }));
    add.disabled = left.length === 0 || hrefs.length >= MAX_TABS;
  }
  add.addEventListener('change', () => {
    if (add.value) save([...current(), add.value]);
  });
  editor.querySelector('[data-tab-reset]')?.addEventListener('click', () => {
    writeDeviceSettings({ bottomTabs: null });
    render();
  });
  render();
}

// The inbox --------------------------------------------------------------------

for (const link of document.querySelectorAll('[data-inbox-open]')) {
  link.addEventListener('click', () => {
    // Fire and forget: the page is leaving; keepalive lets the request finish.
    MT.api('/api/inbox', { method: 'PATCH', body: { ids: [link.dataset.inboxOpen] }, keepalive: true }).catch(() => {});
  });
}

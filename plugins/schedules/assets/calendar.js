// The calendar page: the chip row, "Only mine", and keeping the whole year
// on this device. Whose calendar this is, is a per-device preference stored
// beside the theme, so it differs between somebody's phone and the church
// laptop — and the offline shell reads the same key, so choosing a name in
// one place settles it in both.
import { isSaved, sync, forget, readIndex } from '../../../assets/js/offline-calendar.js';

function ready(fn) {
  if (window.MT) fn(window.MT);
  else document.addEventListener('DOMContentLoaded', () => fn(window.MT));
}

ready((MT) => {
  const box = document.querySelector('[data-calendar]');
  if (!box || !MT) return;
  const labels = JSON.parse(box.dataset.labels || '{}');
  const signedIn = box.dataset.signedIn === '1';
  const chooser = box.querySelector('[data-calendar-person]');
  const onlyMine = box.querySelector('[data-calendar-mine]');
  let rota = '';

  if (chooser) {
    chooser.value = MT.settings.read().calendarPersonId || '';
    chooser.addEventListener('change', () => {
      MT.settings.write({ calendarPersonId: chooser.value || null });
      draw();
    });
  }
  onlyMine?.addEventListener('change', draw);
  box.querySelectorAll('[data-rota]').forEach((chip) => {
    chip.addEventListener('click', () => {
      rota = chip.dataset.rota;
      box.querySelectorAll('[data-rota]').forEach((other) => {
        const on = other === chip;
        other.classList.toggle('on', on);
        other.setAttribute('aria-pressed', on ? 'true' : 'false');
      });
      draw();
    });
  });

  /** Filtering is done here rather than by re-asking: the year is already on the page. */
  function draw() {
    const person = signedIn && onlyMine?.checked ? chooser?.value || '' : '';
    let shown = 0;
    box.querySelectorAll('[data-day]').forEach((day) => {
      let left = 0;
      day.querySelectorAll('[data-event]').forEach((item) => {
        const people = (item.dataset.people || '').split(',').filter(Boolean);
        const hide = (rota && item.dataset.rota !== rota) || (person && !people.includes(person));
        item.hidden = hide;
        if (!hide) left += 1;
      });
      day.hidden = left === 0;
      shown += left;
    });
    let empty = box.querySelector('[data-calendar-empty]');
    if (shown === 0 && !empty) {
      empty = document.createElement('p');
      empty.className = 'muted';
      empty.setAttribute('data-calendar-empty', '');
      empty.textContent = labels.nothing || '';
      box.querySelector('[data-calendar-days]')?.after(empty);
    } else if (empty) {
      empty.hidden = shown > 0;
    }
  }

  // Keeping it on the device.
  const keep = box.querySelector('[data-calendar-keep]');
  const savedAt = box.querySelector('[data-calendar-saved]');
  if (keep && 'caches' in window) {
    keep.hidden = false;
    const show = () => {
      const entry = readIndex();
      keep.textContent = entry ? keep.dataset.removeLabel || labels.removeConfirm : keep.dataset.saveLabel || keep.textContent;
      if (savedAt) {
        savedAt.hidden = !entry;
        savedAt.textContent = entry && entry.eventCount ? String(entry.eventCount) : '';
      }
    };
    keep.dataset.saveLabel = keep.textContent;
    keep.dataset.removeLabel = labels.removeConfirm || keep.textContent;
    show();
    keep.addEventListener('click', async () => {
      keep.disabled = true;
      try {
        if (isSaved()) await forget();
        else await sync();
      } catch (e) {
        if (savedAt) { savedAt.hidden = false; savedAt.textContent = e.message; }
      }
      keep.disabled = false;
      show();
    });
    // Once saved, catching up is quiet and automatic.
    if (isSaved() && navigator.onLine !== false) sync().then(show).catch(() => {});
  }

  draw();
});

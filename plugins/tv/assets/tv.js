// The television screen: it asks for a code, polls until somebody approves
// it, and moves focus with the four arrows.
import { move, keyOf, firstFocusable } from './tv-nav.js';
// The television's page carries none of the app's chrome, and so none of its
// scripts: what this needs of MT it brings itself.
import MT from '../../../assets/js/mt.js';

function ready(fn) {
  if (document.readyState !== 'loading') fn(MT);
  else document.addEventListener('DOMContentLoaded', () => fn(MT));
}

ready((MT) => {
  if (!MT) return;

  // -- Signing this television in ---------------------------------------
  const pair = document.querySelector('[data-tv-pair]');
  if (pair) {
    const labels = JSON.parse(pair.dataset.labels || '{}');
    const codeBox = pair.querySelector('[data-tv-code]');
    const status = pair.querySelector('[data-tv-status]');
    const say = (text) => { if (status) status.textContent = text; };

    (async function signIn() {
      let started;
      try {
        started = await MT.api('/api/tv/pair', {
          method: 'POST',
          body: { deviceName: deviceName(), deviceKind: 'tv' },
        });
      } catch (e) {
        say(e.message);
        return;
      }
      if (codeBox) codeBox.textContent = started.formattedUserCode;
      const every = Math.max(2, started.interval || 5) * 1000;
      const until = Date.now() + (started.expiresIn || 900) * 1000;

      const ask = async () => {
        if (Date.now() > until) {
          say(labels.expired || '');
          // A code nobody used is replaced rather than left dead on a
          // screen somebody walks up to twenty minutes later.
          signIn();
          return;
        }
        let answer;
        try {
          answer = await MT.api('/api/tv/poll', { method: 'POST', body: { deviceCode: started.deviceCode } });
        } catch (e) {
          window.setTimeout(ask, every * 2);
          return;
        }
        if (answer.status === 'READY') {
          window.location.href = answer.redirect || MT.url('/tv');
          return;
        }
        if (answer.status === 'REFUSED') {
          say(labels.denied || '');
          return;
        }
        if (answer.status === 'EXPIRED' || answer.status === 'GONE') {
          signIn();
          return;
        }
        window.setTimeout(ask, Math.max(every, (answer.interval || 5) * 1000));
      };
      window.setTimeout(ask, every);
    })();
  }

  /**
   * What this set calls itself, for the approval screen. The platform's own
   * name where it gives one; never anything identifying beyond that.
   */
  function deviceName() {
    const agent = navigator.userAgent || '';
    for (const [pattern, name] of [
      [/Tizen/i, 'Samsung TV'],
      [/Web0?S|LG Browser/i, 'LG TV'],
      [/BRAVIA|SonyCEBrowser/i, 'Sony TV'],
      [/AFT[A-Z]/i, 'Fire TV'],
      [/CrKey|Chromecast/i, 'Chromecast'],
      [/AppleTV/i, 'Apple TV'],
      [/Android TV|GoogleTV/i, 'Android TV'],
      [/Roku/i, 'Roku'],
    ]) {
      if (pattern.test(agent)) return name;
    }
    return 'A television';
  }

  // -- Moving about with a remote ----------------------------------------
  // Back is a real button on every remote and belongs on every screen, grid
  // or no grid: a set with no pointer and no visible browser chrome has no
  // other way out of a video.
  const home = new URL(MT.url('/tv'), window.location.origin).pathname;
  document.addEventListener('keydown', (event) => {
    if (keyOf(event) !== 'back') return;
    if (window.location.pathname.replace(/\/$/, '') === home.replace(/\/$/, '')) return;
    event.preventDefault();
    if (window.history.length > 1) window.history.back();
    else window.location.href = MT.url('/tv');
  });

  const grid = document.querySelector('[data-tv-grid]');
  if (!grid) return;
  const rows = [...grid.querySelectorAll('[data-tv-row]')].map((row) => [...row.querySelectorAll('[data-tv-tile]')]);
  const lengths = rows.map((row) => row.length);
  let at = firstFocusable(lengths);
  let memory = 0;

  function show() {
    if (!at) return;
    const tile = rows[at.row]?.[at.column];
    if (!tile) return;
    tile.focus({ preventScroll: true });
    // Keep the tile and its row title in view, nudged rather than centred:
    // a jump is disorientating when you cannot see the pointer.
    tile.scrollIntoView({ block: 'nearest', inline: 'nearest', behavior: 'smooth' });
  }

  document.addEventListener('keydown', (event) => {
    const key = keyOf(event);
    if (key === null || at === null) return;
    if (key === 'ok') {
      rows[at.row]?.[at.column]?.click();
      event.preventDefault();
      return;
    }
    const next = move(lengths, at, key, memory);
    if (next === null) return;
    at = { row: next.row, column: next.column };
    memory = next.memory ?? next.column;
    show();
    event.preventDefault();
  });

  // A tile reached some other way (a pointer, a touch) keeps the grid honest.
  rows.forEach((row, rowIndex) => {
    row.forEach((tile, column) => {
      tile.addEventListener('focus', () => {
        at = { row: rowIndex, column };
        memory = column;
      });
    });
  });

  show();
});

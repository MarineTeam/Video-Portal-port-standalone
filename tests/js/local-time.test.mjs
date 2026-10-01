/**
 * Turning the server's UTC into the reader's own zone.
 *
 * The rules that matter are the two refusals: a date with no time in it is
 * a day and must not be moved into a zone, and anything unreadable is left
 * exactly as the server wrote it rather than blanked.
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { localise, SELECTOR } from '../../public/assets/js/local-time.js';

/** Enough of an element for the pure part of this. */
function timeEl(datetime, kind = 'localDate', text = 'as the server wrote it') {
  const attrs = new Map();
  if (datetime !== null) attrs.set('datetime', datetime);
  return {
    textContent: text,
    dataset: { [kind]: '' },
    getAttribute: (n) => (attrs.has(n) ? attrs.get(n) : null),
    setAttribute: (n, v) => attrs.set(n, v),
    hasAttribute: (n) => attrs.has(n),
  };
}

test('an instant becomes a date in the reader’s zone', () => {
  const el = timeEl('2026-07-04T17:06:00.000Z');
  assert.equal(localise(el), true);
  assert.notEqual(el.textContent, 'as the server wrote it');
  assert.equal(el.hasAttribute('data-localised'), true);
});

test('an instant with data-local-time gets the time too', () => {
  const el = timeEl('2026-07-04T17:06:00.000Z', 'localTime');
  localise(el);
  // Whatever the runner's zone and locale, a time of day is in there.
  assert.match(el.textContent, /\d{1,2}[:.]\d{2}/);
});

test('a day is not an instant, so it is left alone', () => {
  // "2026-10-04" through a zone west of UTC would become the 3rd, and move
  // a service to the wrong day.
  for (const day of ['2026-10-04', ' 2026-01-01 ']) {
    const el = timeEl(day);
    assert.equal(localise(el), false, day);
    assert.equal(el.textContent, 'as the server wrote it', day);
    assert.equal(el.hasAttribute('data-localised'), false, day);
  }
});

test('what cannot be read is left as the server wrote it, not blanked', () => {
  for (const bad of [null, '', 'soon', 'not a date', '2026-13-45T99:99:99Z']) {
    const el = timeEl(bad);
    assert.equal(localise(el), false, String(bad));
    assert.equal(el.textContent, 'as the server wrote it', String(bad));
  }
});

test('converting twice does not reformat what was already converted', () => {
  const el = timeEl('2026-07-04T17:06:00.000Z');
  localise(el);
  const once = el.textContent;
  assert.equal(localise(el), false, 'the second pass finds nothing to do');
  assert.equal(el.textContent, once);
});

test('the selector matches both kinds and nothing else', () => {
  assert.equal(SELECTOR, 'time[data-local-time], time[data-local-date]');
});

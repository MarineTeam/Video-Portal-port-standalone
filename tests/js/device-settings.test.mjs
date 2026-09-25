// lib/device-settings.test.ts, against the browser module itself.
// Run with: node --test tests/js
import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import {
  parseDeviceSettings, DEFAULT_SETTINGS, THEME_INIT_SCRIPT, DEVICE_SETTINGS_KEY,
  MIN_READING_SCALE, MAX_READING_SCALE, MAX_PRESENT_SCALE,
} from '../../public/assets/js/device-settings.js';

describe('parseDeviceSettings', () => {
  test('falls back to the defaults for missing or unparseable storage', () => {
    assert.deepEqual(parseDeviceSettings(null), DEFAULT_SETTINGS);
    assert.deepEqual(parseDeviceSettings('{not json'), DEFAULT_SETTINGS);
    assert.deepEqual(parseDeviceSettings('[1,2]'), DEFAULT_SETTINGS);
  });

  test('reads a full, valid settings object back', () => {
    const full = {
      theme: 'dark', language: 'es', autoplay: true, playbackSpeed: 1.5, downloadNetwork: 'any',
      keepScreenOn: false, swipePages: false, readingTextScale: 1.2, presentTextScale: 2,
      presentPalette: 'light', bottomTabs: ['/', '/search'], calendarPersonId: 'p1',
    };
    assert.deepEqual(parseDeviceSettings(JSON.stringify(full)), full);
  });

  test('keeps the fields it recognizes when others are missing', () => {
    const s = parseDeviceSettings(JSON.stringify({ theme: 'light' }));
    assert.equal(s.theme, 'light');
    assert.equal(s.playbackSpeed, 1);
  });

  test("rejects a theme or language it doesn't know, per field", () => {
    const s = parseDeviceSettings(JSON.stringify({ theme: 'sepia', language: 'fr', autoplay: true }));
    assert.equal(s.theme, 'system');
    assert.equal(s.language, null);
    assert.equal(s.autoplay, true);
  });

  test("rejects a playback speed that isn't one we offer", () => {
    assert.equal(parseDeviceSettings(JSON.stringify({ playbackSpeed: 3 })).playbackSpeed, 1);
  });

  test('rejects non-boolean flags rather than coercing them', () => {
    assert.equal(parseDeviceSettings(JSON.stringify({ autoplay: 'true' })).autoplay, false);
  });

  test('holds the screen on unless it was deliberately turned off', () => {
    assert.equal(parseDeviceSettings(JSON.stringify({ keepScreenOn: 'no' })).keepScreenOn, true);
    assert.equal(parseDeviceSettings(JSON.stringify({ keepScreenOn: false })).keepScreenOn, false);
  });

  test('pulls a present-mode text size back into range rather than resetting it', () => {
    assert.equal(parseDeviceSettings(JSON.stringify({ presentTextScale: 99 })).presentTextScale, MAX_PRESENT_SCALE);
  });

  test('does the same for the reading text size, which has its own range', () => {
    assert.equal(parseDeviceSettings(JSON.stringify({ readingTextScale: 0.1 })).readingTextScale, MIN_READING_SCALE);
    assert.equal(parseDeviceSettings(JSON.stringify({ readingTextScale: 9 })).readingTextScale, MAX_READING_SCALE);
  });

  test('keeps the reading size and the present size apart', () => {
    const s = parseDeviceSettings(JSON.stringify({ readingTextScale: 1.5 }));
    assert.equal(s.readingTextScale, 1.5);
    assert.equal(s.presentTextScale, 1);
  });

  test('treats a bottom bar that was never customised as unset', () => {
    assert.equal(parseDeviceSettings('{}').bottomTabs, null);
    assert.deepEqual(parseDeviceSettings(JSON.stringify({ bottomTabs: [] })).bottomTabs, []);
  });

  test('keeps page swiping on unless it was deliberately turned off', () => {
    assert.equal(parseDeviceSettings('{}').swipePages, true);
    assert.equal(parseDeviceSettings(JSON.stringify({ swipePages: false })).swipePages, false);
  });
});

describe('THEME_INIT_SCRIPT', () => {
  function run(stored, prefersDark, throwOnGet = false) {
    const classes = [];
    const context = {
      localStorage: { getItem: (k) => { if (throwOnGet) throw new Error('blocked'); return k === DEVICE_SETTINGS_KEY ? stored : null; } },
      window: { matchMedia: () => ({ matches: prefersDark }) },
      document: { documentElement: { classList: { add: (c) => classes.push(c) } } },
    };
    vm.runInNewContext(THEME_INIT_SCRIPT, context);
    return classes;
  }

  test('reads the same storage key parseDeviceSettings writes', () => {
    assert.ok(THEME_INIT_SCRIPT.includes(`"${DEVICE_SETTINGS_KEY}"`));
    assert.deepEqual(run(JSON.stringify({ theme: 'dark' }), false), ['dark']);
  });

  test('stamps one of the two classes the stylesheet keys off', () => {
    assert.deepEqual(run(null, true), ['dark']);
    assert.deepEqual(run(null, false), ['light']);
    assert.deepEqual(run(JSON.stringify({ theme: 'weird' }), false), ['light']);
  });

  test("swallows its own errors, so a blocked localStorage can't halt the page", () => {
    assert.doesNotThrow(() => run(null, false, true));
  });
});

// lib/nav-tabs.test.ts
import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { parseTabHrefs, resolveTabs, toSnapshot, MAX_TABS, TABS_ACROSS } from '../../public/assets/js/nav-tabs.js';

const home = { href: '/', label: 'Home', icon: 'home' };
const search = { href: '/search', label: 'Search', icon: 'search' };
const profile = { href: '/profile', label: 'Profile', icon: 'person', badge: 3 };
const admin = { href: '/admin', label: 'Admin', icon: 'shield' };
const options = [home, search, profile, admin];
const suggested = [home, search, profile];

describe('parseTabHrefs', () => {
  test('reads a stored choice back', () => {
    assert.deepEqual(parseTabHrefs(['/profile', '/']), ['/profile', '/']);
  });
  test('tells no choice from an empty one', () => {
    assert.equal(parseTabHrefs(null), null);
    assert.equal(parseTabHrefs(undefined), null);
    assert.equal(parseTabHrefs('nope'), null);
    assert.deepEqual(parseTabHrefs([]), []);
  });
  test("drops entries that aren't hrefs, and repeats", () => {
    assert.deepEqual(parseTabHrefs(['/', 7, 'https://evil.example', null, '/', '/search']), ['/', '/search']);
  });
  test('keeps more than fit across the screen — those scroll — but not without limit', () => {
    const many = Array.from({ length: 30 }, (_, i) => `/c/${i}`);
    const parsed = parseTabHrefs(many);
    assert.ok(parsed.length > TABS_ACROSS);
    assert.equal(parsed.length, MAX_TABS);
  });
});

describe('resolveTabs', () => {
  test("uses the app's suggestion when nothing was chosen", () => {
    assert.deepEqual(resolveTabs(null, suggested, options), suggested);
  });
  test("keeps the chosen order, not the catalogue's", () => {
    assert.deepEqual(resolveTabs(['/admin', '/'], suggested, options).map((t) => t.href), ['/admin', '/']);
  });
  test('carries the whole item through, badge included', () => {
    assert.deepEqual(resolveTabs(['/profile'], suggested, options), [profile]);
  });
  test('drops a destination this viewer no longer has', () => {
    assert.deepEqual(resolveTabs(['/admin', '/gone', '/'], suggested, [home, search, profile]).map((t) => t.href), ['/']);
  });
  test('falls back rather than leaving an installed app with no navigation', () => {
    assert.deepEqual(resolveTabs([], suggested, options), suggested);
    assert.deepEqual(resolveTabs(['/gone'], suggested, options), suggested);
  });
});

describe('toSnapshot', () => {
  test('keeps only what the offline shell can draw', () => {
    assert.deepEqual(toSnapshot([{ ...profile, extra: 'x' }]), [{ href: '/profile', label: 'Profile', icon: 'person' }]);
  });
});

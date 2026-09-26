// lib/offline-calendar.test.ts
import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { mergeSnapshot } from '../../public/assets/js/offline-calendar.js';

const breakbread = { id: 'sch1', name: 'Breakbread', displayOrder: 0 };
const welcome = { id: 'sch2', name: 'Welcome', displayOrder: 1 };

function event(id, date, extra = {}) {
  return { id, scheduleId: 'sch1', date, endDate: null, startTime: null, title: null, notes: null, location: null, status: 'CONFIRMED', people: [], ...extra };
}

function delta(fields = {}) {
  return {
    full: false,
    syncedAt: '2026-03-02T05:30:00Z',
    from: '2026-02-01',
    to: '2026-12-31',
    schedules: [breakbread, welcome],
    people: [{ id: 'p1', displayName: 'Devin' }],
    events: [],
    deleted: [],
    ...fields,
  };
}

function saved(events, fields = {}) {
  return { full: true, syncedAt: '2026-03-01T05:30:00Z', from: '2026-02-01', to: '2026-12-31', schedules: [breakbread, welcome], people: [{ id: 'p1', displayName: 'Devin' }], events, ...fields };
}

describe('mergeSnapshot', () => {
  test('replaces everything when the server says the snapshot is full', () => {
    const was = saved([event('a', '2026-03-08')]);
    const now = mergeSnapshot(was, delta({ full: true, events: [event('b', '2026-03-15')] }));
    assert.deepEqual(now.events.map((e) => e.id), ['b']);
  });

  test('takes the delta whole when the device holds nothing yet', () => {
    const now = mergeSnapshot(null, delta({ events: [event('a', '2026-03-08')] }));
    assert.deepEqual(now.events.map((e) => e.id), ['a']);
    assert.deepEqual(now.people.map((p) => p.id), ['p1']);
  });

  test('updates an event in place rather than duplicating it', () => {
    const was = saved([event('a', '2026-03-08', { title: 'Old' })]);
    const now = mergeSnapshot(was, delta({ events: [event('a', '2026-03-08', { title: 'New' })] }));
    assert.equal(now.events.length, 1);
    assert.equal(now.events[0].title, 'New');
  });

  test('drops what the server reports as deleted', () => {
    const was = saved([event('a', '2026-03-08'), event('b', '2026-03-15')]);
    const now = mergeSnapshot(was, delta({ deleted: ['a'] }));
    assert.deepEqual(now.events.map((e) => e.id), ['b']);
  });

  test('drops a withdrawn schedule’s events, which the server never lists', () => {
    // Disabling a schedule touches no event row, so nothing is reported as
    // changed or deleted; it simply stops being one of the schedules.
    const was = saved([event('a', '2026-03-08'), event('b', '2026-03-15', { scheduleId: 'sch2' })]);
    const now = mergeSnapshot(was, delta({ schedules: [breakbread] }));
    assert.deepEqual(now.events.map((e) => e.id), ['a']);
  });

  test('prunes days that have fallen out behind the window', () => {
    const was = saved([event('old', '2026-01-04'), event('a', '2026-03-08')]);
    const now = mergeSnapshot(was, delta());
    assert.deepEqual(now.events.map((e) => e.id), ['a']);
  });

  test('keeps a multi-day event until the day it ends', () => {
    const was = saved([event('camp', '2026-01-28', { endDate: '2026-02-03' })]);
    const now = mergeSnapshot(was, delta());
    assert.deepEqual(now.events.map((e) => e.id), ['camp']);
  });

  test('prunes days beyond the far edge too', () => {
    const was = saved([event('far', '2027-06-01'), event('a', '2026-03-08')]);
    const now = mergeSnapshot(was, delta());
    assert.deepEqual(now.events.map((e) => e.id), ['a']);
  });

  test('sorts a new schedule into its place rather than appending it', () => {
    const sound = { id: 'sch0', name: 'Sound', displayOrder: -1 };
    const now = mergeSnapshot(saved([]), delta({ schedules: [breakbread, welcome, sound] }));
    assert.deepEqual(now.schedules.map((s) => s.id), ['sch0', 'sch1', 'sch2']);
  });

  test('keeps the names it was given, and says when there are none', () => {
    const now = mergeSnapshot(saved([event('a', '2026-03-08', { people: [{ personId: 'p1', displayName: 'Devin' }] })]), delta({ full: true, people: [], events: [event('a', '2026-03-08')], namesWithheld: true }));
    assert.deepEqual(now.people, []);
    assert.deepEqual(now.events[0].people, []);
    assert.equal(now.namesWithheld, true);
  });

  test('is unmoved by a server answer that is not one', () => {
    const was = saved([event('a', '2026-03-08')]);
    assert.equal(mergeSnapshot(was, null), was);
  });

  test('orders a day’s events by the time they start', () => {
    const now = mergeSnapshot(null, delta({
      full: true,
      events: [event('late', '2026-03-08', { startTime: '18:30' }), event('early', '2026-03-08', { startTime: '10:00' })],
    }));
    assert.deepEqual(now.events.map((e) => e.id), ['early', 'late']);
  });
});

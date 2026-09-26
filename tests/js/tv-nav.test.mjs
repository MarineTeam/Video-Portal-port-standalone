// lib/tv-nav.test.ts
import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { move, keyOf, isValid, firstFocusable } from '../../plugins/tv/assets/tv-nav.js';

// Three rows: a long one, a short one, and a long one again.
const rows = [5, 2, 5];

function at(row, column, memory = null) {
  return { row, column, memory };
}

describe('move', () => {
  test('walks along a row', () => {
    assert.deepEqual(move(rows, at(0, 1), 'right'), { row: 0, column: 2, memory: 2 });
    assert.deepEqual(move(rows, at(0, 2), 'left'), { row: 0, column: 1, memory: 1 });
  });

  test('stops at the end of a row rather than wrapping', () => {
    assert.deepEqual(move(rows, at(0, 4), 'right'), { row: 0, column: 4, memory: 4 });
    assert.deepEqual(move(rows, at(0, 0), 'left'), { row: 0, column: 0, memory: 0 });
  });

  test('stops at the top and the bottom', () => {
    assert.equal(move(rows, at(0, 1), 'up').row, 0);
    assert.equal(move(rows, at(2, 1), 'down').row, 2);
  });

  test('keeps your column when it can, moving between rows', () => {
    assert.deepEqual(move([5, 5], at(0, 3), 'down'), { row: 1, column: 3, memory: 3 });
  });

  test('lands on the last item when the row below is shorter, not the first', () => {
    assert.deepEqual(move(rows, at(0, 4), 'down'), { row: 1, column: 1, memory: 4 });
  });

  test('goes back out to the column you came from, where the row is long enough', () => {
    const down = move(rows, at(0, 4), 'down');
    const further = move(rows, { row: down.row, column: down.column }, 'down', down.memory);
    assert.deepEqual(further, { row: 2, column: 4, memory: 4 });
  });

  test('skips an empty row rather than letting focus vanish into it', () => {
    assert.deepEqual(move([3, 0, 3], at(0, 1), 'down'), { row: 2, column: 1, memory: 1 });
  });

  test('has somewhere to be even with nothing on screen', () => {
    assert.equal(move([], at(0, 0), 'down'), null);
    assert.equal(move([0, 0], at(0, 0), 'down'), null);
    // And it recovers from a position that has gone stale under it.
    assert.deepEqual(move([4], at(9, 9), 'left'), { row: 0, column: 0 });
  });
});

describe('the remote’s keys', () => {
  test('reads the four arrows', () => {
    assert.equal(keyOf({ key: 'ArrowLeft' }), 'left');
    assert.equal(keyOf({ key: 'ArrowUp' }), 'up');
    assert.equal(keyOf({ key: 'ArrowRight' }), 'right');
    assert.equal(keyOf({ key: 'ArrowDown' }), 'down');
    assert.equal(keyOf({ keyCode: 40 }), 'down');
  });

  test('takes OK however the platform spells it', () => {
    for (const event of [{ key: 'Enter' }, { key: 'Select' }, { key: 'OK' }, { keyCode: 13 }]) {
      assert.equal(keyOf(event), 'ok');
    }
  });

  test('takes Back however the platform spells it', () => {
    for (const event of [{ key: 'Backspace' }, { key: 'Escape' }, { key: 'GoBack' }, { key: 'XF86Back' }, { keyCode: 461 }, { keyCode: 10009 }]) {
      assert.equal(keyOf(event), 'back');
    }
    assert.equal(keyOf({ key: 'a' }), null);
  });
});

describe('isValid', () => {
  test('knows what is inside the grid', () => {
    assert.equal(isValid(rows, at(1, 1)), true);
    assert.equal(isValid(rows, at(1, 2)), false);
    assert.equal(isValid(rows, at(3, 0)), false);
    assert.equal(isValid(rows, at(-1, 0)), false);
    assert.equal(isValid(rows, null), false);
  });
});

describe('firstFocusable', () => {
  test('finds the first row with anything in it', () => {
    assert.deepEqual(firstFocusable([0, 0, 2]), { row: 2, column: 0 });
    assert.equal(firstFocusable([0, 0]), null);
    assert.equal(firstFocusable([]), null);
  });
});

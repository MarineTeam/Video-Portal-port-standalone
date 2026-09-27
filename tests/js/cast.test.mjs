// cast-button.tsx, against the browser module itself.
// Run with: node --test tests/js
import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { canCast, castSource, castError } from '../../public/assets/js/cast.js';

describe('canCast', () => {
  test('wants a media element with the Remote Playback API on it', () => {
    assert.equal(canCast({ remote: { prompt: () => {} } }), true);
  });

  test('is false for a browser without it, so no dead button is drawn', () => {
    assert.equal(canCast(null), false);
    assert.equal(canCast({}), false);
    assert.equal(canCast({ remote: {} }), false);
    assert.equal(canCast({ remote: { prompt: 'not a function' } }), false);
  });
});

describe('castSource', () => {
  test("casts the page's own player when there is one: it is already where the member is", () => {
    assert.deepEqual(castSource({ tagName: 'VIDEO' }), { kind: 'element' });
  });

  test('falls back to the download MP4 when the player is somebody else’s iframe', () => {
    assert.deepEqual(castSource(null), { kind: 'mp4' });
  });
});

describe('castError', () => {
  test('says nothing when the member simply closed the picker', () => {
    assert.equal(castError({ name: 'NotAllowedError', message: 'no' }), null);
    assert.equal(castError({ name: 'AbortError', message: 'no' }), null);
    assert.equal(castError(undefined), null);
  });

  test('passes on the reason the server or the browser gave', () => {
    assert.equal(castError(new Error('This video can’t be downloaded.')), 'This video can’t be downloaded.');
  });

  test('has something to say even for an error with no message', () => {
    assert.equal(castError({ name: 'NotFoundError', message: '' }), 'No television answered.');
  });
});

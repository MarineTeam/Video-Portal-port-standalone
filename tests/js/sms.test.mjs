// lib/sms.test.ts — the browser half, against the same cases as
// tests/Unit/Support/SmsTest.php, because the cost the composer shows must
// be the cost that goes out.
import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { normalizePhone, smsSegments } from '../../public/assets/js/sms.js';

describe('normalizePhone', () => {
  test('keeps a number that is already international', () => {
    assert.equal(normalizePhone('+1 (415) 555-0132'), '+14155550132');
    assert.equal(normalizePhone('+44 7700 900123', '1'), '+447700900123');
  });
  test('reads 00 as the other way of writing +', () => {
    assert.equal(normalizePhone('0044 7700 900123'), '+447700900123');
  });
  test('adds a country code to a national number and drops the trunk zero', () => {
    assert.equal(normalizePhone('07700 900123', '44'), '+447700900123');
    assert.equal(normalizePhone('07700900123', '+44'), '+447700900123');
  });
  test('refuses a national number with no country code to add rather than guessing', () => {
    assert.equal(normalizePhone('07700 900123'), null);
    assert.equal(normalizePhone('07700 900123', ''), null);
  });
  test('refuses something that is not a phone number', () => {
    assert.equal(normalizePhone('ring the office'), null);
    assert.equal(normalizePhone(''), null);
    assert.equal(normalizePhone(null), null);
    assert.equal(normalizePhone('12345'), null);
  });
  test('refuses one longer than any real number', () => {
    assert.equal(normalizePhone('+1234567890123456'), null);
  });
});

describe('smsSegments', () => {
  test('fits 160 plain characters in one message', () => {
    const counted = smsSegments('a'.repeat(160));
    assert.equal(counted.messages, 1);
    assert.equal(counted.encoding, 'GSM-7');
    assert.equal(counted.remaining, 0);
  });
  test('spills into two at 161, which are 153 each once split', () => {
    const counted = smsSegments('a'.repeat(161));
    assert.equal(counted.messages, 2);
    assert.equal(counted.perMessage, 153);
    assert.equal(counted.remaining, 145);
  });
  test('charges two places for the bracket-family characters, as the standard does', () => {
    assert.equal(smsSegments('[').units, 2);
    assert.equal(smsSegments('€').units, 2);
    assert.equal(smsSegments('{'.repeat(80)).messages, 1);
    assert.equal(smsSegments('{'.repeat(81)).messages, 2);
    assert.equal(smsSegments('{}[]~|^\\€').encoding, 'GSM-7');
  });
  test('halves the allowance for one character outside the 7-bit set', () => {
    const counted = smsSegments('don’t' + 'a'.repeat(65));
    assert.equal(counted.encoding, 'UCS-2');
    assert.equal(counted.characters, 70);
    assert.equal(counted.messages, 1);
    assert.equal(smsSegments('don’t' + 'a'.repeat(66)).messages, 2);
    assert.equal(smsSegments('don’t' + 'a'.repeat(66)).perMessage, 67);
  });
  test('counts an emoji as the two units it costs', () => {
    const counted = smsSegments('👋');
    assert.equal(counted.characters, 1);
    assert.equal(counted.units, 2);
    assert.equal(counted.encoding, 'UCS-2');
    assert.equal(smsSegments('👋'.repeat(35) + 'a').messages, 2);
  });
  test('never reports zero messages, even for nothing', () => {
    assert.equal(smsSegments('').messages, 1);
    assert.equal(smsSegments('').units, 0);
  });
});

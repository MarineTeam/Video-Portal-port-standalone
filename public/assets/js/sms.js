// The provider-independent half of texting (lib/sms.ts), in the browser.
//
// The broadcast composer shows the cost as you type — a single curly
// apostrophe pasted from a word processor halves what fits in one text,
// which is worth knowing before it is multiplied by three hundred — and the
// server counts the same way when the message goes out, so the cost on the
// screen is the cost that goes out. app/Support/Sms.php is the same two
// functions, against the same cases.

export const MAX_DIGITS = 15;
export const MIN_DIGITS = 7;
export const GSM_SINGLE = 160;
export const GSM_PART = 153;
export const UCS2_SINGLE = 70;
export const UCS2_PART = 67;

const GSM_BASIC =
  '@£$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !"#¤%&\'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà';

/** Two places each: GSM-7 sends an escape and then the character. */
const GSM_EXTENDED = ['^', '{', '}', '\\', '[', '~', ']', '|', '€'];

/**
 * A number in E.164, or null when it cannot be made into one. A national
 * number with no default country code is refused rather than guessed at.
 */
export function normalizePhone(given, defaultCountry = null) {
  if (typeof given !== 'string' && typeof given !== 'number') return null;
  let text = String(given).trim();
  if (text === '' || /[a-z]/i.test(text)) return null;
  text = text.replace(/^00/, '+');
  const international = text.startsWith('+');
  let digits = text.replace(/\D+/g, '');
  if (digits === '') return null;
  if (!international) {
    const code = String(defaultCountry ?? '').replace(/\D+/g, '');
    if (code === '') return null;
    // The trunk zero belongs to dialling inside the country, not to the number.
    digits = code + digits.replace(/^0+/, '');
  }
  if (digits.length < MIN_DIGITS || digits.length > MAX_DIGITS) return null;
  return `+${digits}`;
}

/** What a message costs, counted the way the standard charges for it. */
export function smsSegments(body) {
  const characters = [...String(body ?? '')];
  let gsm = true;
  let units = 0;
  for (const character of characters) {
    if (GSM_EXTENDED.includes(character)) {
      units += 2;
      continue;
    }
    if (!GSM_BASIC.includes(character)) gsm = false;
    units += character.length; // a surrogate pair is two units, and costs two
  }
  if (!gsm) units = characters.reduce((total, character) => total + character.length, 0);
  const single = gsm ? GSM_SINGLE : UCS2_SINGLE;
  const part = gsm ? GSM_PART : UCS2_PART;
  const messages = Math.max(1, units <= single ? 1 : Math.ceil(units / part));
  const perMessage = messages > 1 ? part : single;
  return {
    characters: characters.length,
    units,
    messages,
    perMessage,
    remaining: Math.max(0, messages * perMessage - units),
    encoding: gsm ? 'GSM-7' : 'UCS-2',
  };
}

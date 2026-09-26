<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The provider-independent half of texting (lib/sms.ts).
 *
 * It lives apart from the providers because the broadcast composer shows the
 * cost as somebody types, in the browser, and the server counts the same way
 * when the message goes out: the cost on the screen is the cost that goes
 * out. `public/assets/js/sms.js` is the same two functions for the browser,
 * and the two are tested against the same cases.
 */
final class Sms
{
    /** E.164: a country code and up to fifteen digits in all. */
    public const MAX_DIGITS = 15;
    public const MIN_DIGITS = 7;

    /** What fits in one GSM-7 message, and in each part once it is split. */
    public const GSM_SINGLE = 160;
    public const GSM_PART = 153;

    /** The same for a message with anything outside the 7-bit set in it. */
    public const UCS2_SINGLE = 70;
    public const UCS2_PART = 67;

    /** The 7-bit alphabet, which is what a plain text costs 160 characters of. */
    public const GSM_BASIC = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";

    /**
     * The characters that cost two places, because GSM-7 sends them as an
     * escape and then the character. Every one of them is on a keyboard
     * somebody writing a text will reach for.
     */
    public const GSM_EXTENDED = ['^', '{', '}', '\\', '[', '~', ']', '|', '€'];

    /**
     * A number in E.164, or null when it cannot be made into one.
     *
     * A national number with no default country code is refused rather than
     * guessed at: guessing wrong sends somebody's message to a stranger in
     * another country, and the church that would be hurt by it is exactly the
     * one that never set the default.
     *
     * @param ?string $defaultCountry the dialling code, with or without its +
     */
    public static function normalizePhone(mixed $given, ?string $defaultCountry = null): ?string
    {
        if (!is_string($given) && !is_int($given)) {
            return null;
        }
        $text = trim((string) $given);
        if ($text === '' || preg_match('/[a-z]/i', $text)) {
            return null;
        }
        // 00 is the other way of writing +, dialled the world over.
        $text = (string) preg_replace('/^00/', '+', $text);
        $international = str_starts_with($text, '+');
        $digits = (string) preg_replace('/\D+/', '', $text);
        if ($digits === '') {
            return null;
        }
        if (!$international) {
            $code = (string) preg_replace('/\D+/', '', (string) $defaultCountry);
            if ($code === '') {
                return null;
            }
            // The trunk zero is for dialling inside the country and is not
            // part of the number once the country code is on the front.
            $digits = $code . preg_replace('/^0+/', '', $digits);
        }
        $length = strlen($digits);
        if ($length < self::MIN_DIGITS || $length > self::MAX_DIGITS) {
            return null;
        }
        return '+' . $digits;
    }

    /**
     * What a message costs, counted the way the standard charges for it.
     *
     * @return array{characters: int, units: int, messages: int, perMessage: int, remaining: int, encoding: string}
     */
    public static function segments(string $body): array
    {
        $characters = self::characters($body);
        $gsm = true;
        $units = 0;
        foreach ($characters as $character) {
            if (in_array($character, self::GSM_EXTENDED, true)) {
                // Two places: the escape, then the character.
                $units += 2;
                continue;
            }
            if (mb_strpos(self::GSM_BASIC, $character) === false) {
                $gsm = false;
            }
            // An emoji is a pair of UTF-16 units, and is charged as two.
            $units += self::units($character);
        }
        if (!$gsm) {
            // Outside the 7-bit set the whole message goes as UCS-2, so one
            // curly apostrophe pasted from a word processor halves what fits.
            $units = array_sum(array_map([self::class, 'units'], $characters));
        }
        $single = $gsm ? self::GSM_SINGLE : self::UCS2_SINGLE;
        $part = $gsm ? self::GSM_PART : self::UCS2_PART;
        $messages = $units <= $single ? 1 : (int) ceil($units / $part);
        $perMessage = $messages > 1 ? $part : $single;
        return [
            'characters' => count($characters),
            'units' => $units,
            // Never zero: an empty box still costs one message if it is sent.
            'messages' => max(1, $messages),
            'perMessage' => $perMessage,
            'remaining' => max(0, $messages * $perMessage - $units),
            'encoding' => $gsm ? 'GSM-7' : 'UCS-2',
        ];
    }

    /** @return list<string> */
    private static function characters(string $body): array
    {
        $out = preg_split('//u', $body, -1, PREG_SPLIT_NO_EMPTY);
        return $out === false ? [] : $out;
    }

    /** What one character costs in UTF-16 units, which is what is charged. */
    private static function units(string $character): int
    {
        $code = mb_ord($character, 'UTF-8');
        return $code !== false && $code > 0xFFFF ? 2 : 1;
    }
}

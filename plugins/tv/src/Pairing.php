<?php

declare(strict_types=1);

namespace MarineTeam\Plugins\Tv;

/**
 * Signing a television in is RFC 8628, not a password box
 * (lib/tv-pairing.ts).
 *
 * The user code and the device code are two different secrets on purpose.
 * The first is on a screen in a public room and only ever *names* a request:
 * knowing it lets somebody approve a pairing, which is why the approval
 * screen says whose television it is about and what could go wrong. The
 * second never leaves the television and is the only thing that can exchange
 * an approval for a token.
 *
 * Both are stored hashed, the token is compared in constant time, and
 * claiming is a conditional update, so two polls arriving together cannot
 * both mint one.
 */
final class Pairing
{
    /**
     * No vowels, so a code is never a word; no digits, so no digit can be
     * mistaken for a letter on the screen; and nothing left that looks like
     * anything else still in it — D and Q go with O, I and 1 go with L.
     * 18^6 is thirty-four million codes, against a code that lives minutes
     * and a lookup that is rate-limited.
     */
    public const ALPHABET = 'BCFGHJKLMNPRSTVWXZ';

    /** The length shown on the screen. */
    public const LENGTH = 6;

    /**
     * What somebody may plausibly have typed for a character the screen did
     * show. A character the screen can never show and that resembles
     * nothing in the alphabet is left alone, so the lookup simply fails
     * rather than being bent into a different code.
     */
    public const LOOKALIKES = ['1' => 'L', 'I' => 'L', '|' => 'L', '!' => 'L', '5' => 'S', '8' => 'B', '6' => 'G', '2' => 'Z', 'U' => 'V'];

    /** How long a code on a screen is worth anything. */
    public const TTL = 900;

    /** How often a television should ask. */
    public const INTERVAL = 5;

    /** Long enough to fill the approval screen, and no longer. */
    public const MAX_NAME = 60;

    public const PENDING = 'PENDING';
    public const APPROVED = 'APPROVED';
    public const DENIED = 'DENIED';
    public const LINKED = 'LINKED';
    public const REVOKED = 'REVOKED';

    /** What a poll is told. */
    public const WAIT = 'WAIT';
    public const READY = 'READY';
    public const REFUSED = 'REFUSED';
    public const EXPIRED = 'EXPIRED';
    public const GONE = 'GONE';

    /** A code as somebody typed it, read back as the screen meant it. */
    public static function normalizeUserCode(string $typed): string
    {
        $out = '';
        foreach (str_split(mb_strtoupper(trim($typed))) as $character) {
            $character = self::LOOKALIKES[$character] ?? $character;
            if (ctype_alnum($character)) {
                $out .= $character;
            }
            if (strlen($out) === self::LENGTH) {
                // One extra keypress does not fail the lookup.
                break;
            }
        }
        return $out;
    }

    public static function isWellFormedUserCode(string $code): bool
    {
        if (strlen($code) !== self::LENGTH) {
            return false;
        }
        foreach (str_split($code) as $character) {
            if (!str_contains(self::ALPHABET, $character)) {
                return false;
            }
        }
        return true;
    }

    /**
     * A code from random bytes. Never from rand(): the alphabet is small
     * enough that a guessable code is a signed-in television.
     */
    public static function userCodeFromBytes(string $bytes): string
    {
        $size = strlen(self::ALPHABET);
        $out = '';
        for ($i = 0; $i < self::LENGTH; $i++) {
            $out .= self::ALPHABET[ord($bytes[$i % max(1, strlen($bytes))]) % $size];
        }
        return $out;
    }

    /** Rejection sampling rather than a modulo, so no character is likelier. */
    public static function newUserCode(): string
    {
        $out = '';
        for ($i = 0; $i < self::LENGTH; $i++) {
            $out .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }
        return $out;
    }

    /** Broken in half, which reads back better across a room. */
    public static function formatUserCode(string $code): string
    {
        $half = (int) (self::LENGTH / 2);
        return strlen($code) === self::LENGTH ? substr($code, 0, $half) . '-' . substr($code, $half) : $code;
    }

    /**
     * What a television polling is told.
     *
     * Expiry is checked before "approved", so a code somebody approved and
     * then walked away from stops being redeemable rather than waiting for
     * ever; and a pairing that has already handed over its token is gone,
     * not ready a second time.
     *
     * @param array<string, mixed>|null $device
     * @return array{status: string, interval: int}
     */
    public static function pollAnswer(?array $device, ?string $now = null): array
    {
        $answer = static fn (string $status) => ['status' => $status, 'interval' => self::INTERVAL];
        if ($device === null) {
            return $answer(self::GONE);
        }
        $status = (string) ($device['status'] ?? self::PENDING);
        if ($status === self::REVOKED || ($device['revoked_at'] ?? null) !== null) {
            return $answer(self::GONE);
        }
        if ($status === self::LINKED) {
            // Its token was minted once; a second poll is not a second token.
            return $answer(self::GONE);
        }
        if (self::hasExpired($device, $now)) {
            return $answer(self::EXPIRED);
        }
        if ($status === self::DENIED) {
            return $answer(self::REFUSED);
        }
        return $answer($status === self::APPROVED ? self::READY : self::WAIT);
    }

    /** @param array<string, mixed> $device */
    public static function hasExpired(array $device, ?string $now = null): bool
    {
        $expires = (string) ($device['expires_at'] ?? '');
        if ($expires === '') {
            return true;
        }
        $zone = new \DateTimeZone('UTC');
        return new \DateTimeImmutable($now ?? 'now', $zone) >= new \DateTimeImmutable($expires, $zone);
    }

    /** @param array<string, mixed>|null $device */
    public static function canApprove(?array $device, ?string $now = null): bool
    {
        return $device !== null
            && (string) ($device['status'] ?? '') === self::PENDING
            && ($device['revoked_at'] ?? null) === null
            && !self::hasExpired($device, $now);
    }

    /**
     * What the person holding the phone is asked. It names the device and
     * says plainly what saying yes does, because the code was visible to
     * everybody in the room and the request may not be theirs.
     *
     * @param array<string, mixed> $device
     */
    public static function approvalPrompt(array $device): string
    {
        return t('tv.approvalPrompt', ['name' => self::cleanDeviceName((string) ($device['device_name'] ?? ''))]);
    }

    /**
     * The name a television calls itself, shown on the approval screen.
     *
     * It is supplied by the device and never trusted: one line, so it cannot
     * smuggle a second sentence ("...  Approving this is safe.") into the
     * question somebody is being asked, and capped so it cannot fill the
     * screen.
     */
    public static function cleanDeviceName(string $given): string
    {
        $name = (string) preg_replace('/[\p{C}\p{Zl}\p{Zp}]+/u', ' ', $given);
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));
        if ($name === '') {
            return t('tv.aTelevision');
        }
        return mb_strlen($name) > self::MAX_NAME ? mb_substr($name, 0, self::MAX_NAME - 1) . '…' : $name;
    }
}

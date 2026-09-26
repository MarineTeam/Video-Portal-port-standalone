<?php

declare(strict_types=1);

namespace MarineTeam\Plugins\Broadcasts;

use App\Support\Sms;

/**
 * Who a broadcast reaches, and who it does not (lib/broadcast.ts).
 *
 * Pure, and resolved into rows before anything is sent, so the count on the
 * screen is the count that goes out. Without that number "I told everyone"
 * is false and the people who got it assume everybody did.
 *
 * Consent is three separate rules, and they are here rather than at the
 * provider because they are the church's rules, not Twilio's:
 *
 *  - Email is on unless somebody turned announcements off. That is a
 *    different switch from "email me when a sermon publishes", because
 *    turning that off is not asking to miss a cancellation.
 *  - A text needs an explicit yes *and* a number the member typed in
 *    themselves. A number given on a public event form is never consent.
 *  - Push needs a device already signed up.
 */
final class Broadcast
{
    public const EMAIL = 'EMAIL';
    public const SMS = 'SMS';
    public const PUSH = 'PUSH';
    public const CHANNELS = [self::EMAIL, self::SMS, self::PUSH];

    public const EVERYONE = 'EVERYONE';
    public const PERMISSION_GROUP = 'PERMISSION_GROUP';
    public const EVENT = 'EVENT';
    public const SMALL_GROUP = 'SMALL_GROUP';
    public const TEAM = 'TEAM';
    public const AUDIENCES = [self::EVERYONE, self::PERMISSION_GROUP, self::EVENT, self::SMALL_GROUP, self::TEAM];

    public const DRAFT = 'DRAFT';
    public const SENDING = 'SENDING';
    public const SENT = 'SENT';
    public const CANCELLED = 'CANCELLED';

    public const PENDING = 'PENDING';
    public const OK = 'SENT';
    public const FAILED = 'FAILED';
    public const SKIPPED = 'SKIPPED';

    /** Why somebody is not reachable on a channel. */
    public const NO_EMAIL = 'noEmail';
    public const ANNOUNCEMENTS_OFF = 'announcementsOff';
    public const NO_PHONE = 'noPhone';
    public const BAD_PHONE = 'badPhone';
    public const NOT_OPTED_IN = 'notOptedIn';
    public const NO_ACCOUNT = 'noAccount';
    public const NO_DEVICE = 'noDevice';

    /**
     * Turn an audience into the rows that will be written.
     *
     * @param list<array<string, mixed>> $people each: userId?, name?, email?, phone?, broadcastEmails?, smsOptIn?, pushDevices?
     * @param list<string> $channels
     * @param array{defaultCountry?: ?string} $options
     * @return array{
     *   rows: list<array{userId: ?string, name: ?string, channel: string, address: string, person: string}>,
     *   skips: list<array{userId: ?string, name: ?string, channel: string, reason: string, person: string}>,
     *   reached: int, missed: int
     * }
     */
    public static function planDelivery(array $people, array $channels, array $options = []): array
    {
        $wanted = array_values(array_intersect(self::CHANNELS, $channels));
        $country = $options['defaultCountry'] ?? null;
        $rows = [];
        $skips = [];
        $reached = 0;
        $missed = 0;
        $seen = [];
        $written = [];
        foreach ($people as $person) {
            // Somebody in the audience twice — on a team and in the group it
            // serves — is written to once. Without an account there is no id
            // to match on, so the address is the identity.
            $key = self::identityOf($person);
            if ($key === null || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $any = false;
            foreach ($wanted as $channel) {
                $decided = self::forChannel($person, $channel, $country);
                if ($decided['address'] === null) {
                    $skips[] = ['userId' => self::userId($person), 'name' => self::nameOf($person), 'channel' => $channel, 'reason' => (string) $decided['reason'], 'person' => $key];
                    continue;
                }
                // The unique index is (broadcast, channel, address); two
                // people sharing an address would collide on the insert, so
                // they are collapsed here where it can be explained.
                $slot = $channel . '|' . $decided['address'];
                if (isset($written[$slot])) {
                    $any = true;
                    continue;
                }
                $written[$slot] = true;
                $rows[] = ['userId' => self::userId($person), 'name' => self::nameOf($person), 'channel' => $channel, 'address' => $decided['address'], 'person' => $key];
                $any = true;
            }
            // Reached if any one channel works; missed when nothing does —
            // which is the number that matters.
            $any ? $reached++ : $missed++;
        }
        return ['rows' => $rows, 'skips' => $skips, 'reached' => $reached, 'missed' => $missed];
    }

    /**
     * @param array<string, mixed> $person
     * @return array{address: ?string, reason: ?string}
     */
    public static function forChannel(array $person, string $channel, ?string $defaultCountry = null): array
    {
        $no = static fn (string $reason) => ['address' => null, 'reason' => $reason];
        if ($channel === self::EMAIL) {
            $email = trim((string) ($person['email'] ?? ''));
            if ($email === '' || !str_contains($email, '@')) {
                return $no(self::NO_EMAIL);
            }
            // On by default, and off only because somebody turned
            // announcements off themselves.
            return ($person['broadcastEmails'] ?? true) === false
                ? $no(self::ANNOUNCEMENTS_OFF)
                : ['address' => $email, 'reason' => null];
        }
        if ($channel === self::SMS) {
            // Deliberately not inferable from having a number: an event's
            // sign-up form collects them, and that is not permission to text.
            if (($person['smsOptIn'] ?? false) !== true) {
                return $no(self::NOT_OPTED_IN);
            }
            $phone = trim((string) ($person['phone'] ?? ''));
            if ($phone === '') {
                return $no(self::NO_PHONE);
            }
            $number = Sms::normalizePhone($phone, $defaultCountry);
            return $number === null ? $no(self::BAD_PHONE) : ['address' => $number, 'reason' => null];
        }
        if ($channel === self::PUSH) {
            $userId = self::userId($person);
            if ($userId === null) {
                return $no(self::NO_ACCOUNT);
            }
            return (int) ($person['pushDevices'] ?? 0) > 0
                ? ['address' => $userId, 'reason' => null]
                : $no(self::NO_DEVICE);
        }
        return $no(self::NO_ACCOUNT);
    }

    /**
     * Why the rest get nothing, commonest first: "41 get nothing · 28 no
     * mobile number, 13 turned off announcement emails".
     *
     * @param list<array{reason: string, person?: string}> $skips
     * @param list<array{person?: string}> $rows
     * @return list<array{reason: string, count: int}>
     */
    public static function summariseSkips(array $skips, array $rows = []): array
    {
        // Only somebody nothing worked for: a member with no mobile who got
        // the email is not missing out on anything.
        $reachable = [];
        foreach ($rows as $row) {
            $reachable[(string) ($row['person'] ?? '')] = true;
        }
        $counts = [];
        $counted = [];
        foreach ($skips as $skip) {
            $key = (string) ($skip['person'] ?? '');
            if (isset($reachable[$key]) || isset($counted[$key])) {
                continue;
            }
            $counted[$key] = true;
            $counts[$skip['reason']] = ($counts[$skip['reason']] ?? 0) + 1;
        }
        $out = [];
        foreach ($counts as $reason => $count) {
            $out[] = ['reason' => (string) $reason, 'count' => $count];
        }
        usort($out, fn (array $a, array $b) => [$b['count'], $a['reason']] <=> [$a['count'], $b['reason']]);
        return $out;
    }

    /**
     * What this went to, as it read when it was sent.
     *
     * The name is copied onto the broadcast at the time, so a list of past
     * broadcasts still reads sensibly after the group is renamed or deleted.
     *
     * @param array<string, mixed> $broadcast
     */
    public static function audienceLabel(array $broadcast): string
    {
        $audience = (string) ($broadcast['audience'] ?? self::EVERYONE);
        $name = trim((string) ($broadcast['audienceName'] ?? $broadcast['audience_name'] ?? ''));
        if ($audience === self::EVERYONE) {
            return t('broadcasts.audience.everyone');
        }
        $kind = t('broadcasts.audience.' . strtolower($audience));
        return $name === '' ? t('broadcasts.audience.gone', ['kind' => $kind]) : $kind . ': ' . $name;
    }

    /**
     * How far a send has got.
     *
     * Anything not still pending is done — a failure is as finished as a
     * success, and a send that stopped at the failures would never end.
     *
     * @param array<string, int> $byStatus
     * @return array{total: int, done: int, sent: int, failed: int, skipped: int, pending: int, finished: bool, percent: int}
     */
    public static function progressOf(array $byStatus): array
    {
        $sent = (int) ($byStatus[self::OK] ?? 0);
        $failed = (int) ($byStatus[self::FAILED] ?? 0);
        $skipped = (int) ($byStatus[self::SKIPPED] ?? 0);
        $pending = (int) ($byStatus[self::PENDING] ?? 0);
        $total = $sent + $failed + $skipped + $pending;
        $done = $total - $pending;
        return [
            'total' => $total,
            'done' => $done,
            'sent' => $sent,
            'failed' => $failed,
            'skipped' => $skipped,
            'pending' => $pending,
            'finished' => $pending === 0,
            'percent' => $total === 0 ? 100 : (int) floor($done * 100 / $total),
        ];
    }

    /** @param array<string, mixed> $person */
    private static function userId(array $person): ?string
    {
        $id = trim((string) ($person['userId'] ?? ''));
        return $id === '' ? null : $id;
    }

    /** @param array<string, mixed> $person */
    private static function nameOf(array $person): ?string
    {
        $name = trim((string) ($person['name'] ?? ''));
        return $name === '' ? null : $name;
    }

    /**
     * Who this is, for deduplication: the account where there is one, and
     * otherwise the address, because somebody with no account has no id to
     * match on and an event's sign-ups are full of them.
     *
     * @param array<string, mixed> $person
     */
    private static function identityOf(array $person): ?string
    {
        $id = self::userId($person);
        if ($id !== null) {
            return 'user:' . $id;
        }
        $email = strtolower(trim((string) ($person['email'] ?? '')));
        $phone = trim((string) ($person['phone'] ?? ''));
        if ($email !== '') {
            return 'email:' . $email;
        }
        return $phone === '' ? null : 'phone:' . $phone;
    }
}

<?php

declare(strict_types=1);

namespace MarineTeam\Plugins\Profiles;

/**
 * The member directory's rules, as the original's lib/directory.ts had
 * them. Nobody is in it unless they asked; each contact detail is its own
 * separate yes; a name is the only thing published by default and an email
 * address is never used as one; a listing is a view of live rows, so
 * somebody whose access is withdrawn is gone without anybody tidying up.
 */
final class Directory
{
    /** The name a listing shows: the one they chose, then their sign-in name — never an address. */
    public static function directoryName(array $user): ?string
    {
        foreach (['display_name', 'name'] as $field) {
            $value = trim((string) ($user[$field] ?? ''));
            if ($value !== '' && !str_contains($value, '@')) {
                return $value;
            }
        }
        return null;
    }

    /** In the directory: asked to be, still has access, and has a name to show. */
    public static function listed(array $user): bool
    {
        return (bool) ($user['directory_listed'] ?? false)
            && (bool) ($user['authorized'] ?? false)
            && self::directoryName($user) !== null;
    }

    /**
     * What a listing publishes: the name, then only what they ticked, and a
     * field left off rather than sent empty.
     *
     * @return array{name: string, note?: string, email?: string, phone?: string}
     */
    public static function presentMember(array $user): array
    {
        $out = ['name' => (string) self::directoryName($user)];
        $note = trim((string) ($user['directory_note'] ?? ''));
        if ($note !== '') {
            $out['note'] = $note;
        }
        $email = trim((string) ($user['email'] ?? ''));
        if ((bool) ($user['directory_show_email'] ?? false) && $email !== '') {
            $out['email'] = $email;
        }
        $phone = trim((string) ($user['phone'] ?? ''));
        if ((bool) ($user['directory_show_phone'] ?? false) && $phone !== '') {
            $out['phone'] = $phone;
        }
        return $out;
    }

    /**
     * @param list<array<string, mixed>> $users
     * @return list<array{name: string, note?: string, email?: string, phone?: string}>
     */
    public static function visibleDirectory(array $users): array
    {
        $out = array_map([self::class, 'presentMember'], array_values(array_filter($users, [self::class, 'listed'])));
        usort($out, fn ($a, $b) => strcasecmp($a['name'], $b['name']) ?: strcmp($a['name'], $b['name']));
        return $out;
    }

    /**
     * A name or a note — never a contact detail, even a published one, so
     * the directory can't be used to find out whose number that is.
     *
     * @param list<array{name: string, note?: string, email?: string, phone?: string}> $members
     * @return list<array{name: string, note?: string, email?: string, phone?: string}>
     */
    public static function searchDirectory(array $members, string $q): array
    {
        $q = mb_strtolower(trim($q));
        if ($q === '') {
            return $members;
        }
        return array_values(array_filter($members, fn ($m) => str_contains(mb_strtolower($m['name']), $q) || str_contains(mb_strtolower($m['note'] ?? ''), $q)));
    }

    /**
     * Where somebody stands, in plain words, as translation keys and values.
     *
     * @return array{0: string, 1: array<string, string>}
     */
    public static function directoryStanding(array $user): array
    {
        if (!(bool) ($user['directory_listed'] ?? false)) {
            return ['directory.standingOut', []];
        }
        if (!(bool) ($user['authorized'] ?? false)) {
            return ['directory.standingNoAccess', []];
        }
        $name = self::directoryName($user);
        if ($name === null) {
            return ['directory.standingNoName', []];
        }
        $member = self::presentMember($user);
        $key = match (true) {
            isset($member['email'], $member['phone']) => 'directory.standingEmailPhone',
            isset($member['email']) => 'directory.standingEmail',
            isset($member['phone']) => 'directory.standingPhone',
            default => 'directory.standingName',
        };
        return [$key, ['name' => $name]];
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Profile;

use App\Core\App;
use App\Core\Json;
use App\Support\Groups;

/**
 * "Download my data": one JSON document holding everything the site keeps
 * against one member. Three rules, which pull against each other:
 *
 *   completeness — a row keyed to the member is in the file;
 *   nobody else's data leaves with it — a reply is marked as one, not
 *     quoted with its parent; a group's address follows Groups::canSeeAddress;
 *     no moderator or sender is named;
 *   no live secret — push endpoints, television and calendar tokens,
 *     password hashes are capabilities, not facts.
 *
 * assertExportSafe() walks the finished document and throws, naming the
 * path, if a forbidden key appears anywhere: a credential reaching that
 * point means a query changed, and stripping it quietly would hide that.
 */
final class DataExport
{
    public const FORMAT = 'marine-team-export/1';

    /** Keys that never appear in an export, at any depth. */
    public const FORBIDDEN_KEYS = [
        'passwordHash', 'calendarToken', 'token', 'tokenHash', 'deviceCodeHash', 'userCode',
        'endpoint', 'endpointHash', 'p256dh', 'auth', 'hashedKey', 'idHash', 'secret', 'data',
    ];

    /**
     * Every path at which a forbidden key appears — whole keys only, arrays
     * included.
     *
     * @return list<string>
     */
    public static function unsafeKeysIn(mixed $doc, string $path = '$'): array
    {
        $found = [];
        if (!is_array($doc)) {
            return $found;
        }
        foreach ($doc as $key => $value) {
            $here = is_int($key) ? "{$path}[$key]" : "$path.$key";
            if (is_string($key) && in_array($key, self::FORBIDDEN_KEYS, true)) {
                $found[] = $here;
            }
            array_push($found, ...self::unsafeKeysIn($value, $here));
        }
        return $found;
    }

    public static function assertExportSafe(mixed $doc): void
    {
        $found = self::unsafeKeysIn($doc);
        if ($found !== []) {
            throw new \LogicException('The export holds a credential at ' . implode(', ', $found) . '; refusing to send it.');
        }
    }

    /** marine-team-<name>-<yyyy-mm-dd>.json, ASCII and header-safe. */
    public static function exportFilename(?string $name, string $email, \DateTimeInterface $when): string
    {
        $base = $name !== null && trim($name) !== '' ? $name : (string) strstr($email, '@', true);
        if (function_exists('iconv')) {
            $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $base);
            $base = is_string($ascii) ? $ascii : $base;
        }
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($base)), '-');
        $slug = substr($slug, 0, 40);
        return 'marine-team-' . ($slug === '' ? 'member' : rtrim($slug, '-')) . '-' . $when->format('Y-m-d') . '.json';
    }

    /** The push service a device used (https://fcm.googleapis.com), never the endpoint itself. */
    public static function pushServiceOf(string $endpoint): string
    {
        $parts = parse_url($endpoint);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || !isset($parts['host']) || $parts['host'] === '') {
            return 'unknown';
        }
        return 'https://' . strtolower($parts['host']);
    }

    /** Rows in every list, nested lists included. */
    public static function totalRecords(mixed $doc): int
    {
        if (!is_array($doc)) {
            return 0;
        }
        $n = array_is_list($doc) ? count($doc) : 0;
        foreach ($doc as $value) {
            $n += self::totalRecords($value);
        }
        return $n;
    }

    /** @return array<string, mixed> */
    public static function build(App $app, string $userId): array
    {
        $db = $app->db();
        $email = (string) $db->value('SELECT email FROM {{users}} WHERE id = ?', [$userId]);
        if ($email === '') {
            throw new \RuntimeException('No such member.');
        }
        $doc = ['format' => self::FORMAT, 'exportedAt' => gmdate('Y-m-d\TH:i:s.v\Z')];
        foreach (ExportQueries::QUERIES as $section => [$table, $sql]) {
            $params = [];
            if (str_contains($sql, ':user')) {
                $params['user'] = $userId;
            }
            if (str_contains($sql, ':email')) {
                $params['email'] = $email;
            }
            $rows = Json::rows($table, $db->all($sql, $params));
            $doc[$section] = match ($section) {
                'account' => $rows[0] ?? null,
                'pushDevices' => array_map(fn ($r) => ['service' => self::pushServiceOf((string) $r['endpoint']), 'createdAt' => $r['createdAt']], $rows),
                'smallGroups' => array_map(function ($r) {
                    if (!Groups::canSeeAddress((string) $r['status'])) {
                        unset($r['address']);
                    }
                    return $r;
                }, $rows),
                default => self::booleans($rows),
            };
        }
        if (is_array($doc['account'])) {
            $doc['account'] = self::booleans([$doc['account']])[0];
        }
        $doc = $app->hooks->apply('profile.export', $doc, $userId);
        self::assertExportSafe($doc);
        return $doc;
    }

    /**
     * Computed flags (is_reply, has_password, covering_for_someone) come back
     * from MySQL as 0/1; they are booleans.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private static function booleans(array $rows): array
    {
        foreach ($rows as &$row) {
            foreach (['isReply', 'hasPassword', 'coveringForSomeone'] as $key) {
                if (array_key_exists($key, $row) && $row[$key] !== null) {
                    $row[$key] = (bool) $row[$key];
                }
            }
        }
        return $rows;
    }
}

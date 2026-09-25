<?php

declare(strict_types=1);

namespace App\Services\Video;

/**
 * Pasted links, recognised and reduced to what each provider keys on. Pure,
 * so every shape a volunteer might paste is pinned by a test.
 */
final class Links
{
    private static function parts(string $url): ?array
    {
        $url = trim($url);
        if (!preg_match('#^https?://#i', $url)) {
            $url = 'https://' . ltrim($url, '/');
        }
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['host'])) {
            return null;
        }
        $parts['host'] = strtolower((string) $parts['host']);
        $parts['path'] ??= '/';
        return $parts;
    }

    /** @return array<string, string> */
    private static function query(array $parts): array
    {
        parse_str((string) ($parts['query'] ?? ''), $q);
        return array_filter($q, 'is_string');
    }

    /** watch?v=, youtu.be/, /shorts/, /embed/, /live/, /v/ — or null. */
    public static function youtubeId(string $url): ?string
    {
        $p = self::parts($url);
        if ($p === null) {
            return null;
        }
        $host = (string) preg_replace('/^(www\.|m\.|music\.)/', '', $p['host']);
        $id = null;
        if ($host === 'youtu.be') {
            $id = explode('/', trim($p['path'], '/'))[0] ?? null;
        } elseif (in_array($host, ['youtube.com', 'youtube-nocookie.com'], true)) {
            if (preg_match('#^/(?:shorts|embed|live|v)/([^/?]+)#', $p['path'], $m)) {
                $id = $m[1];
            } elseif ($p['path'] === '/watch') {
                $id = self::query($p)['v'] ?? null;
            }
        }
        return is_string($id) && preg_match('/^[A-Za-z0-9_-]{11}$/', $id) ? $id : null;
    }

    /**
     * vimeo.com/ID, vimeo.com/ID/HASH (unlisted), vimeo.com/channels/x/ID,
     * player.vimeo.com/video/ID?h=HASH.
     *
     * @return array{id: string, hash: ?string}|null
     */
    public static function vimeo(string $url): ?array
    {
        $p = self::parts($url);
        if ($p === null || !in_array($p['host'], ['vimeo.com', 'www.vimeo.com', 'player.vimeo.com'], true)) {
            return null;
        }
        $segments = array_values(array_filter(explode('/', $p['path']), fn ($s) => $s !== ''));
        $hash = self::query($p)['h'] ?? null;
        foreach ($segments as $i => $segment) {
            if (preg_match('/^\d{3,12}$/', $segment)) {
                $next = $segments[$i + 1] ?? null;
                if ($hash === null && is_string($next) && preg_match('/^[0-9a-f]{6,20}$/', $next)) {
                    $hash = $next;
                }
                return ['id' => $segment, 'hash' => is_string($hash) && preg_match('/^[0-9a-f]{6,20}$/', $hash) ? $hash : null];
            }
        }
        return null;
    }

    /**
     * A Dropbox shared link as a direct one: /s/…, /scl/fi/… and
     * dl.dropboxusercontent.com links, with dl=1 (and the rlkey kept).
     */
    public static function dropboxDirect(string $url): ?string
    {
        $p = self::parts($url);
        if ($p === null || !in_array($p['host'], ['dropbox.com', 'www.dropbox.com', 'dl.dropboxusercontent.com', 'dl.dropbox.com'], true)) {
            return null;
        }
        if ($p['host'] !== 'dl.dropboxusercontent.com' && !preg_match('#^/(s|scl/fi|sh)/#', $p['path'])) {
            return null;
        }
        $q = self::query($p);
        $keep = array_intersect_key($q, ['rlkey' => true, 'st' => true]);
        $keep['dl'] = '1';
        return 'https://' . ($p['host'] === 'dl.dropboxusercontent.com' ? $p['host'] : 'www.dropbox.com') . $p['path'] . '?' . http_build_query($keep);
    }

    /** drive.google.com/file/d/ID/…, open?id=ID, uc?id=ID. */
    public static function driveId(string $url): ?string
    {
        $p = self::parts($url);
        if ($p === null || !in_array($p['host'], ['drive.google.com', 'docs.google.com'], true)) {
            return null;
        }
        $id = null;
        if (preg_match('#^/file/d/([^/]+)#', $p['path'], $m)) {
            $id = $m[1];
        } elseif (in_array($p['path'], ['/open', '/uc'], true)) {
            $id = self::query($p)['id'] ?? null;
        }
        return is_string($id) && preg_match('/^[A-Za-z0-9_-]{10,100}$/', $id) ? $id : null;
    }

    /**
     * A OneDrive or SharePoint sharing link, and whether it is personal
     * (resolvable anonymously) or business (needs Graph).
     *
     * @return array{url: string, business: bool}|null
     */
    public static function onedrive(string $url): ?array
    {
        $p = self::parts($url);
        if ($p === null) {
            return null;
        }
        $host = $p['host'];
        if ($host === '1drv.ms' || $host === 'onedrive.live.com') {
            return ['url' => trim($url), 'business' => false];
        }
        if (preg_match('/^[a-z0-9-]+(-my)?\.sharepoint\.com$/', $host)) {
            return ['url' => trim($url), 'business' => true];
        }
        return null;
    }

    /** The Graph / OneDrive shares id: "u!" and the link in unpadded base64url. */
    public static function sharesId(string $url): string
    {
        return 'u!' . rtrim(strtr(base64_encode($url), '+/', '-_'), '=');
    }

    /** archive.org/details/IDENTIFIER (optionally /FILE). */
    public static function archiveIdentifier(string $url): ?string
    {
        $p = self::parts($url);
        if ($p === null || !in_array($p['host'], ['archive.org', 'www.archive.org'], true)) {
            return null;
        }
        if (!preg_match('#^/(?:details|embed|download)/([A-Za-z0-9._-]{1,100})#', $p['path'], $m)) {
            return null;
        }
        return $m[1];
    }

    /** "PT1H2M3S" → 3723; null when it isn't a duration. */
    public static function isoDuration(string $iso): ?int
    {
        if (!preg_match('/^P(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+(?:\.\d+)?)S)?)?$/', $iso, $m) || $iso === 'P' || $iso === 'PT') {
            return null;
        }
        $n = fn (int $i) => isset($m[$i]) && $m[$i] !== '' ? (int) $m[$i] : 0;
        return $n(1) * 86400 + $n(2) * 3600 + $n(3) * 60 + (int) floor((float) ($m[4] ?? 0));
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Files;

/**
 * What an upload may be, decided by its extension from a short list and
 * confirmed against its bytes — never by the type the browser claims.
 *
 * On the way out, the Content-Type comes from the stored extension alone,
 * never from a stored MIME string (for an imported object that is whatever a
 * dashboard was told). Only reader formats, audio and raster images are shown
 * inline; the rest are downloads; anything not on the list at all — including
 * a file from before this rule — is an opaque download the browser won't
 * render: octet-stream, attachment, nosniff, and a sandbox CSP.
 *
 * SVG is not an image here: it can carry script, and the logo is on every page.
 */
final class UploadTypes
{
    /**
     * @var array<string, array{type: string, kind: string, inline: bool, sniff: list<string>}>
     */
    public const TYPES = [
        'pdf' => ['type' => 'application/pdf', 'kind' => 'document', 'inline' => true, 'sniff' => ['application/pdf']],
        'epub' => ['type' => 'application/epub+zip', 'kind' => 'document', 'inline' => true, 'sniff' => ['application/epub+zip', 'application/zip']],
        'mp3' => ['type' => 'audio/mpeg', 'kind' => 'audio', 'inline' => true, 'sniff' => ['audio/mpeg', 'audio/mp3', 'application/octet-stream']],
        'm4a' => ['type' => 'audio/mp4', 'kind' => 'audio', 'inline' => true, 'sniff' => ['audio/mp4', 'audio/x-m4a', 'video/mp4', 'audio/m4a']],
        'ogg' => ['type' => 'audio/ogg', 'kind' => 'audio', 'inline' => true, 'sniff' => ['audio/ogg', 'application/ogg', 'video/ogg']],
        'jpg' => ['type' => 'image/jpeg', 'kind' => 'image', 'inline' => true, 'sniff' => ['image/jpeg']],
        'jpeg' => ['type' => 'image/jpeg', 'kind' => 'image', 'inline' => true, 'sniff' => ['image/jpeg']],
        'png' => ['type' => 'image/png', 'kind' => 'image', 'inline' => true, 'sniff' => ['image/png']],
        'webp' => ['type' => 'image/webp', 'kind' => 'image', 'inline' => true, 'sniff' => ['image/webp']],
        'gif' => ['type' => 'image/gif', 'kind' => 'image', 'inline' => true, 'sniff' => ['image/gif']],
        'vtt' => ['type' => 'text/vtt', 'kind' => 'caption', 'inline' => false, 'sniff' => ['text/vtt', 'text/plain']],
        'srt' => ['type' => 'application/x-subrip', 'kind' => 'caption', 'inline' => false, 'sniff' => ['text/plain', 'application/x-subrip']],
        'mp4' => ['type' => 'video/mp4', 'kind' => 'video', 'inline' => false, 'sniff' => ['video/mp4']],
        'webm' => ['type' => 'video/webm', 'kind' => 'video', 'inline' => false, 'sniff' => ['video/webm']],
    ];

    /** Which kinds each upload purpose may take. */
    public const PURPOSES = [
        'file' => ['document', 'audio', 'image'],
        'image' => ['image'],
        'caption' => ['caption'],
        'video' => ['video'],
    ];

    public static function extensionOf(string $path): string
    {
        $path = (string) preg_replace('/[?#].*$/s', '', $path);
        $base = basename(str_replace('\\', '/', $path));
        $dot = strrpos($base, '.');
        if ($dot === false || $dot === 0) {
            return '';
        }
        return strtolower(substr($base, $dot + 1));
    }

    /** @return array{type: string, kind: string, inline: bool, sniff: list<string>, ext: string}|null */
    public static function uploadType(string $fileName, ?string $purpose = null): ?array
    {
        $ext = self::extensionOf($fileName);
        $entry = self::TYPES[$ext] ?? null;
        if ($entry === null) {
            return null;
        }
        if ($purpose !== null && !in_array($entry['kind'], self::PURPOSES[$purpose] ?? [], true)) {
            return null;
        }
        return $entry + ['ext' => $ext];
    }

    /**
     * Checks the bytes agree with the extension. finfo reads the content;
     * a PHP script or an HTML page renamed .jpg says so here.
     */
    public static function bytesMatch(string $file, string $ext): bool
    {
        $entry = self::TYPES[$ext] ?? null;
        if ($entry === null || !is_file($file)) {
            return false;
        }
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $detected = (string) $finfo->file($file);
        if (in_array($entry['kind'], ['image'], true)) {
            $info = @getimagesize($file);
            if ($info === false || !in_array($info['mime'], $entry['sniff'], true)) {
                return false;
            }
        }
        if ($ext === 'vtt') {
            $head = (string) file_get_contents($file, false, null, 0, 16);
            return str_starts_with(ltrim($head, "\xEF\xBB\xBF"), 'WEBVTT') && in_array($detected, $entry['sniff'], true);
        }
        if ($ext === 'srt') {
            return in_array($detected, $entry['sniff'], true) && !self::looksLikeMarkup($file);
        }
        return in_array($detected, $entry['sniff'], true);
    }

    private static function looksLikeMarkup(string $file): bool
    {
        $head = strtolower((string) file_get_contents($file, false, null, 0, 1024));
        return str_contains($head, '<script') || str_contains($head, '<html') || str_contains($head, '<?php');
    }

    /** The stored object's name: nothing of the client's name survives. */
    public static function objectName(string $id, string $fileName): ?string
    {
        if (!preg_match('/^[a-z0-9]{8,32}$/', $id)) {
            return null;
        }
        $type = self::uploadType($fileName);
        return $type === null ? null : 'files/' . $id . '.' . ($type['ext'] === 'jpeg' ? 'jpg' : $type['ext']);
    }

    /**
     * How a stored object is served, from its extension alone.
     *
     * @return array{type: string, disposition: string, headers: array<string, string>}
     */
    public static function servePolicy(string $storedPath, bool $download = false): array
    {
        $entry = self::TYPES[self::extensionOf($storedPath)] ?? null;
        if ($entry === null) {
            return [
                'type' => 'application/octet-stream',
                'disposition' => 'attachment',
                'headers' => ['X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => 'sandbox'],
            ];
        }
        return [
            'type' => $entry['type'],
            'disposition' => $entry['inline'] && !$download ? 'inline' : 'attachment',
            'headers' => ['X-Content-Type-Options' => 'nosniff'],
        ];
    }

    /** The refusal a person reads. */
    public static function refusal(?string $purpose = null): string
    {
        $kinds = $purpose === null ? null : (self::PURPOSES[$purpose] ?? []);
        $exts = [];
        foreach (self::TYPES as $ext => $entry) {
            if ($ext !== 'jpeg' && ($kinds === null || in_array($entry['kind'], $kinds, true))) {
                $exts[] = strtoupper($ext);
            }
        }
        return 'That kind of file can’t be uploaded here. Allowed: ' . implode(', ', $exts) . '.';
    }
}

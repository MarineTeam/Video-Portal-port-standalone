<?php

declare(strict_types=1);

namespace App\Services\Files;

use App\Core\Request;
use App\Core\Response;

/**
 * Serves a file from disk with one Range, ETag/304 and 512 KB chunks, output
 * buffering off — or hands it to the web server with X-Sendfile /
 * X-Accel-Redirect / X-LiteSpeed-Location when the installer found support,
 * so PHP never pushes the bytes itself.
 */
final class RangeStreamer
{
    public const CHUNK = 524288;

    /** 'x-sendfile' | 'x-accel' | 'litespeed' | null, set from the installer's probe */
    public static ?string $offload = null;

    /** For X-Accel-Redirect: the internal location that maps to storage/. */
    public static string $accelPrefix = '/protected-storage/';

    /** @param array<string, string> $headers */
    public static function serve(string $path, Request $request, array $headers, ?string $storageRoot = null): Response
    {
        if (!is_file($path) || !is_readable($path)) {
            return Response::error('Not found', 404);
        }
        $size = (int) filesize($path);
        $mtime = (int) filemtime($path);
        $etag = '"' . substr(sha1($path . ':' . $size . ':' . $mtime), 0, 20) . '"';
        $base = $headers + ['Accept-Ranges' => 'bytes', 'ETag' => $etag, 'Last-Modified' => gmdate('D, d M Y H:i:s', $mtime) . ' GMT'];

        $ifNone = $request->header('if-none-match');
        if ($ifNone !== null && self::etagMatches($ifNone, $etag)) {
            return new Response(304, '', $base);
        }

        if (self::$offload !== null && $storageRoot !== null && str_starts_with($path, rtrim($storageRoot, '/') . '/')) {
            $response = new Response(200, '', $base);
            $relative = substr($path, strlen(rtrim($storageRoot, '/')) + 1);
            match (self::$offload) {
                'x-sendfile' => $response->header('X-Sendfile', $path),
                'x-accel' => $response->header('X-Accel-Redirect', self::$accelPrefix . $relative),
                'litespeed' => $response->header('X-LiteSpeed-Location', self::$accelPrefix . $relative),
                default => null,
            };
            return $response;
        }

        $start = 0;
        $end = $size - 1;
        $status = 200;
        $range = $request->header('range');
        if ($range !== null && $size > 0) {
            // One range only; a multi-range request is answered whole.
            if (preg_match('/^bytes=(\d*)-(\d*)$/', trim($range), $m) && ($m[1] !== '' || $m[2] !== '')) {
                if ($m[1] === '') {
                    $start = max(0, $size - (int) $m[2]);
                } else {
                    $start = (int) $m[1];
                    $end = $m[2] === '' ? $size - 1 : min((int) $m[2], $size - 1);
                }
                if ($start > $end || $start >= $size) {
                    return new Response(416, '', $base + ['Content-Range' => "bytes */$size"]);
                }
                $status = 206;
                $base['Content-Range'] = "bytes $start-$end/$size";
            }
        }
        $length = $size === 0 ? 0 : $end - $start + 1;
        $base['Content-Length'] = (string) $length;

        if ($request->method === 'HEAD') {
            return new Response($status, '', $base);
        }
        return Response::stream(static function () use ($path, $start, $length): void {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            $handle = fopen($path, 'rb');
            if ($handle === false) {
                return;
            }
            fseek($handle, $start);
            $left = $length;
            while ($left > 0 && !feof($handle) && connection_status() === CONNECTION_NORMAL) {
                $chunk = fread($handle, (int) min(self::CHUNK, $left));
                if ($chunk === false || $chunk === '') {
                    break;
                }
                echo $chunk;
                flush();
                $left -= strlen($chunk);
            }
            fclose($handle);
        }, $status, $base);
    }

    public static function etagMatches(string $header, string $etag): bool
    {
        if (trim($header) === '*') {
            return true;
        }
        $bare = fn (string $tag) => preg_replace('/^W\//', '', trim($tag));
        foreach (explode(',', $header) as $candidate) {
            if ($bare($candidate) === $bare($etag)) {
                return true;
            }
        }
        return false;
    }
}

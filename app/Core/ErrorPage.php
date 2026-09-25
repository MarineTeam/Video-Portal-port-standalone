<?php

declare(strict_types=1);

namespace App\Core;

/**
 * The one plain page a visitor sees when something goes wrong. It depends on
 * nothing — no database, no theme, no plugin — so it can render when any of
 * those is the thing that failed.
 */
final class ErrorPage
{
    public static function render(int $status, ?string $detail = null): Response
    {
        $title = match ($status) {
            404 => 'Page not found',
            403 => 'Not allowed',
            503 => 'Down for maintenance',
            default => 'Something went wrong',
        };
        $message = match ($status) {
            404 => 'There is nothing at this address.',
            403 => 'You don’t have access to this page.',
            503 => 'The site is being updated. Please try again in a few minutes.',
            default => 'Sorry — this page couldn’t be shown. The problem has been logged.',
        };
        $home = htmlspecialchars(Url::to('/'), ENT_QUOTES);
        $extra = $detail === null ? '' : '<pre style="white-space:pre-wrap;font-size:12px;overflow:auto;background:#f4f4f5;padding:12px;border-radius:8px">'
            . htmlspecialchars($detail, ENT_QUOTES) . '</pre>';
        $html = <<<HTML
            <!doctype html>
            <html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
            <meta name="robots" content="noindex"><title>{$title}</title>
            <style>body{font-family:system-ui,sans-serif;max-width:40rem;margin:4rem auto;padding:0 16px;color:#18181b}
            @media (prefers-color-scheme:dark){body{background:#09090b;color:#fafafa}a{color:#38bdf8}}</style></head>
            <body><h1>{$title}</h1><p>{$message}</p><p><a href="{$home}">Go to the home page</a></p>{$extra}</body></html>
            HTML;
        $response = Response::html($html, $status);
        if ($status === 503) {
            $response->header('Retry-After', '300');
        }
        return $response;
    }
}

<?php

declare(strict_types=1);

/*
 * The handful of helpers every template has in scope. They are functions
 * rather than methods so a theme author writes <?= e($title) ?> and nothing
 * longer.
 */

use App\Core\Url;
use App\Core\View;

if (!function_exists('e')) {
    /** Escapes for HTML text and attributes. The default for every output. */
    function e(mixed $value): string
    {
        return View::escape($value);
    }
}

if (!function_exists('url')) {
    /** A URL under the site's base path. */
    function url(string $path = '/', array $query = []): string
    {
        return Url::to($path, $query);
    }
}

if (!function_exists('asset')) {
    /** A static asset under public/assets, versioned by modification time. */
    function asset(string $path): string
    {
        $file = dirname(__DIR__) . '/public/assets/' . ltrim($path, '/');
        $v = is_file($file) ? (string) filemtime($file) : '0';
        return Url::to('/assets/' . ltrim($path, '/'), ['v' => $v]);
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . View::escape(\App\Core\CsrfToken::current()) . '">';
    }
}

if (!function_exists('t')) {
    /** A translated string from the active catalogue, with {placeholders} filled. */
    function t(string $key, array $vars = []): string
    {
        return \App\Modules\I18n\I18n::t($key, $vars);
    }
}

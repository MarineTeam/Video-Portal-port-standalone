<?php

declare(strict_types=1);

namespace App\Modules\Site;

final class Shell
{
    /**
     * The blocking script inlined before first paint so the page never
     * flashes the wrong theme — the same text as THEME_INIT_SCRIPT in
     * public/assets/js/device-settings.js (a test keeps them identical).
     */
    public const THEME_INIT_SCRIPT = '(function(){try{var raw=localStorage.getItem("marine-device-settings");'
        . 'var theme=raw?(JSON.parse(raw)||{}).theme:"system";'
        . 'if(theme!=="light"&&theme!=="dark")theme="system";'
        . 'var dark=theme==="dark"||(theme==="system"&&window.matchMedia("(prefers-color-scheme: dark)").matches);'
        . 'document.documentElement.classList.add(dark?"dark":"light");}catch(e){}})();';

    /**
     * Whether a nav link is the current section: a segment boundary, Home
     * only on Home, and an overview ($exact) only on itself — /profile stays
     * dark while /profile/inbox is lit.
     */
    public static function isActivePath(string $href, string $path, bool $exact = false): bool
    {
        if ($href === '/' || $exact) {
            return rtrim($path, '/') === rtrim($href, '/') || ($href === '/' && $path === '/');
        }
        $href = rtrim($href, '/');
        return $path === $href || str_starts_with($path, $href . '/');
    }
}

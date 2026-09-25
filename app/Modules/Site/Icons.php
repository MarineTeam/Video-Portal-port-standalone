<?php

declare(strict_types=1);

namespace App\Modules\Site;

/**
 * The app's icon set: 24-unit box, 1.75 stroke — the same paths the offline
 * shell (offline.html) carries, so the bottom bar looks identical with and
 * without a connection.
 */
final class Icons
{
    public const PATHS = [
        'home' => '<path d="M3 11.5 12 4l9 7.5"/><path d="M5.5 9.5V19a1 1 0 0 0 1 1H9a1 1 0 0 0 1-1v-4a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v4a1 1 0 0 0 1 1h2.5a1 1 0 0 0 1-1V9.5"/>',
        'search' => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.9-3.9"/>',
        'clock' => '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>',
        'history' => '<path d="M4 12a8 8 0 1 0 2.3-5.6"/><path d="M4 4.5V8h3.5"/><path d="M12 8v4l3 2"/>',
        'star' => '<path d="M12 4.5l2.4 4.9 5.4.8-3.9 3.8.9 5.4-4.8-2.5-4.8 2.5.9-5.4-3.9-3.8 5.4-.8L12 4.5z"/>',
        'sparkle' => '<path d="M11 4l1.2 3.8L16 9l-3.8 1.2L11 14l-1.2-3.8L6 9l3.8-1.2L11 4z"/><path d="M17.5 14.5l.7 2.1 2.1.7-2.1.7-.7 2.1-.7-2.1-2.1-.7 2.1-.7.7-2.1z"/>',
        'bell' => '<path d="M18 8.5a6 6 0 1 0-12 0c0 6-2.5 8-2.5 8h17s-2.5-2-2.5-8"/><path d="M13.7 20.5a2 2 0 0 1-3.4 0"/>',
        'folder' => '<path d="M3.5 7.5a2 2 0 0 1 2-2h3.2a2 2 0 0 1 1.5.7l1 1.3h7.3a2 2 0 0 1 2 2v7a2 2 0 0 1-2 2h-13a2 2 0 0 1-2-2z"/>',
        'playlist' => '<path d="M4 7h11M4 12h11M4 17h7"/><path d="m17 12.5 4 2.5-4 2.5z"/>',
        'book' => '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 3.5H20v17H6.5A2.5 2.5 0 0 1 4 18V6a2.5 2.5 0 0 1 2.5-2.5z"/>',
        'live' => '<circle cx="12" cy="12" r="2.5"/><path d="M8.2 15.8a5.4 5.4 0 0 1 0-7.6M15.8 8.2a5.4 5.4 0 0 1 0 7.6"/><path d="M5.5 18.5a9.2 9.2 0 0 1 0-13M18.5 5.5a9.2 9.2 0 0 1 0 13"/>',
        'person' => '<circle cx="12" cy="8.5" r="3.5"/><path d="M5 20c0-3.3 3.1-6 7-6s7 2.7 7 6"/>',
        'calendar' => '<rect x="3.5" y="5.5" width="17" height="15" rx="2"/><path d="M3.5 10h17M8 3.5v4M16 3.5v4"/>',
        'ticket' => '<path d="M3.5 8.5V6.5a1 1 0 0 1 1-1h15a1 1 0 0 1 1 1v2a2.5 2.5 0 0 0 0 7v2a1 1 0 0 1-1 1h-15a1 1 0 0 1-1-1v-2a2.5 2.5 0 0 0 0-7z"/><path d="M14 5.5v13"/>',
        'card' => '<rect x="3.5" y="5" width="17" height="14" rx="2"/><path d="M7.5 10h5M7.5 14h9"/>',
        'hands' => '<path d="M12 20.5c-3 0-5.5-2-6.5-4.5L4 12a1.5 1.5 0 0 1 2.6-1.4L8 12V5a1.5 1.5 0 0 1 3 0v5"/><path d="M12 20.5c3 0 5.5-2 6.5-4.5L20 12a1.5 1.5 0 0 0-2.6-1.4L16 12V5a1.5 1.5 0 0 0-3 0v5"/>',
        'people' => '<circle cx="9" cy="8" r="3.25"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M16 5.5a3.25 3.25 0 0 1 0 6.5M17 14.5a6.5 6.5 0 0 1 4.5 5.5"/>',
        'tv' => '<rect x="2.5" y="4.5" width="19" height="13" rx="2"/><path d="M8 20.5h8"/>',
        'download' => '<path d="M12 4v10"/><path d="m8 10.5 4 4 4-4"/><path d="M4.5 18.5h15"/>',
        'shield' => '<path d="M12 3.5 5 6v5.5c0 4.3 2.9 7.6 7 9 4.1-1.4 7-4.7 7-9V6z"/>',
    ];

    public static function svg(string $name, string $class = 'icon'): string
    {
        $paths = self::PATHS[$name] ?? self::PATHS['folder'];
        return '<svg class="' . htmlspecialchars($class, ENT_QUOTES) . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $paths . '</svg>';
    }
}

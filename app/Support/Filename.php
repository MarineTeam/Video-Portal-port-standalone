<?php

declare(strict_types=1);

namespace App\Support;

final class Filename
{
    /**
     * A title pre-filled from a picked file's name: only the last extension
     * goes. Underscores and capitalisation stay — reformatting them would be
     * guessing at what somebody meant to call it.
     */
    public static function titleFromFilename(string $name): string
    {
        $name = trim($name);
        $name = (string) preg_replace('#^.*[/\\\\]#', '', $name);
        $dot = strrpos($name, '.');
        if ($dot !== false && $dot > 0) {
            $name = substr($name, 0, $dot);
        }
        return trim($name);
    }
}

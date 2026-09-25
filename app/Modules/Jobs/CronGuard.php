<?php

declare(strict_types=1);

namespace App\Modules\Jobs;

/**
 * Scheduled jobs fail closed. No token configured is a 503 that runs nothing;
 * a wrong one is a 401, compared in constant time. Never "if (token) check" —
 * the original's version of that ran every job for anybody on a deployment
 * that forgot to set the secret.
 */
final class CronGuard
{
    /** @return 'ok'|'unconfigured'|'unauthorized' */
    public static function verdict(?string $configured, ?string $given): string
    {
        if ($configured === null || trim($configured) === '') {
            return 'unconfigured';
        }
        if ($given === null || $given === '') {
            return 'unauthorized';
        }
        // Hashing first makes the comparison constant-time whatever the lengths.
        return hash_equals(hash('sha256', $configured), hash('sha256', $given)) ? 'ok' : 'unauthorized';
    }
}

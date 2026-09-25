<?php

declare(strict_types=1);

namespace App\Modules\Jobs;

use App\Core\App;
use App\Core\Cache;
use App\Core\RateLimiter;
use App\Core\Session;
use App\Modules\Access\AccessAttempts;
use App\Modules\Access\AuthTokens;

/** The scheduler with the core's own jobs; plugins add theirs through jobs.register. */
final class Jobs
{
    public static function scheduler(App $app): Scheduler
    {
        return Cache::memo('scheduler', function () use ($app) {
            $s = new Scheduler($app->db());
            $s->register('core.prune', 86400, function () use ($app): string {
                $db = $app->db();
                $n = Session::prune($db) + AuthTokens::prune($db) + AccessAttempts::prune($db) + RateLimiter::prune($db);
                // The view throttle's key is a throttle, not a record: blank it after a day.
                $n += $db->run('UPDATE {{view_events}} SET ip_hash = NULL WHERE ip_hash IS NOT NULL AND created_at < ?', [\App\Core\Db::datetime(new \DateTimeImmutable('-1 day'))])->rowCount();
                $n += \App\Modules\Uploads\Uploads::sweep($app);
                return "pruned $n";
            }, 15.0, '03:10');
            $app->hooks->do('jobs.register', $s, $app);
            return $s;
        });
    }
}

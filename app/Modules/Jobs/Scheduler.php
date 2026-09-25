<?php

declare(strict_types=1);

namespace App\Modules\Jobs;

use App\Core\Db;
use App\Core\Id;
use App\Core\Log;

/**
 * Named jobs with an interval and a time budget, driven by /cron/run — from a
 * real cron line the installer printed, or, when no real cron has been seen
 * for fifteen minutes, from a loopback request fired at the end of a page view.
 *
 * A job is claimed with a conditional UPDATE on lock_until, so two triggers
 * arriving together never run it twice. A job stops starting new work once
 * its budget is spent and records how far it got; the next run carries on.
 */
final class Scheduler
{
    /** The whole run's budget: a request is killed at 30s on a cautious host. */
    public const RUN_BUDGET = 20.0;

    /** @var array<string, array{interval: int, fn: callable(float): string, budget: float, at: ?string}> */
    private array $jobs = [];

    public function __construct(private readonly Db $db)
    {
    }

    /**
     * @param callable(float $deadline): string $fn does its work until microtime(true) passes $deadline;
     *        returns a short status for the admin page
     * @param ?string $at "HH:MM" UTC for a daily job that wants a particular time
     */
    public function register(string $name, int $intervalSeconds, callable $fn, float $budget = 15.0, ?string $at = null): void
    {
        if (!preg_match('/^[a-z0-9:_.-]{1,191}$/', $name)) {
            throw new \InvalidArgumentException("Bad job name $name");
        }
        $this->jobs[$name] = ['interval' => max(60, $intervalSeconds), 'fn' => $fn, 'budget' => $budget, 'at' => $at];
    }

    /** @return array<string, array{interval: int, fn: callable(float): string, budget: float, at: ?string}> */
    public function jobs(): array
    {
        return $this->jobs;
    }

    /** Makes sure every registered job has a row. */
    public function sync(): void
    {
        foreach ($this->jobs as $name => $job) {
            $this->db->run(
                'INSERT INTO {{jobs}} (name, interval_seconds, next_run_at) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE interval_seconds = VALUES(interval_seconds)',
                [$name, $job['interval'], Db::datetime(self::firstRun($job['at']))],
            );
        }
    }

    public function anyDue(): bool
    {
        if ($this->jobs === []) {
            return false;
        }
        $names = array_keys($this->jobs);
        $in = implode(',', array_fill(0, count($names), '?'));
        return (int) $this->db->value(
            "SELECT COUNT(*) FROM {{jobs}} WHERE name IN ($in) AND next_run_at <= ? AND (lock_until IS NULL OR lock_until < ?)",
            [...$names, Db::now(), Db::now()],
        ) > 0;
    }

    /**
     * Runs due jobs until the run's budget is spent.
     *
     * @param ?string $only run just this job, due or not (Run now)
     * @return list<array{name: string, status: string}>
     */
    public function run(?string $only = null): array
    {
        $this->sync();
        $started = microtime(true);
        $done = [];
        $due = $only !== null
            ? [$only]
            : $this->db->column('SELECT name FROM {{jobs}} WHERE next_run_at <= ? ORDER BY next_run_at', [Db::now()]);
        foreach ($due as $name) {
            $job = $this->jobs[$name] ?? null;
            if ($job === null) {
                continue;
            }
            $left = self::RUN_BUDGET - (microtime(true) - $started);
            if ($left < 2) {
                break;
            }
            $owner = Id::random(12);
            $claimed = $this->db->run(
                'UPDATE {{jobs}} SET lock_until = ?, lock_owner = ? WHERE name = ? AND (lock_until IS NULL OR lock_until < ?)',
                [Db::datetime(new \DateTimeImmutable('+120 seconds')), $owner, $name, Db::now()],
            )->rowCount();
            if ($claimed !== 1) {
                continue;
            }
            $deadline = microtime(true) + min($job['budget'], $left);
            try {
                $status = 'ok';
                $message = ($job['fn'])($deadline);
                $error = null;
            } catch (\Throwable $e) {
                $status = 'failed';
                $message = 'failed';
                $error = mb_substr($e->getMessage(), 0, 2000);
                Log::error("Job $name failed: " . $e->getMessage(), ['job' => $name]);
            }
            $this->db->run(
                'UPDATE {{jobs}} SET last_run_at = ?, last_status = ?, last_error = ?, next_run_at = ?, lock_until = NULL, lock_owner = NULL WHERE name = ? AND lock_owner = ?',
                [Db::now(), $status === 'ok' ? mb_substr((string) $message, 0, 32) : 'failed', $error, Db::datetime(self::nextRun($job['interval'], $job['at'])), $name, $owner],
            );
            $done[] = ['name' => $name, 'status' => $status === 'ok' ? (string) $message : 'failed: ' . $error];
        }
        return $done;
    }

    public static function firstRun(?string $at): \DateTimeImmutable
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        if ($at === null) {
            return $now;
        }
        [$h, $m] = array_map('intval', explode(':', $at));
        $today = $now->setTime($h, $m);
        return $today > $now ? $today : $today->modify('+1 day');
    }

    public static function nextRun(int $interval, ?string $at): \DateTimeImmutable
    {
        return $at !== null ? self::firstRun($at) : new \DateTimeImmutable("+$interval seconds", new \DateTimeZone('UTC'));
    }
}

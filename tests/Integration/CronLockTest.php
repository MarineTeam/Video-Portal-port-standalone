<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\App;
use App\Core\Cache;
use App\Core\Db;
use App\Core\Migrator;
use App\Core\Paths;
use App\Modules\Jobs\Scheduler;

/**
 * Two callers arriving at the same job at the same moment.
 *
 * A church site with no real cron runs its jobs off page views, so on a
 * Sunday morning several people load the home page in the same second. If
 * that started the same job twice, a broadcast goes out twice and a paid
 * transcription is paid for twice. The claim is a conditional UPDATE, which
 * is the only thing that decides — a read that checks and then writes is
 * two callers both seeing "free".
 */
final class CronLockTest extends DatabaseTestCase
{
    private const PREFIX = 'cron_';
    private static ?Db $db = null;
    private static ?App $app = null;
    private static string $storage = '';

    public static function setUpBeforeClass(): void
    {
        $name = getenv('MT_TEST_DB_NAME');
        if (!is_string($name) || $name === '') {
            return;
        }
        $db = self::connect(self::PREFIX);
        self::dropPrefix($db, self::PREFIX);
        (new Migrator($db, dirname(__DIR__, 2) . '/app/Migrations'))->runAll();
        self::$db = $db;
        self::$storage = sys_get_temp_dir() . '/mt-cron-' . bin2hex(random_bytes(4));
        mkdir(self::$storage . '/tmp', 0777, true);
        $root = dirname(__DIR__, 2);
        self::$app = new App(new Paths($root, self::$storage, "$root/plugins", "$root/themes"));
        self::$app->config = ['database' => self::dbConfig(self::PREFIX)];
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            self::dropPrefix(self::$db, self::PREFIX);
            \App\Modules\Plugins\PackageInstaller::removeTree(self::$storage);
        }
    }

    protected function setUp(): void
    {
        if (self::$db === null) {
            self::markTestSkipped('MT_TEST_DB_NAME is not set.');
        }
        Cache::forgetMemo();
        self::$db->run('DELETE FROM {{jobs}}');
        self::$db->run('DELETE FROM {{settings}}');
    }

    /** A scheduler holding one job that counts how often it is run. */
    private function scheduler(Db $db, callable $work): Scheduler
    {
        $scheduler = new Scheduler($db);
        $scheduler->register('test-job', 3600, function (float $deadline) use ($work): string {
            $work();
            return 'ran';
        }, 5.0);
        return $scheduler;
    }

    public function test_1_two_schedulers_racing_run_the_job_once(): void
    {
        $runs = 0;
        // Two connections, as two requests would be: the claim has to be
        // decided by the database, not by anything either one remembers.
        $first = $this->scheduler(self::connect(self::PREFIX), function () use (&$runs): void { $runs++; });
        $second = $this->scheduler(self::connect(self::PREFIX), function () use (&$runs): void { $runs++; });
        $first->sync();
        $second->sync();

        $first->run();
        $second->run();

        self::assertSame(1, $runs, 'the second caller found it already claimed and done');
    }

    public function test_2_a_job_is_not_due_again_until_its_interval_has_passed(): void
    {
        $runs = 0;
        $scheduler = $this->scheduler(self::$db, function () use (&$runs): void { $runs++; });
        $scheduler->sync();

        $scheduler->run();
        $scheduler->run();
        $scheduler->run();

        self::assertSame(1, $runs, 'an hourly job called three times in a second runs once');
        self::assertFalse($scheduler->anyDue());
    }

    public function test_3_a_lock_that_outlives_its_holder_is_taken_over(): void
    {
        // A request killed mid-job leaves the row locked. The lock has an
        // end, so the job is not stuck for ever — which is the other half of
        // the rule and the one that bites two days later.
        $runs = 0;
        $scheduler = $this->scheduler(self::$db, function () use (&$runs): void { $runs++; });
        $scheduler->sync();
        self::$db->update('jobs', [
            'lock_until' => Db::datetime(new \DateTimeImmutable('-1 second')),
            'lock_owner' => 'a request that died',
        ], ['name' => 'test-job']);

        $scheduler->run();
        self::assertSame(1, $runs);
        self::assertNull(self::$db->value('SELECT lock_until FROM {{jobs}} WHERE name = ?', ['test-job']), 'and the lock is let go afterwards');
    }

    public function test_4_a_live_lock_is_left_alone(): void
    {
        $runs = 0;
        $scheduler = $this->scheduler(self::$db, function () use (&$runs): void { $runs++; });
        $scheduler->sync();
        self::$db->update('jobs', [
            'lock_until' => Db::datetime(new \DateTimeImmutable('+60 seconds')),
            'lock_owner' => 'somebody else',
        ], ['name' => 'test-job']);

        $scheduler->run();
        self::assertSame(0, $runs, 'a job somebody is running is not started again');
    }

    public function test_5_a_job_that_throws_is_recorded_and_unlocked(): void
    {
        $scheduler = new Scheduler(self::$db);
        $scheduler->register('test-job', 3600, function (): string {
            throw new \RuntimeException('the service refused');
        }, 5.0);
        $scheduler->sync();
        $scheduler->run();

        $row = self::$db->one('SELECT last_status, last_error, lock_until FROM {{jobs}} WHERE name = ?', ['test-job']);
        self::assertSame('failed', $row['last_status']);
        self::assertStringContainsString('the service refused', (string) $row['last_error']);
        self::assertNull($row['lock_until'], 'a job that failed is not a job that is still running');
    }

    public function test_6_the_page_view_trigger_fires_at_most_once_a_minute(): void
    {
        // The claim is one atomic INSERT ... ON DUPLICATE KEY UPDATE, so of
        // several page views in the same second exactly one wins.
        $claim = static function (Db $db): int {
            return $db->run(
                'INSERT INTO {{settings}} (name, value) VALUES (\'cron.pageview_at\', ?)
                 ON DUPLICATE KEY UPDATE value = IF(CAST(JSON_UNQUOTE(value) AS UNSIGNED) < ?, VALUES(value), value)',
                [json_encode(time()), time() - 60],
            )->rowCount();
        };

        $won = 0;
        for ($i = 0; $i < 8; $i++) {
            $won += $claim(self::connect(self::PREFIX)) > 0 ? 1 : 0;
        }
        self::assertSame(1, $won, 'eight page views in the same second, one trigger');

        // A minute later the next one is allowed through.
        self::$db->run('UPDATE {{settings}} SET value = ? WHERE name = ?', [json_encode(time() - 61), 'cron.pageview_at']);
        self::assertGreaterThan(0, $claim(self::$db));
    }
}

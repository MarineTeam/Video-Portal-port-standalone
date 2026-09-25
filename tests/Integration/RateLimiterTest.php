<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Migrator;
use App\Core\RateLimiter;
use PHPUnit\Framework\Attributes\TestDox;

final class RateLimiterTest extends DatabaseTestCase
{
    #[TestDox('under 30-way concurrency the limiter says yes exactly as many times as were left')]
    public function testConcurrent(): void
    {
        if (!function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl is needed to fork concurrent clients.');
        }
        $db = self::connect('rl_');
        self::dropPrefix($db, 'rl_');
        (new Migrator($db, dirname(__DIR__, 2) . '/app/Migrations'))->runAll();
        $bucket = RateLimiter::bucket('test', 'concurrency');
        $results = sys_get_temp_dir() . '/mt-rl-' . getmypid();
        @unlink($results);
        // A forked child would share (and on exit, close) the parent's socket.
        $db = null;
        gc_collect_cycles();
        $children = [];
        for ($i = 0; $i < 30; $i++) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                try {
                    $mine = self::connect('rl_');
                    $ok = (new RateLimiter($mine))->hit($bucket, 10, 60);
                    file_put_contents($results, $ok ? "1\n" : "0\n", FILE_APPEND | LOCK_EX);
                } catch (\Throwable $e) {
                    file_put_contents($results, 'E ' . $e->getMessage() . "\n", FILE_APPEND | LOCK_EX);
                }
                // Leave without running the parent's shutdown handlers.
                posix_kill(posix_getpid(), SIGKILL);
            }
            $children[] = $pid;
        }
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
        }
        $answers = file($results, FILE_IGNORE_NEW_LINES) ?: [];
        @unlink($results);
        self::assertCount(30, $answers);
        self::assertSame([], array_values(array_filter($answers, fn ($a) => str_starts_with($a, 'E'))));
        self::assertSame(10, count(array_filter($answers, fn ($a) => $a === '1')));
        self::dropPrefix(self::connect('rl_'), 'rl_');
    }

    #[TestDox('failures back off exponentially after the free allowance')]
    public function testBackoff(): void
    {
        $db = self::connect('rl_');
        self::dropPrefix($db, 'rl_');
        (new Migrator($db, dirname(__DIR__, 2) . '/app/Migrations'))->runAll();
        $limiter = new RateLimiter($db);
        $bucket = RateLimiter::bucket('login-account', 'a@b.test');
        $waits = [];
        for ($i = 0; $i < 8; $i++) {
            $waits[] = $limiter->fail($bucket, free: 5, base: 30);
        }
        self::assertSame([0, 0, 0, 0, 0, 30, 60, 120], $waits);
        self::assertGreaterThan(100, $limiter->blocked($bucket));
        $limiter->clear($bucket);
        self::assertSame(0, $limiter->blocked($bucket));
        self::dropPrefix($db, 'rl_');
    }
}

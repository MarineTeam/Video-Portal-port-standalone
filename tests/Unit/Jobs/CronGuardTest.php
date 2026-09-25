<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Modules\Jobs\CronGuard;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * lib/cron-guard.test.ts. The original stayed open in development with no
 * secret; the port has no development exception (see PORT_MAP Deviations):
 * no token configured is always a 503 that runs nothing.
 */
final class CronGuardTest extends TestCase
{
    #[TestDox('lets the right bearer token through')]
    public function testRight(): void
    {
        self::assertSame('ok', CronGuard::verdict('s3cret-token', 's3cret-token'));
    }

    #[TestDox('refuses a wrong token, a missing header, and a token of another length')]
    public function testWrong(): void
    {
        self::assertSame('unauthorized', CronGuard::verdict('s3cret-token', 's3cret-tokeN'));
        self::assertSame('unauthorized', CronGuard::verdict('s3cret-token', null));
        self::assertSame('unauthorized', CronGuard::verdict('s3cret-token', 's3cret'));
    }

    #[TestDox('fails closed in production when no secret is configured')]
    public function testUnconfigured(): void
    {
        self::assertSame('unconfigured', CronGuard::verdict(null, 'anything'));
        self::assertSame('unconfigured', CronGuard::verdict('  ', null));
    }

    #[TestDox('fails closed everywhere when no secret is configured — the port has no development exception')]
    public function testNoDevException(): void
    {
        self::assertSame('unconfigured', CronGuard::verdict('', ''));
    }

    #[TestDox('still checks a secret that is set, in development too')]
    public function testChecksSet(): void
    {
        self::assertSame('unauthorized', CronGuard::verdict('x', 'y'));
    }
}

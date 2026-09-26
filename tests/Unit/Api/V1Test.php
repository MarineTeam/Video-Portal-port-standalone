<?php

declare(strict_types=1);

namespace Tests\Unit\Api;

use App\Core\Request;
use App\Modules\Api\V1;
use PHPUnit\Framework\TestCase;

/** lib/api-v1.test.ts */
final class V1Test extends TestCase
{
    private function get(array $query): Request
    {
        return new Request('GET', '/api/v1/videos', $query);
    }

    public function testOkWrapsRowsInOneEnvelope(): void
    {
        $response = V1::ok([['id' => 'a'], ['id' => 'b']]);
        $body = json_decode($response->body, true);
        $this->assertSame(['data' => [['id' => 'a'], ['id' => 'b']]], $body);
        $this->assertSame(200, $response->status);
    }

    public function testAnAnswerIsNeverCachedBySomethingShared(): void
    {
        $headers = V1::ok([])->headers;
        $this->assertStringContainsString('private', (string) ($headers['Cache-Control'] ?? ''));
        $this->assertStringContainsString('no-store', (string) ($headers['Cache-Control'] ?? ''));
    }

    public function testNextCursorIsLeftOutOnTheLastPageRatherThanSentAsNull(): void
    {
        $this->assertArrayNotHasKey('nextCursor', json_decode(V1::ok([['id' => 'a']])->body, true));
        $this->assertSame('c1', json_decode(V1::ok([['id' => 'a']], 'c1')->body, true)['nextCursor']);
    }

    public function testItRefusesToAnswerWithACredentialInIt(): void
    {
        // The same guard the data export uses, from the same list: a query
        // that quietly changed shape is a leak, so it throws rather than
        // filtering.
        $this->expectException(\LogicException::class);
        V1::ok([['id' => 'a', 'user' => ['email' => 'x@y.z', 'passwordHash' => '$2y$...']]]);
    }

    public function testFailHasOneShapeWithACodeAProgramCanBranchOn(): void
    {
        $response = V1::fail('forbidden', 'This key does not hold that scope.', 403);
        $this->assertSame(403, $response->status);
        $this->assertSame(
            ['error' => ['code' => 'forbidden', 'message' => 'This key does not hold that scope.']],
            json_decode($response->body, true),
        );
    }

    public function testRetryAfterIsSentOnlyWhenThereIsSomethingToWaitFor(): void
    {
        $this->assertArrayNotHasKey('Retry-After', V1::fail('forbidden', 'no', 403)->headers);
        $this->assertSame('17', V1::fail('rate_limited', 'slow down', 429, 17)->headers['Retry-After']);
    }

    public function testPagingAsksForOneRowMoreThanThePage(): void
    {
        // Which is how "is there another page" gets answered without a COUNT
        // over the whole table.
        $paging = V1::paging($this->get(['limit' => '10']));
        $this->assertSame(10, $paging['limit']);
        $this->assertSame(11, $paging['fetch']);
    }

    public function testPagingReadsLimitAndCursorOffTheQueryString(): void
    {
        $paging = V1::paging($this->get(['limit' => '5', 'cursor' => '2026-01-01|abc']));
        $this->assertSame(5, $paging['limit']);
        $this->assertSame('2026-01-01|abc', $paging['cursor']);
        $this->assertNull(V1::paging($this->get([]))['cursor']);
        $this->assertSame(50, V1::paging($this->get([]))['limit']);
    }

    public function testThePageAndTheCursorComeBackWhenThereIsMore(): void
    {
        $rows = [['id' => 'a'], ['id' => 'b'], ['id' => 'c']];
        $page = V1::page($rows, 2, fn (array $row) => (string) $row['id']);
        $this->assertSame([['id' => 'a'], ['id' => 'b']], $page['rows']);
        $this->assertSame('b', $page['nextCursor'], 'the last row of this page, so the next starts after it');
    }

    public function testThereIsNoMoreWhenTheExtraRowDidNotComeBack(): void
    {
        $page = V1::page([['id' => 'a'], ['id' => 'b']], 2, fn (array $row) => (string) $row['id']);
        $this->assertCount(2, $page['rows']);
        $this->assertNull($page['nextCursor']);
        $this->assertNull(V1::page([], 10, fn (array $row) => 'x')['nextCursor']);
    }

    public function testUpdatedSinceReadsATimestamp(): void
    {
        $this->assertSame('2026-03-08 10:30:00.000', V1::since($this->get(['updatedSince' => '2026-03-08T10:30:00Z'])));
        $this->assertSame('2026-03-08 00:00:00.000', V1::since($this->get(['updatedSince' => '2026-03-08'])));
    }

    public function testItIgnoresATimestampItCannotReadRatherThanRefusingTheRequest(): void
    {
        // A sync job with a corrupt bookmark should get the whole list and
        // carry on, not a 400 it retries for ever.
        $this->assertNull(V1::since($this->get(['updatedSince' => 'whenever'])));
        $this->assertNull(V1::since($this->get([])));
        $this->assertSame('2026-03-08 00:00:00.000', V1::since($this->get(['addedSince' => '2026-03-08']), 'addedSince'));
    }
}

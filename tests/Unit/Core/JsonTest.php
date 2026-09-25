<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Json;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

final class JsonTest extends TestCase
{
    #[TestDox('reads every table\'s column types from the schema')]
    public function testTypes(): void
    {
        $types = Json::types();
        // Join tables of plain strings (series_tags…) have no typed columns.
        self::assertGreaterThanOrEqual(95, count($types));
        self::assertSame('bool', $types['series']['member_only']);
        self::assertSame('datetime', $types['series']['publish_at']);
        self::assertSame('date', $types['calendar_events']['date']);
        self::assertSame('json', $types['series']['tags']);
        self::assertSame('int', $types['series']['position']);
    }

    #[TestDox('presents a row the way the original API did: camelCase, real booleans, ISO instants with milliseconds')]
    public function testRow(): void
    {
        $row = Json::row('series', [
            'id' => 'c1', 'member_only' => 1, 'publish_at' => '2026-07-10 09:30:00.000', 'tags' => '["a","b"]',
            'position' => 3, 'category_id' => null,
        ]);
        self::assertSame(['id' => 'c1', 'memberOnly' => true, 'publishAt' => '2026-07-10T09:30:00.000Z', 'tags' => ['a', 'b'], 'position' => 3, 'categoryId' => null], $row);
    }

    #[TestDox('never sends an omitted column')]
    public function testOmit(): void
    {
        self::assertArrayNotHasKey('passwordHash', Json::row('users', ['id' => 'u', 'password_hash' => 'x'], ['password_hash']));
    }
}

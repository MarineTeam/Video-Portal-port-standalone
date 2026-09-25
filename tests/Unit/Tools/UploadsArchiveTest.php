<?php

declare(strict_types=1);

namespace Tests\Unit\Tools;

use App\Modules\Tools\UploadsArchive;
use PHPUnit\Framework\TestCase;

final class UploadsArchiveTest extends TestCase
{
    public function test_files_are_grouped_in_order_up_to_the_part_size(): void
    {
        $parts = UploadsArchive::partition(['a' => 40, 'b' => 40, 'c' => 40, 'd' => 10], 100);
        $this->assertSame([['files' => ['a', 'b'], 'bytes' => 80], ['files' => ['c', 'd'], 'bytes' => 50]], $parts);
    }

    public function test_a_file_bigger_than_a_part_is_a_part_of_its_own(): void
    {
        $parts = UploadsArchive::partition(['a' => 10, 'big' => 500, 'c' => 10], 100);
        $this->assertSame([['a'], ['big'], ['c']], array_column($parts, 'files'));
    }

    public function test_no_files_no_parts(): void
    {
        $this->assertSame([], UploadsArchive::partition([], 100));
    }
}

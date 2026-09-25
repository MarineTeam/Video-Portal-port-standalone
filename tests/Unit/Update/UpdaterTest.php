<?php

declare(strict_types=1);

namespace Tests\Unit\Update;

use App\Modules\Update\Updater;
use PHPUnit\Framework\TestCase;

final class UpdaterTest extends TestCase
{
    public function test_during_maintenance_only_the_administrator_doing_it_uses_the_whole_site(): void
    {
        $m = ['by' => 'admin1', 'email' => 'a@x.test', 'at' => null, 'reason' => 'update'];
        $this->assertTrue(Updater::bypasses('/', $m, 'admin1', true));
        $this->assertTrue(Updater::bypasses('/api/videos', $m, 'admin1', true));
        $this->assertFalse(Updater::bypasses('/', $m, 'admin2', true), 'another administrator');
        $this->assertFalse(Updater::bypasses('/', $m, 'member', false));
        $this->assertFalse(Updater::bypasses('/', $m, null, false));
    }

    public function test_any_administrator_can_reach_the_update_page_to_take_over_and_anyone_the_sign_in_pages(): void
    {
        $m = ['by' => 'admin1', 'email' => null, 'at' => null, 'reason' => 'update'];
        $this->assertTrue(Updater::bypasses('/admin/update', $m, 'admin2', true));
        $this->assertTrue(Updater::bypasses('/api/admin/update/step', $m, 'admin2', true));
        $this->assertFalse(Updater::bypasses('/admin/update', $m, 'member', false));
        $this->assertFalse(Updater::bypasses('/admin/updates-elsewhere', $m, 'admin2', true));
        $this->assertTrue(Updater::bypasses('/auth/login', $m, null, false));
        $this->assertFalse(Updater::bypasses('/cron/run', $m, null, false));
    }

    public function test_a_maintenance_file_without_json_still_closes_the_site(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'mt');
        file_put_contents($file, '');
        $m = Updater::readMaintenance($file);
        unlink($file);
        $this->assertNotNull($m);
        $this->assertNull($m['by']);
        $this->assertFalse(Updater::bypasses('/', $m, 'admin1', true));
        $this->assertNull(Updater::readMaintenance($file));
    }
}

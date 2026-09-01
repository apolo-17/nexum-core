<?php

namespace Tests\Feature\Filament;

use App\Filament\Widgets\SystemNoticeBanner;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The one-time dashboard notice shows only to a super_admin who hasn't acknowledged it, and
 * disappears for good once they do (with the go-ahead timestamp kept as a record).
 */
class SystemNoticeBannerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('super_admin');

        return $user;
    }

    #[Test]
    public function a_super_admin_sees_it_until_acknowledged(): void
    {
        $this->actingAs($this->superAdmin());

        $this->assertTrue(SystemNoticeBanner::canView());

        $widget = new SystemNoticeBanner;
        $widget->acknowledge();

        $this->assertNotNull(auth()->user()->fresh()->system_notice_ack_at);
        $this->assertFalse(SystemNoticeBanner::canView());
    }

    #[Test]
    public function a_partner_never_sees_it(): void
    {
        $user = User::factory()->create();
        $user->assignRole('partner');
        $this->actingAs($user);

        $this->assertFalse(SystemNoticeBanner::canView());
    }
}

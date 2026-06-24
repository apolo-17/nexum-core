<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\UserResource;
use App\Models\User;
use App\Notifications\AccountInvitationNotification;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Feature tests for the user invitation flow and access control.
 *
 * Covers: super_admin-only access to UserResource, the welcome/activation
 * email being dispatched on invitation, and the email_verified_at activation
 * flag being set the first time a user sets their password.
 */
class UserInvitationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    // -------------------------------------------------------------------------
    // Access control
    // -------------------------------------------------------------------------

    #[Test]
    public function super_admin_can_access_the_user_resource(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');

        $this->actingAs($admin);

        $this->assertTrue(UserResource::canAccess());
    }

    #[Test]
    public function non_super_admin_cannot_access_the_user_resource(): void
    {
        $notario = User::factory()->create();
        $notario->assignRole('notario');

        $this->actingAs($notario);

        $this->assertFalse(UserResource::canAccess());
    }

    // -------------------------------------------------------------------------
    // Invitation email
    // -------------------------------------------------------------------------

    #[Test]
    public function inviting_a_user_sends_the_activation_email(): void
    {
        Notification::fake();

        $invitee = User::factory()->create(['email_verified_at' => null]);
        $invitee->assignRole('notario');

        UserResource::sendInvitation($invitee);

        Notification::assertSentTo($invitee, AccountInvitationNotification::class);
    }

    // -------------------------------------------------------------------------
    // Activation flag
    // -------------------------------------------------------------------------

    #[Test]
    public function setting_a_password_marks_a_pending_user_as_activated(): void
    {
        $invitee = User::factory()->create(['email_verified_at' => null]);

        event(new PasswordReset($invitee));

        $this->assertNotNull($invitee->fresh()->email_verified_at);
    }
}

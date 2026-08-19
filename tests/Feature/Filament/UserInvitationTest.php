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

    // -------------------------------------------------------------------------
    // Multi-role assignment
    // -------------------------------------------------------------------------

    #[Test]
    public function syncing_multiple_roles_assigns_all_of_them(): void
    {
        $user = User::factory()->create();

        UserResource::syncRolesAndSoldado($user, ['super_admin', 'soldado']);

        $fresh = $user->fresh();
        $this->assertTrue($fresh->hasRole('super_admin'));
        $this->assertTrue($fresh->hasRole('soldado'));
    }

    #[Test]
    public function assigning_the_soldado_role_creates_a_linked_soldado_profile(): void
    {
        $user = User::factory()->create();

        UserResource::syncRolesAndSoldado($user, ['super_admin', 'soldado']);

        $soldado = \App\Models\Soldado::where('user_id', $user->id)->first();

        $this->assertNotNull($soldado);
        $this->assertSame($user->email, $soldado->email);
        $this->assertTrue((bool) $soldado->available_for_mua);
    }

    #[Test]
    public function assigning_the_soldado_role_links_an_existing_soldado_by_email(): void
    {
        $user = User::factory()->create();
        $soldado = \App\Models\Soldado::create([
            'name' => 'Existente',
            'email' => $user->email,
        ]);

        UserResource::syncRolesAndSoldado($user, ['soldado']);

        $this->assertSame($user->id, $soldado->fresh()->user_id);
        $this->assertSame(1, \App\Models\Soldado::where('email', $user->email)->count());
    }

    #[Test]
    public function removing_the_soldado_role_keeps_the_profile_but_drops_the_role(): void
    {
        $user = User::factory()->create();
        UserResource::syncRolesAndSoldado($user, ['super_admin', 'soldado']);

        // Re-sync sin soldado: conserva super_admin y el perfil, quita el rol soldado.
        UserResource::syncRolesAndSoldado($user, ['super_admin']);

        $fresh = $user->fresh();
        $this->assertTrue($fresh->hasRole('super_admin'));
        $this->assertFalse($fresh->hasRole('soldado'));
        $this->assertNotNull(\App\Models\Soldado::where('user_id', $user->id)->first());
    }
}

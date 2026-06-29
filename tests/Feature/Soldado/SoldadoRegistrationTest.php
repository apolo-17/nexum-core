<?php

namespace Tests\Feature\Soldado;

use App\Filament\Resources\MiPerfilResource;
use App\Filament\Resources\MiPerfilResource\Pages\EditMiPerfil;
use App\Filament\Resources\SoldadoResource;
use App\Filament\Resources\SoldadoResource\Pages\CreateSoldado;
use App\Models\Soldado;
use App\Models\User;
use App\Notifications\AccountInvitationNotification;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * End-to-end tests for the soldado registration flow:
 *   1. super_admin registers (name + email + capabilities) → invitation sent
 *   2. soldado completes their own profile via "Mi perfil"
 */
class SoldadoRegistrationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Create the roles used by the flow.
     */
    private function seedRoles(): void
    {
        Role::findOrCreate('super_admin', 'web');
        Role::findOrCreate('soldado', 'web');
    }

    #[Test]
    public function super_admin_registers_a_soldado_with_minimal_data_and_an_invitation_is_sent(): void
    {
        $this->seedRoles();
        Notification::fake();

        $admin = User::factory()->create();
        $admin->assignRole('super_admin');
        $this->actingAs($admin);

        Livewire::test(CreateSoldado::class)
            ->fillForm([
                'name' => 'Juan Pérez',
                'email' => 'juan@soldados.mx',
                'available_for_mua' => true,
                'available_as_legal_representative' => true,
                'available_as_commissary' => false,
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $soldado = Soldado::where('email', 'juan@soldados.mx')->first();
        $this->assertNotNull($soldado);
        $this->assertTrue($soldado->available_for_mua);
        $this->assertTrue($soldado->available_as_legal_representative);

        // A linked user with the soldado role was created.
        $user = User::where('email', 'juan@soldados.mx')->first();
        $this->assertNotNull($user);
        $this->assertTrue($user->hasRole('soldado'));
        $this->assertSame($user->id, $soldado->user_id);

        // The welcome/invitation email was sent.
        Notification::assertSentTo($user, AccountInvitationNotification::class);
    }

    #[Test]
    public function a_soldado_completes_their_own_profile(): void
    {
        $this->seedRoles();

        $user = User::create(['name' => 'Juan', 'email' => 'juan@soldados.mx', 'password' => 'secret']);
        $user->assignRole('soldado');
        $soldado = Soldado::create([
            'name' => 'Juan',
            'email' => 'juan@soldados.mx',
            'is_active' => true,
            'user_id' => $user->id,
        ]);

        $this->actingAs($user);

        Livewire::test(EditMiPerfil::class, ['record' => $soldado->getRouteKey()])
            ->fillForm([
                'phone' => '5512345678',
                'rfc' => 'PEXJ800101AB1',
                'curp' => 'PEXJ800101HDFRXN09',
                'birthplace' => 'CDMX',
                'address' => 'Calle 1',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $soldado->refresh();
        $this->assertSame('5512345678', $soldado->phone);
        $this->assertSame('PEXJ800101AB1', $soldado->rfc);
        $this->assertSame('CDMX', $soldado->birthplace);
    }

    #[Test]
    public function an_invited_soldado_can_access_the_panel(): void
    {
        $this->seedRoles();
        Notification::fake();

        $soldado = Soldado::create(['name' => 'Inv', 'email' => 'inv@soldados.mx', 'is_active' => true]);
        SoldadoResource::grantAccess($soldado);

        $user = $soldado->refresh()->user;
        $this->assertNotNull($user);
        $this->assertTrue($user->canAccessPanel(Filament::getDefaultPanel()));
    }

    #[Test]
    public function a_user_linked_to_a_soldado_can_access_even_without_the_role(): void
    {
        // Simulates a role/guard hiccup: no role assigned, but a linked soldado profile.
        $user = User::create(['name' => 'NoRole', 'email' => 'norole@soldados.mx', 'password' => 'secret']);
        Soldado::create(['name' => 'NoRole', 'email' => 'norole@soldados.mx', 'is_active' => true, 'user_id' => $user->id]);

        $this->assertTrue($user->fresh()->canAccessPanel(Filament::getDefaultPanel()));
    }

    #[Test]
    public function mi_perfil_is_scoped_to_the_soldados_own_record(): void
    {
        $this->seedRoles();

        $user = User::create(['name' => 'Mio', 'email' => 'mio@soldados.mx', 'password' => 'secret']);
        $user->assignRole('soldado');
        $mine = Soldado::create(['name' => 'Mio', 'email' => 'mio@soldados.mx', 'is_active' => true, 'user_id' => $user->id]);

        $otherUser = User::create(['name' => 'Otro', 'email' => 'otro@soldados.mx', 'password' => 'secret']);
        $otherUser->assignRole('soldado');
        $other = Soldado::create(['name' => 'Otro', 'email' => 'otro@soldados.mx', 'is_active' => true, 'user_id' => $otherUser->id]);

        $this->actingAs($user);

        $visible = MiPerfilResource::getEloquentQuery()->pluck('id')->all();

        $this->assertSame([$mine->id], $visible);
        $this->assertNotContains($other->id, $visible);
    }
}

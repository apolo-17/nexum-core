<?php

namespace Tests\Feature\Soldado;

use App\Enums\AppointmentTypeEnum;
use App\Enums\EfirmaAppointmentStatusEnum;
use App\Filament\Resources\MisCitasResource;
use App\Filament\Resources\MisEmpresasResource;
use App\Models\Registration;
use App\Models\Soldado;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Verifies the soldado dashboard is correctly scoped and access-gated.
 */
class SoldadoDashboardTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Create a soldado linked to a user with the soldado role.
     */
    private function makeSoldadoUser(string $email): Soldado
    {
        Role::findOrCreate('soldado', 'web');

        $user = User::create(['name' => 'Sol '.$email, 'email' => $email, 'password' => 'secret']);
        $user->assignRole('soldado');

        return Soldado::create([
            'name' => 'Sol '.$email,
            'rfc' => strtoupper(substr(md5($email), 0, 13)),
            'available_as_legal_representative' => true,
            'is_active' => true,
            'user_id' => $user->id,
        ]);
    }

    #[Test]
    public function mis_citas_is_scoped_to_the_logged_in_soldado(): void
    {
        $mine = $this->makeSoldadoUser('mine@notaria.mx');
        $other = $this->makeSoldadoUser('other@notaria.mx');

        $regA = Registration::factory()->create();
        $regB = Registration::factory()->create();

        $myAppointment = $regA->appointments()->create([
            'soldado_id' => $mine->id,
            'type' => AppointmentTypeEnum::RFC,
            'status' => EfirmaAppointmentStatusEnum::SCHEDULED,
        ]);

        $regB->appointments()->create([
            'soldado_id' => $other->id,
            'type' => AppointmentTypeEnum::FIEL,
            'status' => EfirmaAppointmentStatusEnum::SCHEDULED,
        ]);

        $this->actingAs($mine->user);

        $visible = MisCitasResource::getEloquentQuery()->pluck('id')->all();

        $this->assertSame([$myAppointment->id], $visible);
    }

    #[Test]
    public function mis_empresas_is_scoped_to_the_soldados_actas(): void
    {
        $mine = $this->makeSoldadoUser('rep@notaria.mx');
        $other = $this->makeSoldadoUser('rep2@notaria.mx');

        $regA = Registration::factory()->create();
        $regB = Registration::factory()->create();

        $regA->soldados()->attach($mine->id, ['role' => 'legal_representative', 'participation_percentage' => 50]);
        $regB->soldados()->attach($other->id, ['role' => 'legal_representative', 'participation_percentage' => 50]);

        $this->actingAs($mine->user);

        $visible = MisEmpresasResource::getEloquentQuery()->pluck('id')->all();

        $this->assertSame([$regA->id], $visible);
    }

    #[Test]
    public function the_soldado_resources_are_gated_to_the_soldado_role(): void
    {
        $soldado = $this->makeSoldadoUser('gate@notaria.mx');

        Role::findOrCreate('notario', 'web');
        $notario = User::create(['name' => 'Noti', 'email' => 'noti@notaria.mx', 'password' => 'secret']);
        $notario->assignRole('notario');

        $this->actingAs($soldado->user);
        $this->assertTrue(MisCitasResource::canAccess());
        $this->assertTrue(MisEmpresasResource::canAccess());

        $this->actingAs($notario);
        $this->assertFalse(MisCitasResource::canAccess());
        $this->assertFalse(MisEmpresasResource::canAccess());
    }
}

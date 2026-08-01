<?php

namespace Tests\Feature\Filament;

use App\Enums\AppointmentStatusEnum;
use App\Enums\AppointmentTypeEnum;
use App\Enums\LegalNameStatusEnum;
use App\Filament\Widgets\DenominationsBoard;
use App\Filament\Widgets\SatAppointmentsBoard;
use App\Models\Appointment;
use App\Models\LegalName;
use App\Models\Registration;
use App\Models\Soldado;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Smoke tests for the super_admin dashboard boards (stats, citas, denominaciones).
 *
 * Verifies they render for a super_admin, list the right rows, and are hidden from
 * everyone else.
 */
class AdminDashboardBoardsTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        Role::findOrCreate('super_admin', 'web');
        $user = User::create(['name' => 'Admin', 'email' => 'admin@nexumcore.app', 'password' => 'secret']);
        $user->assignRole('super_admin');

        return $user;
    }

    private function makeScheduledAppointment(): Appointment
    {
        Role::findOrCreate('soldado', 'web');
        $soldadoUser = User::create(['name' => 'Sol', 'email' => 'sol@notaria.mx', 'password' => 'secret']);
        $soldadoUser->assignRole('soldado');
        $soldado = Soldado::create([
            'name' => 'Sol Test', 'rfc' => 'SOLX800101AB1', 'email' => 'sol@notaria.mx',
            'available_as_legal_representative' => true, 'is_active' => true, 'user_id' => $soldadoUser->id,
        ]);

        return Registration::factory()->create()->appointments()->create([
            'soldado_id' => $soldado->id,
            'type' => AppointmentTypeEnum::RFC,
            'status' => AppointmentStatusEnum::SCHEDULED,
            'scheduled_at' => now()->addDays(5),
            'email_alias' => 'soldado1@nexumcore.app',
        ]);
    }

    #[Test]
    public function the_boards_render_for_a_super_admin(): void
    {
        $admin = $this->superAdmin();
        $appointment = $this->makeScheduledAppointment();
        LegalName::create([
            'registration_id' => null, 'name' => 'NOVA GLOBAL', 'company_type' => 'srl',
            'priority' => 1, 'status' => LegalNameStatusEnum::DRAFT,
        ]);

        $this->actingAs($admin);

        Livewire::test(SatAppointmentsBoard::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$appointment]);
        Livewire::test(DenominationsBoard::class)->assertOk();
    }

    #[Test]
    public function the_appointments_board_shows_the_documentation_state(): void
    {
        $this->actingAs($this->superAdmin());
        $appointment = $this->makeScheduledAppointment(); // sin acuse ni comprobante

        // Falta acuse y comprobante → la columna lo refleja.
        Livewire::test(SatAppointmentsBoard::class)
            ->assertOk()
            ->assertSee('Falta');
    }

    #[Test]
    public function the_denominations_board_lists_only_pending_ones(): void
    {
        $this->actingAs($this->superAdmin());

        LegalName::create(['registration_id' => null, 'name' => 'EN PROCESO', 'company_type' => 'srl',
            'priority' => 1, 'status' => LegalNameStatusEnum::PROCESS]);
        LegalName::create(['registration_id' => null, 'name' => 'YA APROBADA', 'company_type' => 'srl',
            'priority' => 1, 'status' => LegalNameStatusEnum::APPROVED]);

        Livewire::test(DenominationsBoard::class)
            ->assertOk()
            ->assertSee('EN PROCESO')
            ->assertDontSee('YA APROBADA');
    }

    #[Test]
    public function the_boards_are_hidden_from_non_super_admins(): void
    {
        Role::findOrCreate('notario', 'web');
        $user = User::create(['name' => 'Notario', 'email' => 'not@nexumcore.app', 'password' => 'secret']);
        $user->assignRole('notario');
        $this->actingAs($user);

        $this->assertFalse(SatAppointmentsBoard::canView());
        $this->assertFalse(DenominationsBoard::canView());
    }
}

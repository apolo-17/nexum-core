<?php

namespace Tests\Feature\Filament;

use App\Enums\AppointmentStatusEnum;
use App\Enums\AppointmentTypeEnum;
use App\Filament\Resources\RegistrationResource\RelationManagers\AppointmentsRelationManager;
use App\Models\Appointment;
use App\Models\Registration;
use App\Models\Soldado;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The SAT allows a soldado at most one active cita per type (one RFC and one e.firma at the
 * same time, never two of the same). The manual assignment must detect that conflict.
 */
class AppointmentSoldadoConflictTest extends TestCase
{
    use RefreshDatabase;

    private function conflict(?string $soldadoId, ?string $type, ?string $exceptId = null): bool
    {
        $method = new ReflectionMethod(AppointmentsRelationManager::class, 'soldadoHasActiveConflict');
        $method->setAccessible(true);

        return $method->invoke(null, $soldadoId, $type, $exceptId);
    }

    private function soldado(): Soldado
    {
        return Soldado::create([
            'name' => 'Soldado Uno',
            'email' => 's1@nexum.test',
            'rfc' => 'SOL000001ABC',
            'is_active' => true,
            'available_as_legal_representative' => true,
        ]);
    }

    private function appointment(Soldado $soldado, AppointmentTypeEnum $type, AppointmentStatusEnum $status): Appointment
    {
        return Appointment::create([
            'registration_id' => Registration::factory()->create()->id,
            'soldado_id' => $soldado->id,
            'type' => $type->value,
            'status' => $status->value,
        ]);
    }

    #[Test]
    public function it_detects_a_second_active_cita_of_the_same_type(): void
    {
        $soldado = $this->soldado();
        $this->appointment($soldado, AppointmentTypeEnum::RFC, AppointmentStatusEnum::FORMED);

        $this->assertTrue($this->conflict($soldado->id, AppointmentTypeEnum::RFC->value));
    }

    #[Test]
    public function it_allows_a_cita_of_a_different_type(): void
    {
        $soldado = $this->soldado();
        $this->appointment($soldado, AppointmentTypeEnum::RFC, AppointmentStatusEnum::FORMED);

        // A FIEL is fine even though the soldado already holds an active RFC.
        $this->assertFalse($this->conflict($soldado->id, AppointmentTypeEnum::FIEL->value));
    }

    #[Test]
    public function it_ignores_terminal_citas(): void
    {
        $soldado = $this->soldado();
        $this->appointment($soldado, AppointmentTypeEnum::RFC, AppointmentStatusEnum::ATTENDED);

        // An attended (finished) cita frees the slot — no conflict for a new RFC.
        $this->assertFalse($this->conflict($soldado->id, AppointmentTypeEnum::RFC->value));
    }

    #[Test]
    public function it_excludes_the_cita_being_edited(): void
    {
        $soldado = $this->soldado();
        $cita = $this->appointment($soldado, AppointmentTypeEnum::RFC, AppointmentStatusEnum::FORMED);

        // Editing that same cita is not a conflict with itself.
        $this->assertFalse($this->conflict($soldado->id, AppointmentTypeEnum::RFC->value, $cita->id));
    }
}

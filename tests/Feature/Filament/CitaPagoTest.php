<?php

namespace Tests\Feature\Filament;

use App\Enums\AppointmentStatusEnum;
use App\Enums\AppointmentTypeEnum;
use App\Filament\Resources\CitaPagoResource;
use App\Models\Appointment;
use App\Models\Registration;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Payment tracking: a cita is only "pendiente de pago" once its date has passed AND we hold its
 * result; rejected/future/result-less citas are never payable. Marking paid computes IVA + total.
 */
class CitaPagoTest extends TestCase
{
    use RefreshDatabase;

    private function rfcCita(array $regOverrides, array $citaOverrides = []): Appointment
    {
        $reg = Registration::factory()->create($regOverrides);

        return Appointment::create(array_merge([
            'registration_id' => $reg->id,
            'type' => AppointmentTypeEnum::RFC,
            'status' => AppointmentStatusEnum::ATTENDED,
            'scheduled_at' => now()->subDay(),
        ], $citaOverrides));
    }

    #[Test]
    public function a_past_rfc_cita_with_rfc_is_pending_payment(): void
    {
        $cita = $this->rfcCita(['rfc' => 'ABC260101XY9']);

        $this->assertTrue($cita->isPayable());
        $this->assertSame('pendiente', $cita->paymentState());
    }

    #[Test]
    public function without_the_rfc_it_is_not_payable(): void
    {
        $cita = $this->rfcCita(['rfc' => null]);

        $this->assertFalse($cita->isPayable());
        $this->assertSame('aun_no', $cita->paymentState());
    }

    #[Test]
    public function a_future_cita_is_not_payable(): void
    {
        $cita = $this->rfcCita(['rfc' => 'ABC260101XY9'], ['scheduled_at' => now()->addDay()]);

        $this->assertFalse($cita->isPayable());
    }

    #[Test]
    public function a_rejected_cita_is_never_payable(): void
    {
        $cita = $this->rfcCita(['rfc' => 'ABC260101XY9'], ['status' => AppointmentStatusEnum::REJECTED]);

        $this->assertFalse($cita->isPayable());
    }

    #[Test]
    public function marking_paid_sets_state_and_computes_iva_and_total(): void
    {
        $cita = $this->rfcCita(['rfc' => 'ABC260101XY9']);
        $cita->update(['payment_amount' => 100, 'paid_at' => now()]);

        $this->assertSame('pagada', $cita->fresh()->paymentState());
        $this->assertEqualsWithDelta(16.0, $cita->paymentIva(), 0.001);
        $this->assertEqualsWithDelta(116.0, $cita->paymentTotal(), 0.001);
    }

    #[Test]
    public function only_super_admin_can_access_the_payments_board(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $admin = User::factory()->create();
        $admin->assignRole('super_admin');
        $this->actingAs($admin);
        $this->assertTrue(CitaPagoResource::canAccess());

        $partner = User::factory()->create();
        $partner->assignRole('partner');
        $this->actingAs($partner);
        $this->assertFalse(CitaPagoResource::canAccess());
    }
}

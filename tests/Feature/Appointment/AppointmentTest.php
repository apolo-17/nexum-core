<?php

namespace Tests\Feature\Appointment;

use App\Enums\AppointmentTypeEnum;
use App\Enums\EfirmaAppointmentStatusEnum;
use App\Models\Appointment;
use App\Models\Registration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Feature tests for SAT appointments (RFC and FIEL).
 */
class AppointmentTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_company_can_have_an_rfc_and_a_fiel_appointment(): void
    {
        $registration = Registration::factory()->create();

        $registration->appointments()->create([
            'type' => AppointmentTypeEnum::RFC,
            'status' => EfirmaAppointmentStatusEnum::PENDING_SCHEDULING,
        ]);

        $registration->appointments()->create([
            'type' => AppointmentTypeEnum::FIEL,
            'status' => EfirmaAppointmentStatusEnum::SCHEDULED,
        ]);

        $this->assertCount(2, $registration->refresh()->appointments);
        $this->assertEqualsCanonicalizing(
            [AppointmentTypeEnum::RFC, AppointmentTypeEnum::FIEL],
            $registration->appointments->pluck('type')->all(),
        );
    }

    #[Test]
    public function it_casts_type_and_status_and_reports_completion(): void
    {
        $registration = Registration::factory()->create();

        $appointment = $registration->appointments()->create([
            'type' => AppointmentTypeEnum::FIEL,
            'status' => EfirmaAppointmentStatusEnum::ATTENDED_APPROVED,
        ]);

        $fresh = Appointment::find($appointment->id);

        $this->assertInstanceOf(AppointmentTypeEnum::class, $fresh->type);
        $this->assertInstanceOf(EfirmaAppointmentStatusEnum::class, $fresh->status);
        $this->assertTrue($fresh->isCompleted());
    }
}

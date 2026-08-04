<?php

namespace Tests\Feature\Sat;

use App\Models\Appointment;
use App\Models\AppointmentEmail;
use App\Models\Registration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The pool-email rule: an address in use by an active cita (formed, or scheduled with a
 * future date) can never be reused; it frees on its own once the cita's date passes.
 */
class AppointmentEmailClaimTest extends TestCase
{
    use RefreshDatabase;

    private function appointment(string $status, ?string $alias = null, ?string $scheduledAt = null): Appointment
    {
        return Appointment::create([
            'registration_id' => Registration::factory()->create()->id,
            'type' => 'rfc',
            'status' => $status,
            'email_alias' => $alias,
            'scheduled_at' => $scheduledAt,
        ]);
    }

    #[Test]
    public function it_does_not_reuse_an_address_held_by_a_future_scheduled_cita(): void
    {
        AppointmentEmail::create(['address' => 'soldado1@nexumcore.app', 'is_free' => true]);
        AppointmentEmail::create(['address' => 'soldado2@nexumcore.app', 'is_free' => true]);

        // DONGHAI already holds soldado1 for a cita on Aug 5 (not passed) — even if the
        // stale flag says free, it must stay off-limits.
        AppointmentEmail::where('address', 'soldado1@nexumcore.app')->update(['is_free' => true]);
        $this->appointment('scheduled', 'soldado1@nexumcore.app', now()->addDays(3)->toDateTimeString());

        // LI BAO now claims — it must get soldado2, never soldado1.
        $liBao = $this->appointment('pending_forming');
        $this->assertSame('soldado2@nexumcore.app', AppointmentEmail::claimFor($liBao));
    }

    #[Test]
    public function it_reassigns_a_stale_colliding_alias(): void
    {
        AppointmentEmail::create(['address' => 'soldado1@nexumcore.app', 'is_free' => true]);
        AppointmentEmail::create(['address' => 'soldado2@nexumcore.app', 'is_free' => true]);

        $this->appointment('scheduled', 'soldado1@nexumcore.app', now()->addDays(3)->toDateTimeString());
        // LI BAO wrongly carries soldado1 (the bug) → claimFor must swap it for a free one.
        $liBao = $this->appointment('pending_forming', 'soldado1@nexumcore.app');

        $this->assertSame('soldado2@nexumcore.app', AppointmentEmail::claimFor($liBao->fresh()));
    }

    #[Test]
    public function it_frees_the_address_once_the_cita_date_has_passed(): void
    {
        AppointmentEmail::create(['address' => 'soldado1@nexumcore.app', 'is_free' => false]);
        // A cita whose date already passed no longer blocks its address.
        $this->appointment('scheduled', 'soldado1@nexumcore.app', now()->subDay()->toDateTimeString());

        $new = $this->appointment('pending_forming');
        $this->assertSame('soldado1@nexumcore.app', AppointmentEmail::claimFor($new));
    }
}

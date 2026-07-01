<?php

namespace Tests\Feature\SatBot;

use App\Enums\AppointmentStatusEnum;
use App\Enums\AppointmentTypeEnum;
use App\Models\AppointmentEmail;
use App\Models\Registration;
use App\Models\Soldado;
use App\Models\User;
use App\Notifications\SatAppointmentScheduledNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Feature tests for the nexum-citas-sat integration endpoints.
 *
 * Appointments are FORMED manually by the team; the bot only reviews formed ones and
 * reports back when the SAT assigns a slot.
 */
class SatBotEndpointsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Configure the shared bot keys for every test.
     */
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.sat_bot.api_key' => 'test-key', 'services.sat_bot.secret_key' => 'test-secret']);
    }

    /**
     * Sign a callback payload exactly as the bot must (see docs/CONTRACT.md).
     *
     * @param  array<string, mixed>  $payload
     */
    private function sign(array $payload, string $secret): string
    {
        ksort($payload);

        return hash_hmac('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $secret);
    }

    /**
     * Create a soldado linked to a user.
     */
    private function makeSoldado(): Soldado
    {
        Role::findOrCreate('soldado', 'web');
        $user = User::create(['name' => 'Sol', 'email' => 'sol@notaria.mx', 'password' => 'secret']);
        $user->assignRole('soldado');

        return Soldado::create([
            'name' => 'Sol',
            'email' => 'sol@notaria.mx',
            'rfc' => 'SOLX800101AB1',
            'available_as_legal_representative' => true,
            'is_active' => true,
            'user_id' => $user->id,
        ]);
    }

    #[Test]
    public function pending_review_requires_the_api_key(): void
    {
        $this->getJson('/api/v3/sat-bot/pending-review')->assertUnauthorized();
    }

    #[Test]
    public function pending_review_returns_formed_appointments_with_their_alias(): void
    {
        $soldado = $this->makeSoldado();
        $registration = Registration::factory()->create();
        $appointment = $registration->appointments()->create([
            'type' => AppointmentTypeEnum::RFC,
            'status' => AppointmentStatusEnum::FORMED,
            'soldado_id' => $soldado->id,
            'email_alias' => 'cita-1@dominio.mx',
            'formed_at' => now(),
        ]);

        $response = $this->withHeader('X-Bot-Api-Key', 'test-key')->getJson('/api/v3/sat-bot/pending-review');

        $response->assertOk()
            ->assertJsonPath('data.0.appointment_id', $appointment->id)
            ->assertJsonPath('data.0.sat_service', 'PM')
            ->assertJsonPath('data.0.email_alias', 'cita-1@dominio.mx');
    }

    #[Test]
    public function pending_review_skips_appointments_that_are_not_formed_or_lack_an_alias(): void
    {
        $soldado = $this->makeSoldado();
        $registration = Registration::factory()->create();

        // Not yet formed — should be ignored.
        $registration->appointments()->create([
            'type' => AppointmentTypeEnum::FIEL,
            'status' => AppointmentStatusEnum::PENDING_FORMING,
            'soldado_id' => $soldado->id,
        ]);

        // Formed but without a pool alias — the bot cannot read the token, so it is skipped.
        $registration->appointments()->create([
            'type' => AppointmentTypeEnum::RFC,
            'status' => AppointmentStatusEnum::FORMED,
            'soldado_id' => $soldado->id,
        ]);

        $response = $this->withHeader('X-Bot-Api-Key', 'test-key')->getJson('/api/v3/sat-bot/pending-review');

        $response->assertOk()->assertJsonCount(0, 'data');
    }

    #[Test]
    public function the_hmac_matches_the_shared_golden_vector(): void
    {
        // Same inputs as tests/test_signature.py in the nexum-citas-sat repo.
        $signature = $this->sign(
            ['appointment_id' => '01TESTAPPT', 'status' => 'scheduled', 'timestamp' => 1751000000],
            'test-secret',
        );

        $this->assertSame('653010bb9b3481a14c3f67c0ada1e54a8e9e88ac2fa41088249bc90072b7333d', $signature);
    }

    #[Test]
    public function callback_rejects_an_invalid_signature(): void
    {
        $this->postJson('/api/v3/webhook/sat-bot', ['appointment_id' => 'x', 'status' => 'scheduled', 'timestamp' => time()], ['X-Signature' => 'bad'])
            ->assertUnauthorized();
    }

    #[Test]
    public function callback_schedules_the_appointment_stores_acuse_and_notifies(): void
    {
        Notification::fake();
        Storage::fake(config('filesystems.default'));

        $email = AppointmentEmail::create(['address' => 'cita-2@dominio.mx', 'is_free' => false]);
        $soldado = $this->makeSoldado();
        $registration = Registration::factory()->create();
        $appointment = $registration->appointments()->create([
            'type' => AppointmentTypeEnum::FIEL,
            'status' => AppointmentStatusEnum::FORMED,
            'soldado_id' => $soldado->id,
            'email_alias' => 'cita-2@dominio.mx',
            'formed_at' => now(),
        ]);

        $payload = [
            'appointment_id' => $appointment->id,
            'status' => 'scheduled',
            'scheduled_at' => '2026-07-03 10:30:00',
            'office' => 'Módulo Culiacán',
            'acuse_pdf_base64' => base64_encode('%PDF-1.4 fake'),
            'timestamp' => time(),
        ];

        $signature = $this->sign(
            ['appointment_id' => $payload['appointment_id'], 'status' => 'scheduled', 'timestamp' => $payload['timestamp']],
            'test-secret',
        );

        $this->postJson('/api/v3/webhook/sat-bot', $payload, ['X-Signature' => $signature])->assertOk();

        $appointment->refresh();
        $this->assertSame(AppointmentStatusEnum::SCHEDULED, $appointment->status);
        $this->assertSame('Módulo Culiacán', $appointment->office);
        $this->assertNotNull($appointment->acknowledgment_path);
        $this->assertNotNull($appointment->last_review_at);
        Storage::disk(config('filesystems.default'))->assertExists($appointment->acknowledgment_path);

        $this->assertTrue($email->refresh()->is_free); // pool email released for reuse
        Notification::assertSentTo($soldado->user, SatAppointmentScheduledNotification::class);
    }

    #[Test]
    public function callback_in_review_keeps_it_formed_and_bumps_last_review(): void
    {
        $soldado = $this->makeSoldado();
        $registration = Registration::factory()->create();
        $appointment = $registration->appointments()->create([
            'type' => AppointmentTypeEnum::RFC,
            'status' => AppointmentStatusEnum::FORMED,
            'soldado_id' => $soldado->id,
            'email_alias' => 'cita-4@dominio.mx',
            'formed_at' => now(),
        ]);

        $payload = [
            'appointment_id' => $appointment->id,
            'status' => 'in_review',
            'timestamp' => time(),
        ];
        $signature = $this->sign(
            ['appointment_id' => $payload['appointment_id'], 'status' => 'in_review', 'timestamp' => $payload['timestamp']],
            'test-secret',
        );

        $this->postJson('/api/v3/webhook/sat-bot', $payload, ['X-Signature' => $signature])->assertOk();

        $appointment->refresh();
        $this->assertSame(AppointmentStatusEnum::FORMED, $appointment->status);
        $this->assertSame('cita-4@dominio.mx', $appointment->email_alias);
        $this->assertNotNull($appointment->last_review_at);
    }

    #[Test]
    public function callback_failed_keeps_it_formed_and_records_the_reason(): void
    {
        $email = AppointmentEmail::create(['address' => 'cita-3@dominio.mx', 'is_free' => false]);
        $soldado = $this->makeSoldado();
        $registration = Registration::factory()->create();
        $appointment = $registration->appointments()->create([
            'type' => AppointmentTypeEnum::RFC,
            'status' => AppointmentStatusEnum::FORMED,
            'soldado_id' => $soldado->id,
            'email_alias' => 'cita-3@dominio.mx',
            'formed_at' => now(),
        ]);

        $payload = [
            'appointment_id' => $appointment->id,
            'status' => 'failed',
            'failure_reason' => 'SAT sin disponibilidad',
            'timestamp' => time(),
        ];
        $signature = $this->sign(
            ['appointment_id' => $payload['appointment_id'], 'status' => 'failed', 'timestamp' => $payload['timestamp']],
            'test-secret',
        );

        $this->postJson('/api/v3/webhook/sat-bot', $payload, ['X-Signature' => $signature])->assertOk();

        $appointment->refresh();
        $this->assertSame(AppointmentStatusEnum::FORMED, $appointment->status);
        $this->assertSame('cita-3@dominio.mx', $appointment->email_alias); // alias kept — forming was manual
        $this->assertStringContainsString('SAT sin disponibilidad', (string) $appointment->notes);
        $this->assertFalse($email->refresh()->is_free); // still in use
    }
}

<?php

namespace Tests\Feature\SatBot;

use App\Enums\AppointmentEventTypeEnum;
use App\Enums\AppointmentStatusEnum;
use App\Enums\AppointmentTypeEnum;
use App\Enums\NotificationEventEnum;
use App\Models\AppointmentEmail;
use App\Models\NotificationSetting;
use App\Models\Registration;
use App\Models\Soldado;
use App\Models\User;
use App\Notifications\SatAppointmentScheduledNotification;
use App\Notifications\SatAppointmentStatusNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Feature tests for the nexum-citas-sat integration endpoints.
 *
 * Two phases: the bot FORMS pending_forming appointments in the SAT virtual queue
 * (pending-forming + callback "formed"), then REVIEWS the formed ones until the SAT
 * assigns a slot (pending-review + callback "scheduled"/"in_review").
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
        // Los avisos al equipo resuelven destinatarios por este rol; sin él, el
        // EventNotifier revienta y no se ejercitaría el camino real.
        Role::findOrCreate('super_admin', 'web');
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
    public function pending_forming_requires_the_api_key(): void
    {
        $this->getJson('/api/v3/sat-bot/pending-forming')->assertUnauthorized();
    }

    #[Test]
    public function pending_forming_returns_appointments_and_locks_a_pool_alias(): void
    {
        AppointmentEmail::create(['address' => 'soldado1@nexumcore.app', 'is_free' => true]);
        $soldado = $this->makeSoldado();
        $registration = Registration::factory()->create();
        $appointment = $registration->appointments()->create([
            'type' => AppointmentTypeEnum::RFC,
            'status' => AppointmentStatusEnum::PENDING_FORMING,
            'soldado_id' => $soldado->id,
        ]);

        $response = $this->withHeader('X-Bot-Api-Key', 'test-key')->getJson('/api/v3/sat-bot/pending-forming');

        $response->assertOk()
            ->assertJsonPath('data.0.appointment_id', $appointment->id)
            ->assertJsonPath('data.0.sat_service', 'PM')
            ->assertJsonPath('data.0.entidad', '10') // CDMX en el catálogo del SAT (no el 09 del INEGI)
            ->assertJsonPath('data.0.email_alias', 'soldado1@nexumcore.app');

        // El alias queda bloqueado y pegado a la cita.
        $this->assertSame('soldado1@nexumcore.app', $appointment->refresh()->email_alias);
        $this->assertFalse(AppointmentEmail::first()->is_free);
    }

    #[Test]
    public function pending_forming_passes_the_chosen_office_to_the_bot(): void
    {
        AppointmentEmail::create(['address' => 'soldado1@nexumcore.app', 'is_free' => true]);
        $soldado = $this->makeSoldado();
        $registration = Registration::factory()->create();
        $registration->appointments()->create([
            'type' => AppointmentTypeEnum::RFC,
            'status' => AppointmentStatusEnum::PENDING_FORMING,
            'soldado_id' => $soldado->id,
            'preferred_module' => 66, // ADSC DF "2" Centro
        ]);

        $this->withHeader('X-Bot-Api-Key', 'test-key')->getJson('/api/v3/sat-bot/pending-forming')
            ->assertOk()
            ->assertJsonPath('data.0.preferred_module', 66);
    }

    #[Test]
    public function pending_forming_leaves_the_office_null_when_nobody_chose_one(): void
    {
        // Sin sucursal elegida, el bot recorre su propia lista de CDMX.
        AppointmentEmail::create(['address' => 'soldado1@nexumcore.app', 'is_free' => true]);
        $soldado = $this->makeSoldado();
        $registration = Registration::factory()->create();
        $registration->appointments()->create([
            'type' => AppointmentTypeEnum::RFC,
            'status' => AppointmentStatusEnum::PENDING_FORMING,
            'soldado_id' => $soldado->id,
        ]);

        $this->withHeader('X-Bot-Api-Key', 'test-key')->getJson('/api/v3/sat-bot/pending-forming')
            ->assertOk()
            ->assertJsonPath('data.0.preferred_module', null);
    }

    #[Test]
    public function pending_forming_reuses_the_alias_already_assigned(): void
    {
        // Idempotencia: reintentar una cita no debe consumir un segundo correo del pool.
        AppointmentEmail::create(['address' => 'soldado1@nexumcore.app', 'is_free' => false]);
        AppointmentEmail::create(['address' => 'soldado2@nexumcore.app', 'is_free' => true]);
        $soldado = $this->makeSoldado();
        $registration = Registration::factory()->create();
        $registration->appointments()->create([
            'type' => AppointmentTypeEnum::FIEL,
            'status' => AppointmentStatusEnum::PENDING_FORMING,
            'soldado_id' => $soldado->id,
            'email_alias' => 'soldado1@nexumcore.app',
        ]);

        $this->withHeader('X-Bot-Api-Key', 'test-key')->getJson('/api/v3/sat-bot/pending-forming')
            ->assertOk()
            ->assertJsonPath('data.0.email_alias', 'soldado1@nexumcore.app')
            ->assertJsonPath('data.0.sat_service', 'E');

        // El segundo correo sigue libre.
        $this->assertTrue(AppointmentEmail::where('address', 'soldado2@nexumcore.app')->first()->is_free);
    }

    #[Test]
    public function pending_forming_skips_appointments_without_soldado_or_free_alias(): void
    {
        $soldado = $this->makeSoldado();
        $registration = Registration::factory()->create();

        // Sin soldado: el SAT necesita sus datos para formar.
        $registration->appointments()->create([
            'type' => AppointmentTypeEnum::RFC,
            'status' => AppointmentStatusEnum::PENDING_FORMING,
        ]);

        // Con soldado pero el pool está agotado: no se entrega sin buzón donde leer el token.
        $registration->appointments()->create([
            'type' => AppointmentTypeEnum::FIEL,
            'status' => AppointmentStatusEnum::PENDING_FORMING,
            'soldado_id' => $soldado->id,
        ]);

        $this->withHeader('X-Bot-Api-Key', 'test-key')->getJson('/api/v3/sat-bot/pending-forming')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    #[Test]
    public function pending_forming_ignores_already_formed_appointments(): void
    {
        AppointmentEmail::create(['address' => 'soldado1@nexumcore.app', 'is_free' => true]);
        $soldado = $this->makeSoldado();
        $registration = Registration::factory()->create();
        $registration->appointments()->create([
            'type' => AppointmentTypeEnum::RFC,
            'status' => AppointmentStatusEnum::FORMED,
            'soldado_id' => $soldado->id,
            'email_alias' => 'otro@dominio.mx',
            'formed_at' => now(),
        ]);

        $this->withHeader('X-Bot-Api-Key', 'test-key')->getJson('/api/v3/sat-bot/pending-forming')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    #[Test]
    public function callback_formed_marks_it_formed_and_keeps_the_alias(): void
    {
        $email = AppointmentEmail::create(['address' => 'soldado1@nexumcore.app', 'is_free' => false]);
        $soldado = $this->makeSoldado();
        $registration = Registration::factory()->create();
        $appointment = $registration->appointments()->create([
            'type' => AppointmentTypeEnum::RFC,
            'status' => AppointmentStatusEnum::PENDING_FORMING,
            'soldado_id' => $soldado->id,
            'email_alias' => 'soldado1@nexumcore.app',
        ]);

        $payload = [
            'appointment_id' => $appointment->id,
            'status' => 'formed',
            'office' => 'ADSC Centro CDMX',
            'timestamp' => time(),
        ];
        $signature = $this->sign(
            ['appointment_id' => $payload['appointment_id'], 'status' => 'formed', 'timestamp' => $payload['timestamp']],
            'test-secret',
        );

        $this->postJson('/api/v3/webhook/sat-bot', $payload, ['X-Signature' => $signature])->assertOk();

        $appointment->refresh();
        $this->assertSame(AppointmentStatusEnum::FORMED, $appointment->status);
        $this->assertNotNull($appointment->formed_at);
        $this->assertSame('ADSC Centro CDMX', $appointment->office);
        // El alias sigue bloqueado: ahí llega el token que el bot lee en cada revisión.
        $this->assertSame('soldado1@nexumcore.app', $appointment->email_alias);
        $this->assertFalse($email->refresh()->is_free);
    }

    #[Test]
    public function callback_formed_alerts_the_team(): void
    {
        Notification::fake();

        // Un admin suscrito al evento "cita formada".
        $admin = User::create(['name' => 'Admin', 'email' => 'admin@nexumcore.app', 'password' => 'secret']);
        $admin->assignRole('super_admin');
        $setting = NotificationSetting::firstOrCreate(
            ['event' => NotificationEventEnum::SAT_APPOINTMENT_FORMED->value],
            ['enabled' => true],
        );
        $setting->recipients()->attach($admin->id);

        $soldado = $this->makeSoldado();
        $registration = Registration::factory()->create();
        $appointment = $registration->appointments()->create([
            'type' => AppointmentTypeEnum::RFC,
            'status' => AppointmentStatusEnum::PENDING_FORMING,
            'soldado_id' => $soldado->id,
            'email_alias' => 'soldado1@nexumcore.app',
        ]);

        $payload = [
            'appointment_id' => $appointment->id,
            'status' => 'formed',
            'office' => '66',
            'timestamp' => time(),
        ];
        $signature = $this->sign(
            ['appointment_id' => $payload['appointment_id'], 'status' => 'formed', 'timestamp' => $payload['timestamp']],
            'test-secret',
        );

        $this->postJson('/api/v3/webhook/sat-bot', $payload, ['X-Signature' => $signature])->assertOk();

        Notification::assertSentTo($admin, SatAppointmentStatusNotification::class);
    }

    #[Test]
    public function callback_failed_while_forming_keeps_it_pending_forming(): void
    {
        $soldado = $this->makeSoldado();
        $registration = Registration::factory()->create();
        $appointment = $registration->appointments()->create([
            'type' => AppointmentTypeEnum::RFC,
            'status' => AppointmentStatusEnum::PENDING_FORMING,
            'soldado_id' => $soldado->id,
            'email_alias' => 'soldado1@nexumcore.app',
        ]);

        $payload = [
            'appointment_id' => $appointment->id,
            'status' => 'failed',
            'failure_reason' => 'no llegó el token',
            'timestamp' => time(),
        ];
        $signature = $this->sign(
            ['appointment_id' => $payload['appointment_id'], 'status' => 'failed', 'timestamp' => $payload['timestamp']],
            'test-secret',
        );

        $this->postJson('/api/v3/webhook/sat-bot', $payload, ['X-Signature' => $signature])->assertOk();

        $appointment->refresh();
        // Sigue por formar: el próximo ciclo la reintenta con el MISMO alias.
        $this->assertSame(AppointmentStatusEnum::PENDING_FORMING, $appointment->status);
        $this->assertSame('soldado1@nexumcore.app', $appointment->email_alias);
        $this->assertStringContainsString('fallo al formar', (string) $appointment->notes);
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
    public function every_callback_leaves_a_trace_on_the_timeline(): void
    {
        // El punto del historial: saber si el bot ha estado trabajando la cita, no solo
        // en qué estado quedó.
        $soldado = $this->makeSoldado();
        $registration = Registration::factory()->create();
        $appointment = $registration->appointments()->create([
            'type' => AppointmentTypeEnum::RFC,
            'status' => AppointmentStatusEnum::FORMED,
            'soldado_id' => $soldado->id,
            'email_alias' => 'soldado1@nexumcore.app',
            'formed_at' => now(),
        ]);

        foreach (['in_review', 'in_review', 'failed'] as $status) {
            $payload = ['appointment_id' => $appointment->id, 'status' => $status,
                        'failure_reason' => 'sin conexión', 'timestamp' => time()];
            $signature = $this->sign(
                ['appointment_id' => $appointment->id, 'status' => $status, 'timestamp' => $payload['timestamp']],
                'test-secret',
            );
            $this->postJson('/api/v3/webhook/sat-bot', $payload, ['X-Signature' => $signature])->assertOk();
        }

        $tipos = $appointment->events()->pluck('type')->all();
        $this->assertSame([
            AppointmentEventTypeEnum::FAILED,
            AppointmentEventTypeEnum::REVIEWED,
            AppointmentEventTypeEnum::REVIEWED,
        ], $tipos); // más reciente primero

        $fallo = $appointment->events()->first();
        $this->assertSame('bot', $fallo->actor_type);
        $this->assertStringContainsString('sin conexión', $fallo->description);
        $this->assertSame('revisar', $fallo->metadata['phase']);
    }

    #[Test]
    public function the_scheduled_callback_records_the_date_on_the_timeline(): void
    {
        Notification::fake();
        Storage::fake(config('filesystems.default'));

        $soldado = $this->makeSoldado();
        $registration = Registration::factory()->create();
        $appointment = $registration->appointments()->create([
            'type' => AppointmentTypeEnum::RFC,
            'status' => AppointmentStatusEnum::FORMED,
            'soldado_id' => $soldado->id,
            'email_alias' => 'soldado1@nexumcore.app',
            'formed_at' => now(),
        ]);

        $payload = ['appointment_id' => $appointment->id, 'status' => 'scheduled',
                    'scheduled_at' => '2026-08-05 09:30:00', 'office' => 'ADSC DF "2" Centro',
                    'timestamp' => time()];
        $signature = $this->sign(
            ['appointment_id' => $appointment->id, 'status' => 'scheduled', 'timestamp' => $payload['timestamp']],
            'test-secret',
        );

        $this->postJson('/api/v3/webhook/sat-bot', $payload, ['X-Signature' => $signature])->assertOk();

        $evento = $appointment->events()->first();
        $this->assertSame(AppointmentEventTypeEnum::SCHEDULED, $evento->type);
        $this->assertStringContainsString('05/08/2026 09:30', $evento->description);
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

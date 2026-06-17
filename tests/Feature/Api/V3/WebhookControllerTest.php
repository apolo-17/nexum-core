<?php

namespace Tests\Feature\Api\V3;

use App\Jobs\ProcessSingapurWebhook;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Feature tests for the V3 WebhookController (Singapur relay endpoint).
 *
 * Covers: shared-secret validation, idempotency, persistence, and job dispatch.
 * The queue is faked so no real jobs execute during tests.
 */
class WebhookControllerTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK_URL = '/api/v3/webhook/singapur';

    private const VALID_SECRET = 'nexum_test_secret';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.singapur.webhook_secret' => self::VALID_SECRET]);
        Queue::fake();
    }

    // -------------------------------------------------------------------------
    // Authorization
    // -------------------------------------------------------------------------

    #[Test]
    public function it_returns_401_when_secret_header_is_missing(): void
    {
        $this->postJson(self::WEBHOOK_URL, $this->validPayload())
            ->assertStatus(Response::HTTP_UNAUTHORIZED)
            ->assertJsonPath('error', 'Unauthorized');
    }

    #[Test]
    public function it_returns_401_when_secret_header_is_wrong(): void
    {
        $this->postJson(self::WEBHOOK_URL, $this->validPayload(), ['X-Nexum-Secret' => 'wrong'])
            ->assertStatus(Response::HTTP_UNAUTHORIZED);
    }

    // -------------------------------------------------------------------------
    // Validation
    // -------------------------------------------------------------------------

    #[Test]
    public function it_returns_422_when_event_id_is_missing(): void
    {
        $payload = $this->validPayload();
        unset($payload['event_id']);

        $this->postJson(self::WEBHOOK_URL, $payload, ['X-Nexum-Secret' => self::VALID_SECRET])
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonPath('error', 'Missing event_id');
    }

    // -------------------------------------------------------------------------
    // Happy path
    // -------------------------------------------------------------------------

    #[Test]
    public function it_accepts_a_valid_event_and_returns_202(): void
    {
        $this->postJson(self::WEBHOOK_URL, $this->validPayload(), ['X-Nexum-Secret' => self::VALID_SECRET])
            ->assertStatus(Response::HTTP_ACCEPTED)
            ->assertJsonPath('message', 'Event accepted');
    }

    #[Test]
    public function it_persists_the_webhook_event_in_the_database(): void
    {
        $this->postJson(self::WEBHOOK_URL, $this->validPayload(), ['X-Nexum-Secret' => self::VALID_SECRET]);

        $this->assertDatabaseHas('webhook_events', [
            'event_id' => 'evt-test-001',
            'source'   => 'singapur_relay',
            'status'   => 'pending',
        ]);
    }

    #[Test]
    public function it_dispatches_the_process_job_on_a_valid_event(): void
    {
        $this->postJson(self::WEBHOOK_URL, $this->validPayload(), ['X-Nexum-Secret' => self::VALID_SECRET]);

        Queue::assertPushed(ProcessSingapurWebhook::class, function ($job) {
            return $job->webhookEvent->event_id === 'evt-test-001';
        });
    }

    // -------------------------------------------------------------------------
    // Idempotency
    // -------------------------------------------------------------------------

    #[Test]
    public function it_returns_409_on_duplicate_event_id(): void
    {
        $payload = $this->validPayload();
        $headers = ['X-Nexum-Secret' => self::VALID_SECRET];

        $this->postJson(self::WEBHOOK_URL, $payload, $headers);

        $this->postJson(self::WEBHOOK_URL, $payload, $headers)
            ->assertStatus(Response::HTTP_CONFLICT)
            ->assertJsonPath('message', 'Event already received');
    }

    #[Test]
    public function it_dispatches_the_job_only_once_even_on_duplicate_delivery(): void
    {
        $payload = $this->validPayload();
        $headers = ['X-Nexum-Secret' => self::VALID_SECRET];

        $this->postJson(self::WEBHOOK_URL, $payload, $headers);
        $this->postJson(self::WEBHOOK_URL, $payload, $headers);

        Queue::assertPushed(ProcessSingapurWebhook::class, 1);
    }

    #[Test]
    public function it_does_not_create_a_duplicate_webhook_event_record(): void
    {
        $payload = $this->validPayload();
        $headers = ['X-Nexum-Secret' => self::VALID_SECRET];

        $this->postJson(self::WEBHOOK_URL, $payload, $headers);
        $this->postJson(self::WEBHOOK_URL, $payload, $headers);

        $this->assertSame(1, WebhookEvent::where('event_id', 'evt-test-001')->count());
    }

    // -------------------------------------------------------------------------
    // Fixtures
    // -------------------------------------------------------------------------

    /**
     * Build a valid webhook payload for the Singapur relay endpoint.
     *
     * @return array<string, string>
     */
    private function validPayload(): array
    {
        return [
            'event_id'            => 'evt-test-001',
            'company_folder_name' => '000001_NOVA CONSULTORA EMPRESARIAL',
            'document_group'      => 'KYC',
        ];
    }
}

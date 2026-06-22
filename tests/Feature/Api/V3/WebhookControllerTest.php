<?php

namespace Tests\Feature\Api\V3;

use App\Jobs\ProcessSingapurWebhook;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Feature tests for the V3 WebhookController (Singapur relay endpoint).
 *
 * Covers: shared-secret validation, idempotency, persistence, and job dispatch.
 * Bus::fake() is used instead of Queue::fake() so Horizon's queue event listeners
 * never fire and do not attempt Redis connections during the test suite.
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
        // Prevent any real HTTP calls in case the job somehow bypasses Bus::fake().
        Http::fake();
        Bus::fake();
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
            'source' => 'singapur_relay',
            'status' => 'pending',
        ]);
    }

    #[Test]
    public function it_dispatches_the_process_job_on_a_valid_event(): void
    {
        $this->postJson(self::WEBHOOK_URL, $this->validPayload(), ['X-Nexum-Secret' => self::VALID_SECRET]);

        Bus::assertDispatched(ProcessSingapurWebhook::class, function ($job) {
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

        Bus::assertDispatched(ProcessSingapurWebhook::class, 1);
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
     * Mirrors the full submission.json structure the relay now sends directly
     * in the webhook body instead of packaging it inside a ZIP archive.
     *
     * @return array<string, mixed>
     */
    private function validPayload(): array
    {
        return [
            'event_id' => 'evt-test-001',
            'id' => '7dde1760-57d4-4f4e-b81b-3ae2b93025d0',
            'type' => 'company-registration',
            'registration_number' => '000001',
            'company_folder_name' => '000001_NOVA CONSULTORA EMPRESARIAL',
            'document_group' => 'KYC',
            'created_at' => '2026-06-14T22:35:56.765341+00:00',
            'fields' => [
                'companyName' => 'NOVA CONSULTORÍA EMPRESARIAL',
                'companyType' => 'sa',
                'shareholderCount' => '2',
                'shareholderType1' => 'natural',
                'naturalShareholderName1' => 'Jiaxin Wu',
                'naturalShareholderEmail1' => 'jiaxin@example.com',
                'naturalSharePercentage1' => '50',
                'naturalNationality1' => 'china',
                'naturalOtherNationality1' => '',
                'naturalMarried1' => 'yes',
                'shareholderType2' => 'natural',
                'naturalShareholderName2' => 'Ruijia Li',
                'naturalShareholderEmail2' => 'ruijia@example.com',
                'naturalSharePercentage2' => '50',
                'naturalNationality2' => 'china',
                'naturalOtherNationality2' => '',
                'naturalMarried2' => 'yes',
                '_language' => 'zh',
            ],
            'files' => [
                [
                    'field' => 'naturalTaxCertificate1',
                    'original_name' => 'JIAXIN_WIU_TAX_ID.pdf',
                    'relay_name' => '000001__naturalTaxCertificate1__JIAXIN_WIU_TAX_ID.pdf',
                    'size' => 108548,
                    'content_type' => 'application/pdf',
                    'content' => base64_encode('fake-pdf-content'),
                ],
            ],
        ];
    }
}

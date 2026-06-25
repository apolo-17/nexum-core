<?php

namespace Tests\Feature\Jobs;

use App\Enums\DocumentTypeEnum;
use App\Enums\RegistrationStageEnum;
use App\Enums\WebhookEventStatusEnum;
use App\Jobs\AnalyzeDocumentJob;
use App\Jobs\ProcessSingapurWebhook;
use App\Jobs\SubmitLegalNameToMuaJob;
use App\Models\Registration;
use App\Models\WebhookEvent;
use App\Services\Notifications\EventNotifier;
use App\Services\Registration\RegistrationUpsertService;
use App\Services\Singapur\SingapurSubmissionParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Feature tests for the pre-verified flow triggered by China's incorporation_deed.
 *
 * When the webhook payload carries the pre-rendered acta, the job must: store the
 * deed as a Document, mark every document verified, dispatch extraction, and skip
 * identity validation by advancing the registration to the denomination stage.
 *
 * Only the inner jobs are faked (so no real Anthropic / MUA calls happen); the
 * webhook job itself runs via handle() so its side effects are exercised.
 */
class IncorporationDeedIngestionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake();
        Notification::fake();
        Queue::fake([AnalyzeDocumentJob::class, SubmitLegalNameToMuaJob::class]);

        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
    }

    #[Test]
    public function it_stores_the_deed_verifies_all_documents_and_skips_identity(): void
    {
        $this->runWebhook($this->payloadWithDeed());

        $registration = Registration::where('singapur_client_code', '000001')->firstOrFail();

        // The deed is stored as its own document type.
        $this->assertDatabaseHas('documents', [
            'registration_id' => $registration->id,
            'type' => DocumentTypeEnum::INCORPORATION_DEED->value,
        ]);

        // Every document is marked verified (China already did identity + extraction).
        $this->assertGreaterThan(0, $registration->documents()->count());
        $this->assertSame(0, $registration->documents()->whereNull('verified_at')->count());

        // Identity validation is skipped — the expedient lands at the denomination stage.
        $this->assertSame(RegistrationStageEnum::LEGAL_NAME, $registration->fresh()->stage);

        // Extraction is triggered for the verified documents.
        Queue::assertPushed(AnalyzeDocumentJob::class);
    }

    #[Test]
    public function it_keeps_the_default_flow_when_no_deed_is_present(): void
    {
        $payload = $this->payloadWithDeed();
        unset($payload['incorporation_deed']);

        $this->runWebhook($payload);

        $registration = Registration::where('singapur_client_code', '000001')->firstOrFail();

        // Without the deed, identity is not auto-verified and the stage stays initial.
        $this->assertSame(RegistrationStageEnum::DATA_RECEIVED, $registration->fresh()->stage);
        $this->assertGreaterThan(0, $registration->documents()->whereNull('verified_at')->count());

        Queue::assertNotPushed(AnalyzeDocumentJob::class);
    }

    /**
     * Execute the webhook job synchronously through handle() for the given payload.
     *
     * @param  array<string, mixed>  $payload
     */
    private function runWebhook(array $payload): void
    {
        $event = WebhookEvent::factory()->create([
            'payload' => $payload,
            'status' => WebhookEventStatusEnum::PENDING,
        ]);

        (new ProcessSingapurWebhook($event))->handle(
            app(SingapurSubmissionParser::class),
            app(RegistrationUpsertService::class),
            app(EventNotifier::class),
        );
    }

    /**
     * Build a webhook payload that includes the top-level incorporation_deed field.
     *
     * @return array<string, mixed>
     */
    private function payloadWithDeed(): array
    {
        return [
            'id' => '7dde1760-57d4-4f4e-b81b-3ae2b93025d0',
            'registration_number' => '000001',
            'company_folder_name' => '000001_NOVA CONSULTORA EMPRESARIAL',
            'fields' => [
                'companyName' => 'NOVA CONSULTORÍA EMPRESARIAL',
                'companyType' => 'sa',
                'shareholderCount' => '1',
                'shareholderType1' => 'natural',
                'naturalShareholderName1' => 'Jiaxin Wu',
                'naturalShareholderEmail1' => 'jiaxin@example.com',
                'naturalSharePercentage1' => '100',
                'naturalNationality1' => 'china',
                'naturalMarried1' => 'no',
                '_language' => 'zh',
            ],
            'files' => [
                [
                    'field' => 'naturalTaxCertificate1',
                    'original_name' => 'JIAXIN_WU_TAX_ID.pdf',
                    'relay_name' => '000001__naturalTaxCertificate1__JIAXIN_WU_TAX_ID.pdf',
                    'size' => 16,
                    'content_type' => 'application/pdf',
                    'content' => base64_encode('fake-pdf-content'),
                ],
            ],
            'incorporation_deed' => base64_encode('fake-acta-render'),
        ];
    }
}

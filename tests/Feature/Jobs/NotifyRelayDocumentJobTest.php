<?php

namespace Tests\Feature\Jobs;

use App\Enums\DocumentTypeEnum;
use App\Jobs\NotifyRelayDocumentJob;
use App\Models\Document;
use App\Models\Registration;
use App\Services\Singapur\RelayDocumentAlertService;
use App\Services\Singapur\RelayFileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The relay job must not hand China a file it cannot ingest: when the served file exceeds the
 * China ceiling it fails fast with a clear reason instead of hanging on China's timeout.
 */
class NotifyRelayDocumentJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('filesystems.default'));
        config([
            'services.singapur.document_alert_url' => 'https://relay.test/alerts',
            'services.singapur.webhook_secret' => 'secret',
            // No compression path: keep the original, and set a tiny China ceiling.
            'services.singapur.relay_max_bytes' => 500 * 1024 * 1024,
            'services.singapur.china_max_bytes' => 10,
        ]);
    }

    #[Test]
    public function it_fails_fast_when_the_served_file_exceeds_the_china_ceiling(): void
    {
        Http::fake();

        $registration = Registration::factory()->create(['singapur_client_code' => '000123']);
        Storage::put('documents/acta.pdf', str_repeat('X', 4000));
        $doc = Document::factory()->create([
            'registration_id' => $registration->id,
            'type' => DocumentTypeEnum::ACTA_PROTOCOLIZADA,
            'storage_path' => 'documents/acta.pdf',
            'relay_delivered_at' => null,
        ]);

        (new NotifyRelayDocumentJob($doc->id))->handle(
            app(RelayDocumentAlertService::class),
            app(RelayFileService::class),
        );

        $doc->refresh();
        $this->assertNotNull($doc->relay_failed_at, 'An oversized file must be marked failed.');
        $this->assertStringContainsString('excede el límite', (string) $doc->relay_last_error);
        $this->assertNull($doc->relay_delivered_at);
        Http::assertNothingSent(); // never even attempted the slow send
    }

    #[Test]
    public function it_sends_when_the_file_is_within_the_ceiling(): void
    {
        config(['services.singapur.china_max_bytes' => 10 * 1024 * 1024]);
        Http::fake([
            'relay.test/*' => Http::response([
                'document' => ['received_at' => now()->toIso8601String(), 'drive_web_view_link' => 'https://drive/x'],
            ], 200),
        ]);

        $registration = Registration::factory()->create(['singapur_client_code' => '000123']);
        Storage::put('documents/csf.pdf', str_repeat('X', 2000));
        $doc = Document::factory()->create([
            'registration_id' => $registration->id,
            'type' => DocumentTypeEnum::CSF,
            'storage_path' => 'documents/csf.pdf',
            'relay_delivered_at' => null,
        ]);

        (new NotifyRelayDocumentJob($doc->id))->handle(
            app(RelayDocumentAlertService::class),
            app(RelayFileService::class),
        );

        $doc->refresh();
        $this->assertNotNull($doc->relay_delivered_at, 'A within-limit file must be delivered.');
        $this->assertNull($doc->relay_failed_at);
        Http::assertSent(fn ($request) => $request->url() === 'https://relay.test/alerts');
    }
}

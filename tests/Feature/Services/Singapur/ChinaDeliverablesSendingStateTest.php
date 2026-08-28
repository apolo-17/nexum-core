<?php

namespace Tests\Feature\Services\Singapur;

use App\Enums\DocumentTypeEnum;
use App\Models\Document;
use App\Models\Registration;
use App\Services\Singapur\ChinaDeliverablesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A deliverable that is mid-send shows the transient "sending" state so the panel can display
 * "Enviando…" live — but only while the send is fresh; a stale marker reverts to "pending".
 */
class ChinaDeliverablesSendingStateTest extends TestCase
{
    use RefreshDatabase;

    private function csf(Registration $r, array $overrides): Document
    {
        return Document::factory()->create(array_merge([
            'registration_id' => $r->id,
            'type' => DocumentTypeEnum::CSF,
            'storage_path' => 'documents/csf.pdf',
        ], $overrides));
    }

    private function stateForCsf(Registration $r): string
    {
        return collect(app(ChinaDeliverablesService::class)->statusFor($r))
            ->firstWhere('type', DocumentTypeEnum::CSF->value)['state'];
    }

    #[Test]
    public function a_fresh_sending_marker_shows_sending(): void
    {
        $r = Registration::factory()->create();
        $this->csf($r, ['relay_sending_at' => now()->subSeconds(30), 'relay_delivered_at' => null]);

        $this->assertSame('sending', $this->stateForCsf($r));
    }

    #[Test]
    public function a_stale_sending_marker_reverts_to_pending(): void
    {
        $r = Registration::factory()->create();
        $this->csf($r, ['relay_sending_at' => now()->subMinutes(30), 'relay_delivered_at' => null]);

        $this->assertSame('pending', $this->stateForCsf($r));
    }

    #[Test]
    public function delivered_wins_over_a_sending_marker(): void
    {
        $r = Registration::factory()->create();
        $this->csf($r, ['relay_sending_at' => now(), 'relay_delivered_at' => now()]);

        $this->assertSame('delivered', $this->stateForCsf($r));
    }
}

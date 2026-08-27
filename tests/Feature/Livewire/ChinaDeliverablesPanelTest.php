<?php

namespace Tests\Feature\Livewire;

use App\Enums\DocumentTypeEnum;
use App\Jobs\NotifyRelayDocumentJob;
use App\Livewire\ChinaDeliverablesPanel;
use App\Models\Document;
use App\Models\Registration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The interactive "Entregables a China" panel renders the deliverables and its Send button
 * dispatches the relay job for the chosen document.
 */
class ChinaDeliverablesPanelTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_renders_the_deliverables_and_sends_a_pending_one(): void
    {
        Queue::fake();

        $registration = Registration::factory()->create();
        $rpp = Document::factory()->create([
            'registration_id' => $registration->id,
            'type' => DocumentTypeEnum::RPP_REGISTRATION,
            'storage_path' => 'documents/rpp.pdf',
            'relay_delivered_at' => null,
        ]);

        Livewire::test(ChinaDeliverablesPanel::class, ['registration' => $registration->id])
            ->assertSee('RPP')
            ->assertSee('Acta protocolizada')
            ->assertSee('Enviar')
            ->call('send', DocumentTypeEnum::RPP_REGISTRATION->value);

        Queue::assertPushed(NotifyRelayDocumentJob::class);

        $this->assertNull($rpp->fresh()->relay_delivered_at);
    }

    #[Test]
    public function the_expediente_view_renders_with_the_embedded_panel(): void
    {
        \Spatie\Permission\Models\Role::findOrCreate('super_admin', 'web');
        $user = \App\Models\User::factory()->create();
        $user->assignRole('super_admin');
        $this->actingAs($user);

        $registration = Registration::factory()->create();

        \Livewire\Livewire::test(
            \App\Filament\Resources\RegistrationResource\Pages\ViewRegistration::class,
            ['record' => $registration->id],
        )->assertSuccessful()->assertSee('Entregables a China');
    }
}

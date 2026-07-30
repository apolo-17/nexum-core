<?php

namespace Tests\Feature\Denomination;

use App\Enums\DocumentTypeEnum;
use App\Enums\LegalNameEventTypeEnum;
use App\Enums\LegalNameStatusEnum;
use App\Filament\Resources\RegistrationResource\Pages\EditRegistration;
use App\Models\Document;
use App\Models\LegalName;
use App\Models\Registration;
use App\Models\User;
use App\Services\Denomination\ClaimPoolDenominationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Covers claiming an SE-approved pool denomination for an expedient.
 *
 * The claim must do two things at once: make the approved name the expedient's
 * priority-1 denomination, and bring its SE constancia along as a document of the
 * file. Both the dashboard form and the China claim API go through the service.
 */
class ClaimPoolDenominationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Create an approved pool denomination, optionally with its constancia stored.
     *
     * @param  bool  $withConstancia  Whether to write the PDF the MUA bot would have saved.
     */
    private function approvedPoolName(bool $withConstancia = true): LegalName
    {
        $name = LegalName::create([
            'registration_id' => null,
            'name' => 'ALVENTO CONSULTORES',
            'company_type' => 'srl',
            'priority' => 1,
            'status' => LegalNameStatusEnum::APPROVED,
            'clave_unica_denominacion' => 'A202600123456',
            'authorization_timestamp' => now(),
        ]);

        if ($withConstancia) {
            Storage::disk('s3')->put(
                ClaimPoolDenominationService::poolConstanciaPath($name),
                '%PDF-1.4 constancia',
            );
        }

        return $name;
    }

    /**
     * Sign in as a super admin, the role that edits expedients.
     */
    private function actingAsSuperAdmin(): User
    {
        // The form's assignment selects query these roles, so they must exist.
        Role::findOrCreate('super_admin', 'web');
        Role::findOrCreate('notario', 'web');
        Role::findOrCreate('asistente_notario', 'web');

        $user = User::factory()->create();
        $user->assignRole('super_admin');
        $this->actingAs($user);

        return $user;
    }

    #[Test]
    public function claiming_links_the_name_and_copies_its_constancia_into_the_expedient(): void
    {
        Storage::fake('s3');

        $registration = Registration::factory()->create();
        $poolName = $this->approvedPoolName();

        $result = app(ClaimPoolDenominationService::class)->claim($poolName, $registration);

        $this->assertTrue($result->claimed);
        $this->assertTrue($result->constanciaAttached);

        $poolName->refresh();
        $this->assertSame($registration->id, $poolName->registration_id);
        $this->assertSame(1, $poolName->priority);
        $this->assertSame('ALVENTO CONSULTORES', $registration->fresh()->primaryLegalName?->name);

        $document = Document::where('registration_id', $registration->id)
            ->where('type', DocumentTypeEnum::LEGAL_NAME_AUTHORIZATION->value)
            ->first();

        $this->assertNotNull($document);
        Storage::disk('s3')->assertExists($document->storage_path);
        $this->assertSame('%PDF-1.4 constancia', Storage::disk('s3')->get($document->storage_path));

        // The pool original stays untouched (copy, not move).
        Storage::disk('s3')->assertExists(ClaimPoolDenominationService::poolConstanciaPath($poolName));

        $this->assertTrue(
            $poolName->events()->where('type', LegalNameEventTypeEnum::CLAIMED->value)->exists(),
        );
    }

    #[Test]
    public function the_claimed_name_takes_priority_one_and_demotes_the_placeholder(): void
    {
        Storage::fake('s3');

        $registration = Registration::factory()->create();
        $placeholder = LegalName::create([
            'registration_id' => $registration->id,
            'name' => 'NOMBRE PROVISIONAL',
            'priority' => 1,
            'status' => LegalNameStatusEnum::DRAFT,
        ]);

        app(ClaimPoolDenominationService::class)->claim($this->approvedPoolName(), $registration);

        // The placeholder is kept for the record, just no longer the primary name.
        $this->assertSame(2, $placeholder->fresh()->priority);
        $this->assertSame('ALVENTO CONSULTORES', $registration->fresh()->primaryLegalName?->name);
    }

    #[Test]
    public function a_name_without_a_stored_constancia_is_still_linked(): void
    {
        Storage::fake('s3');

        $registration = Registration::factory()->create();

        $result = app(ClaimPoolDenominationService::class)
            ->claim($this->approvedPoolName(withConstancia: false), $registration);

        $this->assertTrue($result->claimed);
        $this->assertFalse($result->constanciaAttached);
        $this->assertNotNull($result->reason);

        $this->assertSame('ALVENTO CONSULTORES', $registration->fresh()->primaryLegalName?->name);
        $this->assertDatabaseMissing('documents', [
            'registration_id' => $registration->id,
            'type' => DocumentTypeEnum::LEGAL_NAME_AUTHORIZATION->value,
        ]);
    }

    #[Test]
    public function a_name_already_claimed_cannot_be_claimed_again(): void
    {
        Storage::fake('s3');

        $first = Registration::factory()->create();
        $second = Registration::factory()->create();
        $poolName = $this->approvedPoolName();

        app(ClaimPoolDenominationService::class)->claim($poolName, $first);
        $result = app(ClaimPoolDenominationService::class)->claim($poolName->fresh(), $second);

        $this->assertFalse($result->claimed);
        $this->assertSame($first->id, $poolName->fresh()->registration_id);
        $this->assertDatabaseMissing('documents', ['registration_id' => $second->id]);
    }

    #[Test]
    public function the_edit_form_claims_the_selected_pool_denomination(): void
    {
        Storage::fake('s3');
        $this->actingAsSuperAdmin();

        $registration = Registration::factory()->create();
        $poolName = $this->approvedPoolName();

        Livewire::test(EditRegistration::class, ['record' => $registration->getKey()])
            ->fillForm(['pool_legal_name_id' => $poolName->id])
            ->call('save')
            ->assertHasNoFormErrors();

        $registration->refresh();

        $this->assertSame($registration->id, $poolName->fresh()->registration_id);
        $this->assertSame('ALVENTO CONSULTORES', $registration->primaryLegalName?->name);
        $this->assertSame(LegalNameStatusEnum::APPROVED, $registration->primaryLegalName?->status);

        $this->assertDatabaseHas('documents', [
            'registration_id' => $registration->id,
            'type' => DocumentTypeEnum::LEGAL_NAME_AUTHORIZATION->value,
        ]);
    }

    #[Test]
    public function re_saving_the_form_does_not_re_claim_the_linked_denomination(): void
    {
        Storage::fake('s3');
        $this->actingAsSuperAdmin();

        $registration = Registration::factory()->create();
        $poolName = $this->approvedPoolName();

        app(ClaimPoolDenominationService::class)->claim($poolName, $registration);

        Livewire::test(EditRegistration::class, ['record' => $registration->getKey()])
            ->assertFormSet(['pool_legal_name_id' => $poolName->id, 'legal_name' => 'ALVENTO CONSULTORES'])
            ->fillForm(['company_object' => 'Servicios de consultoría'])
            ->call('save')
            ->assertHasNoFormErrors();

        $registration->refresh();

        $this->assertSame(1, $registration->legalNames()->count());
        $this->assertSame('ALVENTO CONSULTORES', $registration->primaryLegalName?->name);
        $this->assertSame(
            1,
            $poolName->events()->where('type', LegalNameEventTypeEnum::CLAIMED->value)->count(),
        );
    }

    #[Test]
    public function an_se_authorized_name_cannot_be_renamed_by_hand(): void
    {
        Storage::fake('s3');
        $this->actingAsSuperAdmin();

        $registration = Registration::factory()->create();
        $poolName = $this->approvedPoolName();

        app(ClaimPoolDenominationService::class)->claim($poolName, $registration);

        // Clearing the picker must not turn the razón social into free text again.
        Livewire::test(EditRegistration::class, ['record' => $registration->getKey()])
            ->fillForm(['pool_legal_name_id' => null, 'legal_name' => 'OTRO NOMBRE'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('ALVENTO CONSULTORES', $registration->fresh()->primaryLegalName?->name);
    }

    #[Test]
    public function the_claim_api_also_attaches_the_constancia(): void
    {
        Storage::fake('s3');

        $registration = Registration::factory()->create(['singapur_client_code' => 'EXT-0009']);
        $poolName = $this->approvedPoolName();

        $this->actingAs(User::factory()->create(), 'api')
            ->postJson("/api/v3/denominations/{$poolName->id}/claim", [
                'registration_code' => 'EXT-0009',
            ])
            ->assertOk()
            ->assertJsonPath('data.constancia_attached', true);

        $this->assertDatabaseHas('documents', [
            'registration_id' => $registration->id,
            'type' => DocumentTypeEnum::LEGAL_NAME_AUTHORIZATION->value,
        ]);
    }
}

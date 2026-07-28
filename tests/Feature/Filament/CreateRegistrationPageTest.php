<?php

namespace Tests\Feature\Filament;

use App\Enums\LegalNameStatusEnum;
use App\Enums\RegistrationStageEnum;
use App\Enums\RegistrationStatusEnum;
use App\Enums\ShareholderRoleEnum;
use App\Filament\Resources\RegistrationResource\Pages\CreateRegistration;
use App\Filament\Resources\RegistrationResource\Pages\EditRegistration;
use App\Models\LegalName;
use App\Models\Registration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Covers the manual registration of an expedient incorporated outside the platform.
 *
 * The razón social and the shareholders live in their own tables, so the create form
 * writes to three tables at once. These tests pin that behaviour down.
 */
class CreateRegistrationPageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Sign in as a super admin, the role that registers expedients manually.
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
    public function it_registers_an_expedient_with_its_company_name_and_shareholders(): void
    {
        $this->actingAsSuperAdmin();

        Livewire::test(CreateRegistration::class)
            ->fillForm([
                'singapur_client_code' => 'EXT-0001',
                'legal_name' => 'NOVA CONSULTORIA EMPRESARIAL',
                'legal_name_status' => LegalNameStatusEnum::APPROVED->value,
                'company_type' => 'SA de CV',
                'company_object' => 'Servicios de consultoría',
                'capital_social' => 50000,
                'rfc' => 'NCE250101AB1',
                'stage' => RegistrationStageEnum::SAT_REGISTRATION->value,
                'status' => RegistrationStatusEnum::ACTIVE->value,
                'shareholders' => [
                    [
                        'name' => '吴佳鑫',
                        'nationality' => 'China',
                        'participation_percentage' => 60,
                        'role' => ShareholderRoleEnum::SHAREHOLDER->value,
                        'is_married' => true,
                    ],
                    [
                        'name' => '李伟',
                        'nationality' => 'China',
                        'participation_percentage' => 40,
                        'role' => ShareholderRoleEnum::LEGAL_REPRESENTATIVE->value,
                        'is_married' => false,
                    ],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $registration = Registration::firstWhere('singapur_client_code', 'EXT-0001');

        $this->assertNotNull($registration);
        $this->assertSame('Servicios de consultoría', $registration->company_object);
        $this->assertSame('50000.00', $registration->capital_social);
        $this->assertSame(RegistrationStageEnum::SAT_REGISTRATION, $registration->stage);

        // The razón social must land as the priority-1 denomination: that is the one the
        // dashboard, the acta, and the SAT bot payload all read.
        $legalName = $registration->primaryLegalName;

        $this->assertNotNull($legalName);
        $this->assertSame('NOVA CONSULTORIA EMPRESARIAL', $legalName->name);
        $this->assertSame(LegalNameStatusEnum::APPROVED, $legalName->status);

        $this->assertCount(2, $registration->shareholders);
        $this->assertSame('吴佳鑫', $registration->shareholders->first()->name);
    }

    #[Test]
    public function it_requires_the_client_code_and_the_company_name(): void
    {
        $this->actingAsSuperAdmin();

        Livewire::test(CreateRegistration::class)
            ->fillForm([
                'singapur_client_code' => null,
                'legal_name' => null,
                'stage' => RegistrationStageEnum::DATA_RECEIVED->value,
                'status' => RegistrationStatusEnum::ACTIVE->value,
            ])
            ->call('create')
            ->assertHasFormErrors(['singapur_client_code' => 'required', 'legal_name' => 'required']);

        $this->assertDatabaseCount('registrations', 0);
    }

    #[Test]
    public function it_registers_an_expedient_without_shareholders(): void
    {
        $this->actingAsSuperAdmin();

        Livewire::test(CreateRegistration::class)
            ->fillForm([
                'singapur_client_code' => 'EXT-0002',
                'legal_name' => 'EMPRESA SIN SOCIOS',
                'stage' => RegistrationStageEnum::DATA_RECEIVED->value,
                'status' => RegistrationStatusEnum::ACTIVE->value,
                'shareholders' => [],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $registration = Registration::firstWhere('singapur_client_code', 'EXT-0002');

        $this->assertNotNull($registration);
        $this->assertSame('EMPRESA SIN SOCIOS', $registration->primaryLegalName?->name);
        $this->assertCount(0, $registration->shareholders);
    }

    #[Test]
    public function it_renames_the_existing_denomination_instead_of_adding_another_one(): void
    {
        $this->actingAsSuperAdmin();

        $registration = Registration::factory()->create();
        LegalName::create([
            'registration_id' => $registration->id,
            'name' => 'NOMBRE VIEJO',
            'priority' => 1,
            'status' => LegalNameStatusEnum::APPROVED,
        ]);

        Livewire::test(EditRegistration::class, ['record' => $registration->getKey()])
            ->assertFormSet(['legal_name' => 'NOMBRE VIEJO'])
            ->fillForm(['legal_name' => 'NOMBRE NUEVO'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(1, $registration->legalNames()->count());
        $this->assertSame('NOMBRE NUEVO', $registration->fresh()->primaryLegalName?->name);
    }

    #[Test]
    public function editing_an_expedient_keeps_its_shareholders(): void
    {
        $this->actingAsSuperAdmin();

        $registration = Registration::factory()->create();
        $registration->shareholders()->create([
            'name' => 'Socio Existente',
            'nationality' => 'China',
            'participation_percentage' => 100,
            'role' => ShareholderRoleEnum::SHAREHOLDER->value,
        ]);

        Livewire::test(EditRegistration::class, ['record' => $registration->getKey()])
            ->fillForm(['legal_name' => 'RAZON SOCIAL', 'company_object' => 'Comercio'])
            ->call('save')
            ->assertHasNoFormErrors();

        // The shareholders repeater is hidden on edit (the Socios tab owns it there);
        // saving must not wipe the rows it cannot see.
        $this->assertSame(1, $registration->shareholders()->count());
    }
}

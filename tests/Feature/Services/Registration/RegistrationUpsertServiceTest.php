<?php

namespace Tests\Feature\Services\Registration;

use App\DTOs\SingapurFileDTO;
use App\DTOs\SingapurShareholderDTO;
use App\DTOs\SingapurSubmissionDTO;
use App\Enums\LegalNameStatusEnum;
use App\Enums\RegistrationStageEnum;
use App\Enums\RegistrationStatusEnum;
use App\Enums\ShareholderRoleEnum;
use App\Models\Document;
use App\Models\LegalName;
use App\Models\Registration;
use App\Models\Shareholder;
use App\Services\Registration\RegistrationUpsertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Feature tests for RegistrationUpsertService.
 *
 * Uses RefreshDatabase so each test starts from a clean state.
 * Tests verify the DB outcome of the upsert flow end-to-end.
 */
class RegistrationUpsertServiceTest extends TestCase
{
    use RefreshDatabase;

    private RegistrationUpsertService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(RegistrationUpsertService::class);
    }

    // -------------------------------------------------------------------------
    // Registration creation
    // -------------------------------------------------------------------------

    #[Test]
    public function it_creates_a_registration_from_a_dto(): void
    {
        $dto = $this->makeSubmissionDTO();

        $registration = $this->service->upsert($dto);

        $this->assertInstanceOf(Registration::class, $registration);
        $this->assertDatabaseHas('registrations', [
            'singapur_client_code' => '000001',
            'singapur_package_id'  => '7dde1760-57d4-4f4e-b81b-3ae2b93025d0',
            'company_type'         => 'SA de CV',
        ]);
    }

    #[Test]
    public function it_sets_stage_to_data_received_and_status_to_active_on_create(): void
    {
        $registration = $this->service->upsert($this->makeSubmissionDTO());

        $this->assertSame(RegistrationStageEnum::DATA_RECEIVED, $registration->stage);
        $this->assertSame(RegistrationStatusEnum::ACTIVE, $registration->status);
    }

    #[Test]
    public function it_does_not_create_a_duplicate_registration_on_redelivery(): void
    {
        $dto = $this->makeSubmissionDTO();

        $this->service->upsert($dto);
        $this->service->upsert($dto);

        $this->assertSame(1, Registration::where('singapur_client_code', '000001')->count());
    }

    #[Test]
    public function it_updates_package_id_on_redelivery_without_changing_stage(): void
    {
        $this->service->upsert($this->makeSubmissionDTO());

        // Simulate the notary advancing the stage manually.
        Registration::where('singapur_client_code', '000001')
            ->update(['stage' => RegistrationStageEnum::IDENTITY_VALIDATION->value]);

        $this->service->upsert($this->makeSubmissionDTO(id: 'new-uuid-from-relay'));

        $registration = Registration::where('singapur_client_code', '000001')->first();
        $this->assertSame('new-uuid-from-relay', $registration->singapur_package_id);
        $this->assertSame(RegistrationStageEnum::IDENTITY_VALIDATION, $registration->stage);
    }

    // -------------------------------------------------------------------------
    // Legal name
    // -------------------------------------------------------------------------

    #[Test]
    public function it_creates_a_priority_1_legal_name_with_status_wait(): void
    {
        $this->service->upsert($this->makeSubmissionDTO());

        $this->assertDatabaseHas('legal_names', [
            'name'     => 'NOVA CONSULTORÍA EMPRESARIAL',
            'priority' => 1,
            'status'   => LegalNameStatusEnum::WAIT->value,
        ]);
    }

    #[Test]
    public function it_does_not_overwrite_an_approved_legal_name_on_redelivery(): void
    {
        $this->service->upsert($this->makeSubmissionDTO());

        LegalName::where('priority', 1)
            ->update(['status' => LegalNameStatusEnum::APPROVED->value]);

        $this->service->upsert($this->makeSubmissionDTO(companyName: 'NOMBRE DIFERENTE SA'));

        // The approved name must be preserved.
        $this->assertDatabaseHas('legal_names', ['name' => 'NOVA CONSULTORÍA EMPRESARIAL']);
        $this->assertDatabaseMissing('legal_names', ['name' => 'NOMBRE DIFERENTE SA']);
    }

    #[Test]
    public function it_updates_a_wait_status_legal_name_on_redelivery(): void
    {
        $this->service->upsert($this->makeSubmissionDTO());
        $this->service->upsert($this->makeSubmissionDTO(companyName: 'NOMBRE CORREGIDO SA'));

        $this->assertDatabaseHas('legal_names', ['name' => 'NOMBRE CORREGIDO SA']);
        $this->assertSame(1, LegalName::count());
    }

    // -------------------------------------------------------------------------
    // Shareholders
    // -------------------------------------------------------------------------

    #[Test]
    public function it_creates_shareholders_from_the_dto(): void
    {
        $this->service->upsert($this->makeSubmissionDTO());

        $this->assertSame(2, Shareholder::count());
        $this->assertDatabaseHas('shareholders', [
            'name'                     => '吴佳鑫',
            'nationality'              => 'china',
            'participation_percentage' => '50.00',
        ]);
    }

    #[Test]
    public function it_assigns_legal_representative_role_to_first_shareholder(): void
    {
        $this->service->upsert($this->makeSubmissionDTO());

        $first = Shareholder::orderBy('created_at')->first();

        $this->assertSame(ShareholderRoleEnum::LEGAL_REPRESENTATIVE, $first->role);
    }

    #[Test]
    public function it_assigns_shareholder_role_to_subsequent_shareholders(): void
    {
        $this->service->upsert($this->makeSubmissionDTO());

        $second = Shareholder::orderBy('created_at')->skip(1)->first();

        $this->assertSame(ShareholderRoleEnum::SHAREHOLDER, $second->role);
    }

    #[Test]
    public function it_leaves_passport_number_null_since_it_arrives_as_a_document(): void
    {
        $this->service->upsert($this->makeSubmissionDTO());

        $this->assertNull(Shareholder::first()->passport_number);
    }

    #[Test]
    public function it_replaces_shareholders_on_redelivery_without_duplicating(): void
    {
        $dto = $this->makeSubmissionDTO();
        $this->service->upsert($dto);
        $this->service->upsert($dto);

        $this->assertSame(2, Shareholder::count());
    }

    // -------------------------------------------------------------------------
    // Documents
    // -------------------------------------------------------------------------

    #[Test]
    public function it_creates_document_metadata_for_each_file(): void
    {
        $this->service->upsert($this->makeSubmissionDTO());

        $this->assertSame(2, Document::count());
        $this->assertDatabaseHas('documents', [
            'name'                 => '000001__naturalTaxCertificate1__tax.pdf',
            'google_drive_file_id' => null,
            'google_drive_url'     => null,
        ]);
    }

    #[Test]
    public function it_skips_duplicate_documents_on_redelivery(): void
    {
        $dto = $this->makeSubmissionDTO();
        $this->service->upsert($dto);
        $this->service->upsert($dto);

        $this->assertSame(2, Document::count());
    }

    #[Test]
    public function it_sets_document_stage_to_data_received(): void
    {
        $this->service->upsert($this->makeSubmissionDTO());

        $this->assertSame(RegistrationStageEnum::DATA_RECEIVED, Document::first()->stage);
    }

    // -------------------------------------------------------------------------
    // Fixtures
    // -------------------------------------------------------------------------

    /**
     * Build a SingapurSubmissionDTO for use in tests.
     *
     * @param  string  $id           Submission UUID.
     * @param  string  $companyName  Proposed company name.
     * @return SingapurSubmissionDTO
     */
    private function makeSubmissionDTO(
        string $id = '7dde1760-57d4-4f4e-b81b-3ae2b93025d0',
        string $companyName = 'NOVA CONSULTORÍA EMPRESARIAL',
    ): SingapurSubmissionDTO {
        return new SingapurSubmissionDTO(
            id:                 $id,
            registrationNumber: '000001',
            companyFolderName:  '000001_NOVA CONSULTORA EMPRESARIAL',
            companyName:        $companyName,
            companyType:        'sa',
            language:           'zh',
            shareholders: [
                new SingapurShareholderDTO(
                    index:                   1,
                    type:                    'natural',
                    name:                    '吴佳鑫',
                    nationality:             'china',
                    email:                   '上海',
                    participationPercentage: 50.0,
                    isMarried:               true,
                ),
                new SingapurShareholderDTO(
                    index:                   2,
                    type:                    'natural',
                    name:                    '李锐佳',
                    nationality:             'china',
                    email:                   '上海',
                    participationPercentage: 50.0,
                    isMarried:               true,
                ),
            ],
            files: [
                SingapurFileDTO::fromArray([
                    'field'         => 'naturalTaxCertificate1',
                    'original_name' => 'JIAXIN_WU_TAX_ID.pdf',
                    'stored_name'   => 'uuid-1.pdf',
                    'relay_name'    => '000001__naturalTaxCertificate1__tax.pdf',
                    'content_type'  => 'application/pdf',
                    'size'          => 108548,
                ]),
                SingapurFileDTO::fromArray([
                    'field'         => 'naturalTaxCertificate2',
                    'original_name' => 'RUIJIA_LI_TAX_ID.pdf',
                    'stored_name'   => 'uuid-2.pdf',
                    'relay_name'    => '000001__naturalTaxCertificate2__tax.pdf',
                    'content_type'  => 'application/pdf',
                    'size'          => 108121,
                ]),
            ],
        );
    }
}

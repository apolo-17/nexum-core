<?php

namespace Tests\Unit\Services\Singapur;

use App\DTOs\SingapurSubmissionDTO;
use App\Enums\DocumentTypeEnum;
use App\Services\Singapur\SingapurSubmissionParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Unit tests for SingapurSubmissionParser.
 *
 * No I/O or DB involved — the parser is a pure data transformer.
 */
class SingapurSubmissionParserTest extends TestCase
{
    private SingapurSubmissionParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new SingapurSubmissionParser;
    }

    // -------------------------------------------------------------------------
    // Happy path
    // -------------------------------------------------------------------------

    #[Test]
    public function it_parses_a_valid_submission_and_returns_a_dto(): void
    {
        $dto = $this->parser->parse($this->sampleSubmission());

        $this->assertInstanceOf(SingapurSubmissionDTO::class, $dto);
        $this->assertSame('7dde1760-57d4-4f4e-b81b-3ae2b93025d0', $dto->id);
        $this->assertSame('000001', $dto->registrationNumber);
        $this->assertSame('000001_NOVA CONSULTORA EMPRESARIAL', $dto->companyFolderName);
        $this->assertSame('NOVA CONSULTORÍA EMPRESARIAL', $dto->companyName);
        $this->assertSame('sa', $dto->companyType);
        $this->assertSame('zh', $dto->language);
    }

    #[Test]
    public function it_resolves_sa_company_type_to_display_string(): void
    {
        $dto = $this->parser->parse($this->sampleSubmission());

        $this->assertSame('SA de CV', $dto->resolvedCompanyType());
    }

    #[Test]
    public function it_resolves_srl_company_type_to_display_string(): void
    {
        $data = $this->sampleSubmission();
        $data['fields']['companyType'] = 'srl';

        $dto = $this->parser->parse($data);

        $this->assertSame('SRL de CV', $dto->resolvedCompanyType());
    }

    #[Test]
    public function it_resolves_sapi_company_type_to_display_string(): void
    {
        $data = $this->sampleSubmission();
        $data['fields']['companyType'] = 'sapi';

        $dto = $this->parser->parse($data);

        $this->assertSame('SAPI de CV', $dto->resolvedCompanyType());
    }

    #[Test]
    public function it_parses_the_correct_number_of_shareholders(): void
    {
        $dto = $this->parser->parse($this->sampleSubmission());

        $this->assertCount(2, $dto->shareholders);
    }

    #[Test]
    public function it_parses_first_shareholder_fields_correctly(): void
    {
        $dto = $this->parser->parse($this->sampleSubmission());
        $first = $dto->shareholders[0];

        $this->assertSame(1, $first->index);
        $this->assertSame('natural', $first->type);
        $this->assertSame('吴佳鑫', $first->name);
        $this->assertSame('china', $first->nationality);
        $this->assertSame('上海', $first->email);
        $this->assertSame(50.0, $first->participationPercentage);
        $this->assertTrue($first->isMarried);
    }

    #[Test]
    public function it_parses_second_shareholder_correctly(): void
    {
        $dto = $this->parser->parse($this->sampleSubmission());
        $second = $dto->shareholders[1];

        $this->assertSame(2, $second->index);
        $this->assertSame('李锐佳', $second->name);
        $this->assertSame(50.0, $second->participationPercentage);
    }

    #[Test]
    public function it_parses_the_correct_number_of_files(): void
    {
        $dto = $this->parser->parse($this->sampleSubmission());

        $this->assertCount(8, $dto->files);
    }

    #[Test]
    public function it_maps_natural_passport_field_to_passport_document_type(): void
    {
        $data = $this->sampleSubmission();
        $data['files'] = [$this->makeFile('naturalPassport1', 'passport.pdf')];

        $dto = $this->parser->parse($data);

        $this->assertSame(DocumentTypeEnum::PASSPORT, $dto->files[0]->documentType());
    }

    #[Test]
    public function it_maps_natural_tax_certificate_field_to_csf_document_type(): void
    {
        $data = $this->sampleSubmission();
        $data['files'] = [$this->makeFile('naturalTaxCertificate1', 'tax.pdf')];

        $dto = $this->parser->parse($data);

        $this->assertSame(DocumentTypeEnum::CSF, $dto->files[0]->documentType());
    }

    #[Test]
    public function it_maps_unknown_field_to_other_document_type(): void
    {
        $data = $this->sampleSubmission();
        $data['files'] = [$this->makeFile('naturalMarriageCertificate1', 'marriage.pdf')];

        $dto = $this->parser->parse($data);

        $this->assertSame(DocumentTypeEnum::OTHER, $dto->files[0]->documentType());
    }

    #[Test]
    public function it_extracts_shareholder_index_from_file_field(): void
    {
        $data = $this->sampleSubmission();
        $data['files'] = [$this->makeFile('naturalTaxCertificate2', 'tax2.pdf')];

        $dto = $this->parser->parse($data);

        $this->assertSame(2, $dto->files[0]->shareholderIndex());
    }

    #[Test]
    public function it_parses_a_submission_with_zero_shareholders_without_error(): void
    {
        $data = $this->sampleSubmission();
        $data['fields']['shareholderCount'] = '0';
        $data['files'] = [];

        $dto = $this->parser->parse($data);

        $this->assertCount(0, $dto->shareholders);
        $this->assertCount(0, $dto->files);
    }

    // -------------------------------------------------------------------------
    // Validation — missing required fields
    // -------------------------------------------------------------------------

    #[Test]
    public function it_throws_when_id_is_missing(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('missing required field: id');

        $data = $this->sampleSubmission();
        unset($data['id']);

        $this->parser->parse($data);
    }

    #[Test]
    public function it_throws_when_registration_number_is_missing(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('missing required field: registration_number');

        $data = $this->sampleSubmission();
        unset($data['registration_number']);

        $this->parser->parse($data);
    }

    #[Test]
    public function it_throws_when_fields_section_is_missing(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('missing required field: fields');

        $data = $this->sampleSubmission();
        unset($data['fields']);

        $this->parser->parse($data);
    }

    // -------------------------------------------------------------------------
    // Fixtures
    // -------------------------------------------------------------------------

    /**
     * Return a sample submission.json array mirroring the real relay format.
     *
     * @return array<string, mixed>
     */
    private function sampleSubmission(): array
    {
        return [
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
                'naturalShareholderName1' => '吴佳鑫',
                'naturalShareholderEmail1' => '上海',
                'naturalSharePercentage1' => '50',
                'naturalNationality1' => 'china',
                'naturalOtherNationality1' => '',
                'naturalMarried1' => 'yes',
                'shareholderType2' => 'natural',
                'naturalShareholderName2' => '李锐佳',
                'naturalShareholderEmail2' => '上海',
                'naturalSharePercentage2' => '50',
                'naturalNationality2' => 'china',
                'naturalOtherNationality2' => '',
                'naturalMarried2' => 'yes',
                '_formId' => 'companyRegistrationForm',
                '_page' => '/company-registration/',
                '_language' => 'zh',
            ],
            'files' => [
                $this->makeFile('naturalTaxCertificate1', 'JIAXIN_WU_TAX_ID.pdf'),
                $this->makeFile('naturalProofAddress1', 'JIAXIN_WU_PROOF_OF_ADDRESS.pdf'),
                $this->makeFile('naturalMarriageCertificate1', 'JIAXIN_WU_MARRIAGE.pdf'),
                $this->makeFile('naturalSpousePassport1', 'JIAXIN_WU_SPOUSE_PASSPORT.pdf'),
                $this->makeFile('naturalTaxCertificate2', 'RUIJIA_LI_TAX_ID.pdf'),
                $this->makeFile('naturalProofAddress2', 'RUIJIA_LI_PROOF_OF_ADDRESS.pdf'),
                $this->makeFile('naturalMarriageCertificate2', 'RUIJIA_LI_MARRIAGE.pdf'),
                $this->makeFile('naturalSpousePassport2', 'RUIJIA_LI_SPOUSE_PASSPORT.pdf'),
            ],
        ];
    }

    /**
     * Build a minimal file entry array for a submission.
     *
     * @param  string  $field  Form field name.
     * @param  string  $originalName  File name.
     * @return array<string, mixed>
     */
    private function makeFile(string $field, string $originalName): array
    {
        return [
            'field' => $field,
            'original_name' => $originalName,
            'relay_name' => '000001__'.$field.'__'.$originalName,
            'size' => 100000,
            'content_type' => 'application/pdf',
            'content' => base64_encode('fake-pdf-content-for-'.$field),
        ];
    }
}

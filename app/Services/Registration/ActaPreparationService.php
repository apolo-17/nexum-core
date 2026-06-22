<?php

namespace App\Services\Registration;

use App\Enums\DocumentTypeEnum;
use App\Enums\LegalNameStatusEnum;
use App\Models\Document;
use App\Models\DocumentAnalysis;
use App\Models\LegalName;
use App\Models\Registration;
use App\Models\Shareholder;
use Illuminate\Support\Collection;

/**
 * Compiles all the template data required to generate the acta constitutiva.
 *
 * Mirrors Tally's RenderConstitutiveManager::setTemplateData() logic, adapted
 * to Nexum's data model. Data is sourced from three places:
 *
 *   1. Singapur webhook  — company_object, capital_social, shareholder basics.
 *   2. Claude vision     — passport number, gender, birthdate, birthplace,
 *                          address, nationality, matrimonial regime.
 *   3. Hardcoded         — RFC/CURP for foreign nationals, occupation, domicilio
 *                          social, comisario (Backend Bridge fixed person).
 *
 * The compiled array is stored as template_data on the ACTA_DRAFT document and
 * later used to generate the final DOCX/PDF before sending to DocuSign.
 */
class ActaPreparationService
{
    /**
     * Generic RFC assigned by SAT to all foreign natural persons in Mexico.
     */
    private const RFC_EXTRANJERO = 'EXTF900101NI1';

    /**
     * Generic CURP for foreign males (SAT standard).
     */
    private const CURP_EXTRANJERO_M = 'XEXX010101HNEXXXA4';

    /**
     * Generic CURP for foreign females (SAT standard).
     */
    private const CURP_EXTRANJERO_F = 'XEXX010101MNEXXXA8';

    /**
     * Default fiscal domicile for all Backend Bridge incorporations.
     */
    private const DOMICILIO_SOCIAL = 'la Ciudad de México';

    /**
     * Backend Bridge's fixed commissary for all incorporated companies.
     * This person acts as comisario on every acta.
     */
    private const COMISARIO_NOMBRE = 'JACOB ZAZUETA FRAUSTO';

    /**
     * RFC of the Backend Bridge commissary.
     */
    private const COMISARIO_RFC = 'ZAFJ890626DI0';

    /**
     * Compile the full template data array for a registration's acta constitutiva.
     *
     * Loads all relationships needed (shareholders, documents, document analyses,
     * legal names) in a single pass to avoid N+1 queries. Returns a structured
     * array ready to store in Document::template_data.
     *
     * @param  Registration  $registration  The expedient to compile data for.
     * @return array<string, mixed> Compiled template data.
     */
    public function compile(Registration $registration): array
    {
        $registration->loadMissing([
            'shareholders',
            'documents',
            'legalNames',
        ]);

        // Pre-load all DocumentAnalysis records for this registration's documents
        // to avoid hitting the DB once per shareholder.
        $documentIds = $registration->documents->pluck('id');
        $analyses = DocumentAnalysis::whereIn('document_id', $documentIds)
            ->where('analyzed', true)
            ->get()
            ->keyBy('document_id');

        $approvedName = $this->resolveApprovedName($registration);

        // Shareholders ordered by creation (mirrors relay index 1, 2, 3...).
        $shareholders = $registration->shareholders->values();
        $socios = [];

        foreach ($shareholders as $position => $shareholder) {
            $relayIndex = $position + 1;
            $socios[] = $this->compileShareholderData(
                registration: $registration,
                shareholder: $shareholder,
                relayIndex: $relayIndex,
                analyses: $analyses,
            );
        }

        return [
            // ----------------------------------------------------------------
            // Datos de la empresa
            // ----------------------------------------------------------------
            'autorizacion_denominacion' => $approvedName ? strtoupper($approvedName->name) : '',
            'folio_denominacion' => $approvedName?->clave_unica_denominacion ?? '',
            'fecha_denominacion' => $approvedName?->authorization_timestamp?->format('d/m/Y') ?? '',
            'company_type' => $registration->company_type ?? 'SA de CV',
            'company_activity' => $registration->company_object ?? '',
            'capital_social' => (float) ($registration->capital_social ?? 50000.00),
            'domicilio_social' => self::DOMICILIO_SOCIAL,

            // ----------------------------------------------------------------
            // Comisario (fixed Backend Bridge person)
            // ----------------------------------------------------------------
            'comisario' => self::COMISARIO_NOMBRE,
            'comisario_rfc' => self::COMISARIO_RFC,
            'comisario_extranjero' => false,

            // ----------------------------------------------------------------
            // Socios
            // ----------------------------------------------------------------
            'socios' => $socios,
            'numero_socios' => count($socios),

            // ----------------------------------------------------------------
            // Metadata for auditing
            // ----------------------------------------------------------------
            'compiled_at' => now()->toIso8601String(),
            'compiled_by_service' => self::class,
            'registration_id' => $registration->id,
            'singapur_client_code' => $registration->singapur_client_code,
        ];
    }

    // -------------------------------------------------------------------------
    // Private — per-shareholder compilation
    // -------------------------------------------------------------------------

    /**
     * Compile the template data block for a single shareholder.
     *
     * Enriches the base relay data with Claude vision extractions. Falls back to
     * relay/model values when analysis is unavailable, so the acta can always be
     * generated even if AI extraction failed for a document.
     *
     * @param  Registration  $registration  Parent expedient.
     * @param  Shareholder  $shareholder  The shareholder to compile.
     * @param  int  $relayIndex  1-based position from the relay.
     * @param  Collection<string, DocumentAnalysis>  $analyses  All analyses keyed by document_id.
     * @return array<string, mixed>
     */
    private function compileShareholderData(
        Registration $registration,
        Shareholder $shareholder,
        int $relayIndex,
        Collection $analyses,
    ): array {
        // Fetch the relevant documents for this shareholder by relay index.
        $passportDoc = $this->findDoc($registration, $relayIndex, DocumentTypeEnum::PASSPORT);
        $taxCertDoc = $this->findDoc($registration, $relayIndex, DocumentTypeEnum::KYC_TAX_CERTIFICATE);
        $addressDoc = $this->findDoc($registration, $relayIndex, DocumentTypeEnum::KYC_PROOF_OF_ADDRESS);
        $marriageDoc = $this->findDoc($registration, $relayIndex, DocumentTypeEnum::KYC_MARRIAGE_CERTIFICATE);

        // Resolve the best identity document and its analysis.
        // Passport takes precedence over tax certificate for identity extraction.
        $identityDoc = $passportDoc ?? $taxCertDoc;
        $identityAnalysis = $identityDoc ? ($analyses->get($identityDoc->id)) : null;
        $addressAnalysis = $addressDoc ? ($analyses->get($addressDoc->id)) : null;
        $marriageAnalysis = $marriageDoc ? ($analyses->get($marriageDoc->id)) : null;

        // Resolve gender — prefer Claude extraction, fall back to relay field.
        $gender = $identityAnalysis?->gender ?? $shareholder->gender ?? 'M';
        $gender = strtoupper($gender) === 'F' ? 'F' : 'M';

        // Resolve nationality in Spanish with correct grammatical gender.
        $nationality = $this->resolveNacionalidad(
            rawNationality: $identityAnalysis?->nationality ?? $shareholder->nationality ?? 'China',
            gender: $gender,
        );

        // Resolve civil status and matrimonial regime.
        $civilStatus = $shareholder->effectiveCivilStatus();
        $regimenPatrimonial = null;

        if ($marriageAnalysis && filled($marriageAnalysis->matrimonial_regime)) {
            $regimenPatrimonial = $marriageAnalysis->matrimonial_regime === 'separacion_de_bienes'
                ? 'separación de bienes'
                : 'sociedad conyugal';
        } elseif ($shareholder->is_married) {
            // Default to sociedad conyugal for Chinese marriages without explicit clause.
            $regimenPatrimonial = 'sociedad conyugal';
        }

        // Resolve birthdate — prefer Claude extraction, fall back to relay.
        $birthdate = $identityAnalysis?->birthdate ?? $shareholder->birthdate;

        // Resolve birthplace — prefer relay field, fall back to Claude extraction.
        $birthplace = filled($shareholder->birthplace)
            ? $shareholder->birthplace
            : ($identityAnalysis?->birthplace ?? '');

        // Resolve address — Claude extraction from proof of address.
        $address = $addressAnalysis?->address ?? $shareholder->address_line ?? '';
        $countryResidencia = $addressAnalysis?->country_of_residence ?? 'China';

        // Resolve document number — prefer Claude extraction, fall back to relay passport_number.
        $documentNumber = $identityAnalysis?->document_number ?? $shareholder->passport_number ?? '';
        $identityType = $passportDoc ? 'pasaporte' : 'identificación fiscal';

        // RFC and CURP are hardcoded for all foreign (Chinese) shareholders.
        $rfc = $shareholder->effectiveRfc();
        $curp = $gender === 'F' ? self::CURP_EXTRANJERO_F : self::CURP_EXTRANJERO_M;

        // Occupation is derived from gender (empresario / empresaria).
        $ocupacion = $gender === 'F' ? 'empresaria' : 'empresario';

        // Grammatically correct civil status.
        $civilStatusFormatted = $gender === 'F'
            ? $this->feminizeCivilStatus($civilStatus)
            : $civilStatus;

        return [
            // Identity
            'socio_nombre' => strtoupper($shareholder->name),
            'socio_nacionalidad' => $nationality,
            'socio_sexo' => $gender,
            'socio_estado_nacimiento' => $birthplace,
            'socio_fecha_nacimiento' => $birthdate ? $birthdate->format('d/m/Y') : '',
            'socio_estado_civil' => $civilStatusFormatted,
            'socio_regimen_patrimonial' => $regimenPatrimonial,
            'socio_ocupacion' => $ocupacion,
            'socio_calidad_migratoria' => null,
            // Document
            'socio_tipo_identificacion' => $identityType,
            'socio_tipo_identificacion_numero' => $documentNumber,
            // Contact & address
            'socio_correo' => $shareholder->email ?? '',
            'socio_direccion' => $address,
            'pais_residencia' => $countryResidencia,
            'phone' => $shareholder->phone ?? '',
            'phone_country_code' => $shareholder->phone_country_code ?? '',
            // Fiscal
            'socio_rfc' => $rfc,
            'socio_curp' => $curp,
            'tax_id' => $shareholder->tax_id ?? '',
            // Participation
            'socio_participacion' => (float) ($shareholder->participation_percentage ?? 0),
            // Role
            'is_legal_representative' => $relayIndex === 1,
            // Internal reference
            'relay_index' => $relayIndex,
            'shareholder_id' => $shareholder->id,
        ];
    }

    // -------------------------------------------------------------------------
    // Private — document lookup
    // -------------------------------------------------------------------------

    /**
     * Find a document of the given type belonging to the shareholder at relay_index.
     *
     * First tries the explicit shareholder_index column (set for documents
     * received after the 300001 migration). Falls back to parsing the index
     * from the document name for legacy records.
     *
     * @param  Registration  $registration  Parent expedient.
     * @param  int  $relayIndex  1-based shareholder position.
     * @param  DocumentTypeEnum  $type  The document type to find.
     */
    private function findDoc(
        Registration $registration,
        int $relayIndex,
        DocumentTypeEnum $type,
    ): ?Document {
        // Try the explicit column first (fast path).
        $doc = $registration->documents
            ->where('type', $type)
            ->where('shareholder_index', $relayIndex)
            ->first();

        if ($doc !== null) {
            return $doc;
        }

        // Fallback: parse the shareholder index from the relay document name.
        // Names follow the pattern: "000001__naturalPassport1__file.pdf"
        return $registration->documents
            ->where('type', $type)
            ->first(function (Document $document) use ($relayIndex): bool {
                return $this->extractIndexFromName($document->name) === $relayIndex;
            });
    }

    /**
     * Extract the trailing 1-based shareholder index from a relay document name.
     *
     * "000001__naturalTaxCertificate2__tax.pdf" → 2
     *
     * @param  string  $name  Document name as stored from the relay.
     * @return int|null Parsed index, or null if the pattern does not match.
     */
    private function extractIndexFromName(string $name): ?int
    {
        if (preg_match('/__natural\w+?(\d+)__/', $name, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Private — helpers
    // -------------------------------------------------------------------------

    /**
     * Resolve the approved denomination for this registration.
     *
     * Returns the first APPROVED denomination ordered by priority. If none is
     * approved yet, falls back to the priority-1 name so the acta can still be
     * compiled (the notary can regenerate it once approval arrives).
     *
     * @param  Registration  $registration  The expedient to check.
     */
    private function resolveApprovedName(Registration $registration): ?LegalName
    {
        return $registration->legalNames
            ->where('status', LegalNameStatusEnum::APPROVED)
            ->sortBy('priority')
            ->first()
            ?? $registration->legalNames->where('priority', 1)->first();
    }

    /**
     * Return the Spanish demonym for the given nationality string, respecting gender.
     *
     * Handles the nationalities most common in Nexum expedients. Unknown nationalities
     * are returned title-cased as-is so no data is silently lost.
     *
     * @param  string  $rawNationality  Country name in English as extracted by Claude.
     * @param  string  $gender  'M' or 'F'.
     */
    private function resolveNacionalidad(string $rawNationality, string $gender): string
    {
        $map = [
            'china' => ['chino', 'china'],
            'chinese' => ['chino', 'china'],
            'mexico' => ['mexicano', 'mexicana'],
            'mexican' => ['mexicano', 'mexicana'],
            'usa' => ['estadounidense', 'estadounidense'],
            'united states' => ['estadounidense', 'estadounidense'],
            'american' => ['estadounidense', 'estadounidense'],
            'spain' => ['español', 'española'],
            'spanish' => ['español', 'española'],
            'france' => ['francés', 'francesa'],
            'french' => ['francés', 'francesa'],
            'germany' => ['alemán', 'alemana'],
            'german' => ['alemán', 'alemana'],
            'italy' => ['italiano', 'italiana'],
            'italian' => ['italiano', 'italiana'],
            'colombia' => ['colombiano', 'colombiana'],
            'colombian' => ['colombiano', 'colombiana'],
            'argentina' => ['argentino', 'argentina'],
            'argentinian' => ['argentino', 'argentina'],
            'japan' => ['japonés', 'japonesa'],
            'japanese' => ['japonés', 'japonesa'],
            'canada' => ['canadiense', 'canadiense'],
            'canadian' => ['canadiense', 'canadiense'],
            'peru' => ['peruano', 'peruana'],
            'peruvian' => ['peruano', 'peruana'],
            'chile' => ['chileno', 'chilena'],
            'chilean' => ['chileno', 'chilena'],
            'venezuela' => ['venezolano', 'venezolana'],
            'venezuelan' => ['venezolano', 'venezolana'],
        ];

        $key = strtolower(trim($rawNationality));
        $forms = $map[$key] ?? null;

        if ($forms !== null) {
            return $gender === 'F' ? $forms[1] : $forms[0];
        }

        // Unknown nationality — return title-cased to look presentable in the acta.
        return ucwords(strtolower($rawNationality));
    }

    /**
     * Return the feminine form of a Spanish civil status string.
     *
     * Most civil status words end in 'o' for masculine and 'a' for feminine.
     * Words that are already neutral (e.g. 'divorciado' → 'divorciada') are handled.
     *
     * @param  string  $status  Masculine civil status (e.g. 'casado', 'soltero').
     */
    private function feminizeCivilStatus(string $status): string
    {
        $map = [
            'casado' => 'casada',
            'soltero' => 'soltera',
            'divorciado' => 'divorciada',
            'viudo' => 'viuda',
            'separado' => 'separada',
        ];

        return $map[strtolower($status)] ?? $status;
    }
}

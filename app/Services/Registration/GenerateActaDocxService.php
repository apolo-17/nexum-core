<?php

namespace App\Services\Registration;

use App\Enums\DocumentTypeEnum;
use App\Models\Document;
use App\Models\Registration;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use NumberFormatter;
use PhpOffice\PhpWord\TemplateProcessor;

/**
 * Generates the final acta constitutiva .docx from the compiled template_data.
 *
 * Reads the ACTA_DRAFT document's template_data, maps all fields to the
 * PhpWord TemplateProcessor placeholders in sa.docx, saves the rendered
 * file to R2 storage, and creates or updates an ACTA_FINAL Document record.
 *
 * The generated .docx includes a signature page at the end with one block per
 * socio. Each block contains a DocuSign anchor string in the format
 * "-FIRMA{n}" (e.g., "-FIRMA1", "-FIRMA2") — an ASCII-safe identifier that
 * DocuSign uses to position the SignHere tab without relying on Unicode names.
 *
 * Usage: inject and call generate($registration) from GenerateActaDocxAction.
 */
class GenerateActaDocxService
{
    /** Estatutos-style template for Sociedad Anónima de C.V. */
    private const TEMPLATE_SA = 'sa.docx';

    /** Contract-style template for Sociedad de Responsabilidad Limitada de C.V. */
    private const TEMPLATE_SRL = 'srl.docx';

    /** Default special delegate (protocolizes the acta before a notary). Editable per acta. */
    private const DELEGADO_DEFAULT_NOMBRE = 'LINDA CECILIA FAVELA MORENO';

    private const DELEGADO_DEFAULT_RFC = 'FAML020304QS1';

    /** Generic RFC the SAT assigns to foreign shareholders with no Mexican RFC. */
    private const RFC_EXTRANJERO = 'EXTF900101NI1';

    private const MESES_ES = [
        1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril', 5 => 'mayo', 6 => 'junio',
        7 => 'julio', 8 => 'agosto', 9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre',
    ];

    /**
     * Generate the .docx acta constitutiva and persist it to R2.
     *
     * @param  Registration  $registration  The expedient whose ACTA_DRAFT is used as source.
     * @return Document The created or updated ACTA_FINAL document record.
     *
     * @throws \RuntimeException When no ACTA_DRAFT exists or the template file is missing.
     */
    public function generate(Registration $registration): Document
    {
        $actaDraft = $registration->documents()
            ->where('type', DocumentTypeEnum::ACTA_DRAFT)
            ->whereNotNull('template_data')
            ->latest()
            ->first();

        if ($actaDraft === null) {
            throw new \RuntimeException('No ACTA_DRAFT with template_data found for this registration.');
        }

        $data = $actaDraft->template_data;

        $isSrl = $this->isSrl($data);
        $templateFile = $isSrl ? self::TEMPLATE_SRL : self::TEMPLATE_SA;
        $templatePath = storage_path('docs/'.$templateFile);

        if (! file_exists($templatePath)) {
            throw new \RuntimeException("Template file not found at: {$templatePath}");
        }

        Log::info("GenerateActaDocxService: filling {$templateFile} template", [
            'registration_id' => $registration->id,
            'code' => $registration->singapur_client_code,
            'company_type' => $data['company_type'] ?? null,
        ]);

        $processor = new TemplateProcessor($templatePath);

        if ($isSrl) {
            // S. de R.L. de C.V. — contract-style template (srl.docx). All placeholders are
            // single-value: 2 fixed socios + an inline apoderados list. No cloneBlock.
            $processor->setValues($this->buildSrlValues($data));
        } else {
            // S.A. de C.V. — estatutos-style template (sa.docx) with per-socio cloneBlocks.
            $processor->setValues($this->buildSingleValues($data));

            $dataPartners = $this->buildPartnersData($data);
            $processor->cloneBlock('transitionalItems', 0, true, false, $dataPartners);
            $processor->cloneBlock('rfcPartners', 0, true, false, $dataPartners);
            $processor->cloneBlock('general', 0, true, false, $dataPartners);
            // Signature page — one block per socio, with the DocuSign anchor "${socio_anchor}".
            $processor->cloneBlock('signaturePage', 0, true, false, $dataPartners);

            // Apoderados block — one clone per soldado named as legal representative.
            $dataApoderados = $this->buildApoderadosData($data);
            if ($dataApoderados !== []) {
                $processor->cloneBlock('apoderados', 0, true, false, $dataApoderados);
            }
        }

        // Persist temp file locally, upload to R2, then clean up.
        $filename = 'acta_'.$registration->singapur_client_code.'_'.now()->format('Ymd_His').'.docx';
        $tempDir = storage_path('app/temp');
        $tempPath = $tempDir.'/'.$filename;

        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        $processor->saveAs($tempPath);

        $storagePath = "documents/{$registration->id}/acta_final/{$filename}";
        Storage::disk('s3')->put($storagePath, file_get_contents($tempPath));

        @unlink($tempPath);

        // Create or update the ACTA_FINAL document record.
        $actaFinal = Document::updateOrCreate(
            [
                'registration_id' => $registration->id,
                'type' => DocumentTypeEnum::ACTA_FINAL,
            ],
            [
                'name' => $filename,
                'storage_path' => $storagePath,
                'stage' => $registration->stage,
                // Store the anchor map so DocuSignService can look up "-FIRMA1" → shareholder index.
                'template_data' => [
                    'anchor_map' => $this->buildAnchorMap($data),
                ],
            ]
        );

        Log::info('GenerateActaDocxService: ACTA_FINAL saved', [
            'document_id' => $actaFinal->id,
            'storage_path' => $storagePath,
        ]);

        return $actaFinal;
    }

    /**
     * Build the global (non-per-partner) placeholder map for setValues().
     *
     * Maps template_data fields to the single-value ${placeholder} names in sa.docx:
     * legal_name, social_objet_activity/description/products, complete_address,
     * total_shares, value_shares.
     *
     * @param  array<string, mixed>  $data  Compiled template_data from ACTA_DRAFT.
     * @return array<string, string>
     */
    private function buildSingleValues(array $data): array
    {
        $capitalSocial = (int) ($data['capital_social'] ?? 50000);
        $denominacion = strtoupper(trim($data['autorizacion_denominacion'] ?? ''));

        // company_activity may be a multi-line string. The docx uses three separate
        // placeholders (activity / description / products). Split by newline; if the
        // text is a single block, use it verbatim for all three.
        $activity = trim($data['company_activity'] ?? '');
        $parts = array_values(array_filter(explode("\n", $activity)));

        $activityPart1 = $parts[0] ?? $activity;
        $activityPart2 = $parts[1] ?? $activityPart1;
        $activityPart3 = $parts[2] ?? $activityPart1;

        return [
            'legal_name' => $denominacion.' S.A. DE C.V.',
            'social_objet_activity' => $activityPart1,
            'social_objet_description' => $activityPart2,
            'social_objet_products' => $activityPart3,
            'complete_address' => $data['domicilio_social'] ?? '',
            'total_shares' => $this->formatShares($capitalSocial),
            'value_shares' => $this->formatCapitalValue($capitalSocial),
        ];
    }

    /**
     * Whether the acta is for a Sociedad de Responsabilidad Limitada (uses srl.docx).
     *
     * Detected from the compiled company_type (e.g. "SRL de CV", "S. de R.L. de C.V.").
     *
     * @param  array<string, mixed>  $data  Compiled template_data from ACTA_DRAFT.
     */
    private function isSrl(array $data): bool
    {
        $type = strtoupper((string) ($data['company_type'] ?? ''));
        $normalized = str_replace([' ', '.'], '', $type);

        return str_contains($normalized, 'RL') || str_contains($type, 'RESPONSABILIDAD');
    }

    /**
     * Build the full placeholder map for the S. de R.L. de C.V. template (srl.docx).
     *
     * The template is fixed at exactly two socios; a mismatch throws so the operator sees a
     * clear error instead of a malformed acta. The apoderados (3–4 legal representatives from
     * ApoderadoAssignmentService) are rendered inline as a single list, and the special
     * delegate defaults to the recurring one but can be overridden per acta via template_data.
     *
     * @param  array<string, mixed>  $data  Compiled template_data from ACTA_DRAFT.
     * @return array<string, string>
     *
     * @throws \RuntimeException When the expediente does not have exactly two socios.
     */
    /**
     * Public, lenient version of buildSrlValues for the on-screen HTML preview: never throws
     * on a socio-count mismatch (pads to two) so an incomplete expediente can still be previewed.
     *
     * @param  array<string, mixed>  $data  Compiled template_data from ACTA_DRAFT.
     * @return array<string, string>
     */
    public function previewValues(array $data): array
    {
        return $this->buildSrlValues($data, strict: false);
    }

    private function buildSrlValues(array $data, bool $strict = true): array
    {
        $socios = array_values($data['socios'] ?? []);

        if ($strict && count($socios) !== 2) {
            throw new \RuntimeException(
                'La plantilla S. de R.L. de C.V. requiere exactamente 2 socios; este expediente tiene '
                .count($socios).'. Revisa el expediente antes de generar el acta.'
            );
        }

        // Preview mode may run with 0, 1 or 3+ socios; pad to two so the mapping never errors.
        $socios = array_pad(array_slice($socios, 0, 2), 2, []);

        $cud = $this->dateParts($data['fecha_denominacion'] ?? '');

        $values = [
            'company_name' => mb_strtoupper(trim((string) ($data['autorizacion_denominacion'] ?? '')), 'UTF-8'),
            'CUD' => (string) ($data['folio_denominacion'] ?? ''),
            'CUD_day' => $cud['day'],
            'CUD_day_words' => $cud['day_words'],
            'CUD_month' => $cud['month'],
            'CUD_year' => $cud['year'],
            'CUD_year_words' => $cud['year_words'],
            'apoderados_lista' => $this->buildApoderadosLista($data),
            'delegado_nombre' => mb_strtoupper((string) ($data['delegado_nombre'] ?? self::DELEGADO_DEFAULT_NOMBRE), 'UTF-8'),
            'delegado_rfc' => strtoupper((string) ($data['delegado_rfc'] ?? self::DELEGADO_DEFAULT_RFC)),
        ];

        return array_merge(
            $values,
            $this->shareholderValues('shareholder1', $socios[0]),
            $this->shareholderValues('shareholder2', $socios[1]),
        );
    }

    /**
     * Map one socio to the srl.docx shareholderN_* placeholders.
     *
     * @param  string  $prefix  'shareholder1' or 'shareholder2'.
     * @param  array<string, mixed>  $socio  One entry of template_data['socios'].
     * @return array<string, string>
     */
    private function shareholderValues(string $prefix, array $socio): array
    {
        $birth = $this->dateParts($socio['socio_fecha_nacimiento'] ?? '');

        return [
            "{$prefix}_full_name" => mb_strtoupper((string) ($socio['socio_nombre'] ?? ''), 'UTF-8'),
            "{$prefix}_nationality" => (string) ($socio['socio_nacionalidad'] ?? ''),
            "{$prefix}_place_of_birth" => (string) ($socio['socio_estado_nacimiento'] ?? ''),
            "{$prefix}_country" => (string) ($socio['pais_residencia'] ?? 'China'),
            "{$prefix}_birth_day" => $birth['day'],
            "{$prefix}_birth_day_words" => $birth['day_words'],
            "{$prefix}_birth_month" => $birth['month'],
            "{$prefix}_birth_year" => $birth['year'],
            "{$prefix}_birth_year_words" => $birth['year_words'],
            "{$prefix}_address" => (string) ($socio['socio_direccion'] ?? ''),
            "{$prefix}_tax_id" => (string) ($socio['tax_id'] ?? ''),
            "{$prefix}_passport_number" => (string) ($socio['socio_tipo_identificacion_numero'] ?? ''),
            "{$prefix}_email" => (string) ($socio['socio_correo'] ?? ''),
        ];
    }

    /**
     * Build the inline apoderados list for the SRL "Numeral 2" special-powers clause:
     * `NOMBRE cuyo RFC es "RFC", NOMBRE cuyo RFC es "RFC", …`.
     *
     * @param  array<string, mixed>  $data  Compiled template_data from ACTA_DRAFT.
     */
    private function buildApoderadosLista(array $data): string
    {
        $apoderados = array_values($data['apoderados'] ?? []);

        $parts = array_map(function (array $a): string {
            $nombre = mb_strtoupper((string) ($a['apoderado_nombre'] ?? ''), 'UTF-8');
            $rfc = strtoupper((string) ($a['apoderado_rfc'] ?? self::RFC_EXTRANJERO));

            return $nombre.' cuyo RFC es "'.$rfc.'"';
        }, $apoderados);

        return implode(', ', array_filter($parts, fn (string $p): bool => trim($p) !== 'cuyo RFC es ""'));
    }

    /**
     * Break a `d/m/Y` date into the parts the acta spells out:
     * [day, day_words, month (name), year, year_words]. All empty when unparseable.
     *
     * @return array{day: string, day_words: string, month: string, year: string, year_words: string}
     */
    private function dateParts(string $dmy): array
    {
        $empty = ['day' => '', 'day_words' => '', 'month' => '', 'year' => '', 'year_words' => ''];

        $dmy = trim($dmy);
        if ($dmy === '' || ! preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $dmy, $m)) {
            return $empty;
        }

        [$day, $month, $year] = [(int) $m[1], (int) $m[2], (int) $m[3]];

        if ($month < 1 || $month > 12) {
            return $empty;
        }

        return [
            'day' => (string) $day,
            'day_words' => $this->spellCardinal($day),
            'month' => self::MESES_ES[$month],
            'year' => (string) $year,
            'year_words' => $this->spellCardinal($year),
        ];
    }

    /**
     * Spell an integer in lowercase Spanish (masculine cardinal), e.g. 26 → "veintiséis".
     */
    private function spellCardinal(int $amount): string
    {
        $formatter = new NumberFormatter('es_MX', NumberFormatter::SPELLOUT);

        return (string) $formatter->format($amount);
    }

    /**
     * Build the per-apoderado arrays for the ${apoderados} cloneBlock.
     *
     * Each element is one clone with the placeholders the template block expects:
     * ${apoderado_indice}, ${apoderado_nombre}, ${apoderado_rfc}, ${apoderado_curp},
     * ${apoderado_correo}. Data comes from ActaPreparationService's apoderados block.
     *
     * @param  array<string, mixed>  $data  Compiled template_data from ACTA_DRAFT.
     * @return array<int, array<string, string>>
     */
    private function buildApoderadosData(array $data): array
    {
        $apoderados = array_values($data['apoderados'] ?? []);

        return array_map(fn (array $a, int $idx): array => [
            'apoderado_indice' => (string) ($idx + 1),
            'apoderado_nombre' => (string) ($a['apoderado_nombre'] ?? ''),
            'apoderado_rfc' => (string) ($a['apoderado_rfc'] ?? ''),
            'apoderado_curp' => (string) ($a['apoderado_curp'] ?? ''),
            'apoderado_correo' => (string) ($a['apoderado_correo'] ?? ''),
        ], $apoderados, array_keys($apoderados));
    }

    /**
     * Build the per-partner arrays for cloneBlock() calls.
     *
     * Each element maps to one clone of a block (transitionalItems, rfcPartners,
     * general, signaturePage). The shares for each partner are calculated from
     * the total capital and participation %.
     *
     * The "socio_anchor" field contains an ASCII-safe DocuSign anchor string in
     * the form "-FIRMA{n}" (e.g., "-FIRMA1"). This is what the signaturePage block
     * places in the document for DocuSign to locate each signer's tab. Using an
     * index-based identifier avoids issues with Unicode characters in Chinese names.
     *
     * @param  array<string, mixed>  $data  Compiled template_data from ACTA_DRAFT.
     * @return array<int, array<string, string>>
     */
    private function buildPartnersData(array $data): array
    {
        $capitalSocial = (int) ($data['capital_social'] ?? 50000);
        $socios = array_values($data['socios'] ?? []);

        return array_map(function (array $socio, int $idx) use ($capitalSocial): array {
            $n = $idx + 1;
            $participacion = (float) ($socio['socio_participacion'] ?? 0);
            $shares = (int) round($capitalSocial * $participacion / 100);

            $isMarried = in_array(
                strtolower($socio['socio_estado_civil'] ?? ''),
                ['casado', 'casada'],
                strict: true
            );

            // Combine identification type and number for the ${socio_tipo_identificacion} placeholder.
            $idType = $socio['socio_tipo_identificacion'] ?? '';
            $idNumber = $socio['socio_tipo_identificacion_numero'] ?? '';
            $idFull = $idNumber !== '' ? "{$idType} número {$idNumber}" : $idType;

            return [
                // Used in all four blocks.
                'socio_nombre' => strtoupper($socio['socio_nombre'] ?? ''),

                // Used in transitionalItems and general.
                'socio_acciones' => $this->formatShares($shares),
                'socio_acciones_format' => $this->formatShares($shares),
                'socio_participacion' => $this->formatCapitalValue($shares),

                // Used in rfcPartners and general.
                'socio_rfc' => strtoupper($socio['socio_rfc'] ?? 'EXTF900101NI1'),

                // Used in general.
                'estado_civil' => $socio['socio_estado_civil'] ?? '',
                'agreements' => $isMarried ? ($socio['socio_regimen_patrimonial'] ?? '') : '',
                'socio_fecha_nacimiento' => $socio['socio_fecha_nacimiento'] ?? '',
                'socio_estado_nacimiento' => $socio['socio_estado_nacimiento'] ?? '',
                'socio_curp' => strtoupper($socio['socio_curp'] ?? ''),
                'socio_direccion' => $socio['socio_direccion'] ?? '',
                'socio_tipo_identificacion' => $idFull,
                'socio_tipo_identificacion_numero' => $idNumber,

                // Extra fields present in the template partner structure.
                'socio_sexo' => $socio['socio_sexo'] ?? 'M',
                'socio_nacionalidad' => $socio['socio_nacionalidad'] ?? '',
                'socio_ocupacion' => $socio['socio_ocupacion'] ?? 'empresario',
                'socio_correo' => $socio['email'] ?? '',
                'tax_type' => $socio['tax_type'] ?? '',
                'tax_id' => $socio['tax_id'] ?? '',
                'pais_residencia' => $socio['pais_residencia'] ?? '',

                // Used in signaturePage — ASCII-safe DocuSign anchor string.
                // The leading "-" matches DocuSign's setAnchorString("-FIRMA{n}") pattern.
                'socio_anchor' => "-FIRMA{$n}",
            ];
        }, $socios, array_keys($socios));
    }

    /**
     * Build a map from DocuSign anchor string to shareholder data.
     *
     * Stored in ACTA_FINAL template_data so DocuSignService can iterate over
     * signers and look up each anchor string and email without re-deriving them.
     *
     * Example output:
     *   [
     *     'FIRMA1' => ['anchor' => '-FIRMA1', 'nombre' => 'JUAN PÉREZ', 'email' => 'juan@empresa.cn'],
     *     'FIRMA2' => ['anchor' => '-FIRMA2', 'nombre' => '吴佳鑫',     'email' => 'jiaxin@empresa.cn'],
     *   ]
     *
     * @param  array<string, mixed>  $data  Compiled template_data from ACTA_DRAFT.
     * @return array<string, array<string, string>>
     */
    private function buildAnchorMap(array $data): array
    {
        $socios = array_values($data['socios'] ?? []);
        $map = [];

        foreach ($socios as $idx => $socio) {
            $n = $idx + 1;
            $key = "FIRMA{$n}";

            $map[$key] = [
                'anchor' => "-FIRMA{$n}",
                'nombre' => strtoupper($socio['socio_nombre'] ?? ''),
                'email' => $socio['socio_correo'] ?? ($socio['email'] ?? ''),
                'rfc' => strtoupper($socio['socio_rfc'] ?? ''),
            ];
        }

        return $map;
    }

    /**
     * Format an integer as "50,000 (CINCUENTA MIL)" for use in share count placeholders.
     *
     * @param  int  $amount  Raw integer value to format.
     * @return string Formatted string with number and Spanish words in parentheses.
     */
    private function formatShares(int $amount): string
    {
        $formatter = new NumberFormatter('es_MX', NumberFormatter::SPELLOUT);
        $formatter->setTextAttribute(NumberFormatter::DEFAULT_RULESET, '%spellout-cardinal-feminine');
        $words = strtoupper((string) $formatter->format($amount));

        return number_format($amount).' ('.$words.')';
    }

    /**
     * Format an integer as "50,000.00 M.N. (CINCUENTA MIL PESOS, MONEDA NACIONAL)."
     *
     * Used for capital-value placeholders such as value_shares and socio_participacion.
     *
     * @param  int  $amount  Monetary amount in MXN pesos.
     * @return string Formatted monetary string following Mexican notarial conventions.
     */
    private function formatCapitalValue(int $amount): string
    {
        $formatter = new NumberFormatter('es_MX', NumberFormatter::SPELLOUT);
        $formatter->setTextAttribute(NumberFormatter::DEFAULT_RULESET, '%spellout-cardinal-feminine');
        $words = strtoupper((string) $formatter->format($amount));

        return number_format($amount).'.00 M.N. ('.$words.' PESOS, MONEDA NACIONAL).';
    }
}

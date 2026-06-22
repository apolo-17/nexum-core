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
 * Usage: inject and call generate($registration) from GenerateActaDocxAction.
 */
class GenerateActaDocxService
{
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

        $templatePath = storage_path('docs/sa.docx');

        if (! file_exists($templatePath)) {
            throw new \RuntimeException("Template file not found at: {$templatePath}");
        }

        Log::info('GenerateActaDocxService: filling sa.docx template', [
            'registration_id' => $registration->id,
            'code' => $registration->singapur_client_code,
        ]);

        $processor = new TemplateProcessor($templatePath);

        // Single-value replacements — fills global placeholders once.
        $processor->setValues($this->buildSingleValues($data));

        // Per-partner block cloning — each block is repeated once per socio.
        $dataPartners = $this->buildPartnersData($data);

        $processor->cloneBlock('transitionalItems', 0, true, false, $dataPartners);
        $processor->cloneBlock('rfcPartners', 0, true, false, $dataPartners);
        $processor->cloneBlock('general', 0, true, false, $dataPartners);

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
     * Build the per-partner arrays for cloneBlock() calls.
     *
     * Each element maps to one clone of a block (transitionalItems, rfcPartners, general).
     * The shares for each partner are calculated from the total capital and participation %.
     *
     * @param  array<string, mixed>  $data  Compiled template_data from ACTA_DRAFT.
     * @return array<int, array<string, string>>
     */
    private function buildPartnersData(array $data): array
    {
        $capitalSocial = (int) ($data['capital_social'] ?? 50000);
        $socios = array_values($data['socios'] ?? []);

        return array_map(function (array $socio) use ($capitalSocial): array {
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
                // Used in all three blocks.
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
            ];
        }, $socios);
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

<?php

namespace App\Services\Registration;

use App\Enums\DocumentTypeEnum;
use App\Models\Document;
use App\Models\Registration;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Builds the "relación de socios" .xlsx the SAT requires for a persona moral RFC
 * appointment (requisito 2 del acuse de cita).
 *
 * The SAT wants a plain list of every socio / member of the corporate structure
 * with a valid RFC, CURP (personas físicas) and full name — cross-checked against
 * the acta constitutiva. This service reuses the data already compiled by
 * {@see ActaPreparationService} (romanized names, generic foreigner RFC/CURP,
 * participation %), derives the corporate role and the partes sociales from the
 * expedient's capital, and writes a clean, ventanilla-safe spreadsheet.
 *
 * Two entry points:
 *  - compile()  → structured array for the dashboard preview (no file written).
 *  - generate() → renders the .xlsx, stores it in R2 and records the Document.
 */
class SatShareholderRelationService
{
    /**
     * Fixed number of partes sociales used when the expedient has no capital set.
     */
    private const DEFAULT_CAPITAL = 50000;

    /**
     * @param  ActaPreparationService  $actaPreparation  Source of the compiled shareholder data.
     */
    public function __construct(
        private readonly ActaPreparationService $actaPreparation,
    ) {}

    /**
     * Compile the relación de socios into a structured array for preview.
     *
     * Returns the company identity plus one row per socio with the exact columns
     * the SAT expects. No file is written — this feeds the preview modal so the
     * team can review the data before generating the workbook.
     *
     * @param  Registration  $registration  The expedient to compile.
     * @return array{
     *     razon_social: string,
     *     denominacion: string,
     *     company_type: string,
     *     capital_social: int,
     *     total_partes: int,
     *     rows: list<array{no:int, nombre:string, rfc:string, curp:string, cargo:string, partes:int, porcentaje:float}>
     * }
     */
    public function compile(Registration $registration): array
    {
        $data = $this->actaPreparation->compile($registration);

        $denominacion = strtoupper(trim((string) ($data['autorizacion_denominacion'] ?? '')));
        $companyType = (string) ($data['company_type'] ?? 'SA de CV');
        $capital = (int) round((float) ($data['capital_social'] ?? self::DEFAULT_CAPITAL));

        // Order socios by participation (highest first) so the highest stake maps
        // to Presidente; ties keep their original relay order (legal rep first).
        $socios = array_values($data['socios'] ?? []);
        usort($socios, static function (array $a, array $b): int {
            $byShare = (float) ($b['socio_participacion'] ?? 0) <=> (float) ($a['socio_participacion'] ?? 0);

            return $byShare !== 0
                ? $byShare
                : (int) ($a['relay_index'] ?? 0) <=> (int) ($b['relay_index'] ?? 0);
        });

        $cargos = $this->cargosFor(count($socios));

        $rows = [];
        $partesAcumuladas = 0;

        foreach ($socios as $position => $socio) {
            $percentage = (float) ($socio['socio_participacion'] ?? 0);
            $partes = (int) round($capital * $percentage / 100);
            $partesAcumuladas += $partes;

            $rows[] = [
                'no' => $position + 1,
                'nombre' => strtoupper(trim((string) ($socio['socio_nombre'] ?? ''))),
                'rfc' => strtoupper(trim((string) ($socio['socio_rfc'] ?? 'EXTF900101NI1'))),
                'curp' => strtoupper(trim((string) ($socio['socio_curp'] ?? ''))),
                'cargo' => $cargos[$position] ?? 'Socio',
                'partes' => $partes,
                'porcentaje' => $percentage,
            ];
        }

        // Absorb any rounding drift into the largest holder so the partes total
        // matches the capital exactly.
        if ($rows !== [] && $partesAcumuladas !== $capital) {
            $rows[0]['partes'] += $capital - $partesAcumuladas;
        }

        return [
            'razon_social' => $this->razonSocial($denominacion, $companyType),
            'denominacion' => $denominacion,
            'company_type' => $companyType,
            'capital_social' => $capital,
            'total_partes' => $capital,
            'rows' => $rows,
        ];
    }

    /**
     * Render the relación de socios .xlsx, store it in R2, and record the Document.
     *
     * Replaces any previous SAT_SHAREHOLDER_RELATION document for the expedient
     * (updateOrCreate) so re-generating always yields a single current file.
     *
     * @param  Registration  $registration  The expedient to generate for.
     * @return Document The created or updated SAT_SHAREHOLDER_RELATION document.
     */
    public function generate(Registration $registration): Document
    {
        $compiled = $this->compile($registration);

        $spreadsheet = $this->buildSpreadsheet($compiled);

        $filename = 'relacion_socios_sat_'
            .$registration->singapur_client_code
            .'_'.now()->format('Ymd_His').'.xlsx';

        $tempDir = storage_path('app/temp');

        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        $tempPath = $tempDir.'/'.$filename;

        (new Xlsx($spreadsheet))->save($tempPath);
        $spreadsheet->disconnectWorksheets();

        $storagePath = "documents/{$registration->id}/sat_relation/{$filename}";
        Storage::disk('s3')->put($storagePath, file_get_contents($tempPath));

        @unlink($tempPath);

        $document = Document::updateOrCreate(
            [
                'registration_id' => $registration->id,
                'type' => DocumentTypeEnum::SAT_SHAREHOLDER_RELATION,
            ],
            [
                'name' => $filename,
                'storage_path' => $storagePath,
                'stage' => $registration->stage,
            ],
        );

        Log::info('SatShareholderRelationService: relación de socios generated', [
            'registration_id' => $registration->id,
            'document_id' => $document->id,
            'storage_path' => $storagePath,
        ]);

        return $document;
    }

    /**
     * Reuse the existing relación de socios if one already exists for the expedient,
     * otherwise generate it. La tabla de accionistas es la misma para la empresa, así
     * que la de la primera cita (RFC) se reutiliza en la de e.firma en vez de regenerar
     * un archivo nuevo. Solo se regenera si no hay documento o si su archivo ya no está
     * en el almacenamiento.
     *
     * @param  Registration  $registration  The expedient to get-or-generate for.
     * @return Document The existing (reused) or newly generated document.
     */
    public function getOrGenerate(Registration $registration): Document
    {
        $existing = $registration->documents()
            ->where('type', DocumentTypeEnum::SAT_SHAREHOLDER_RELATION->value)
            ->whereNotNull('storage_path')
            ->latest()
            ->first();

        if ($existing !== null && Storage::disk('s3')->exists($existing->storage_path)) {
            return $existing;
        }

        return $this->generate($registration);
    }

    /**
     * Build the styled spreadsheet from compiled data.
     *
     * @param  array<string, mixed>  $compiled  Output of compile().
     */
    private function buildSpreadsheet(array $compiled): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Relación de socios');

        $spreadsheet->getDefaultStyle()->getFont()->setName('Arial')->setSize(11);

        // -----------------------------------------------------------------
        // Title block
        // -----------------------------------------------------------------
        $sheet->setCellValue('A1', $compiled['razon_social']);
        $sheet->mergeCells('A1:G1');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(13);
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $sheet->setCellValue(
            'A2',
            'Relación de socios / integrantes de la estructura orgánica — '
            .'Inscripción en el RFC de persona moral'
        );
        $sheet->mergeCells('A2:G2');
        $sheet->getStyle('A2')->getFont()->setItalic(true)->setSize(10);
        $sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        // -----------------------------------------------------------------
        // Header row
        // -----------------------------------------------------------------
        $headerRow = 4;
        $headers = [
            'A' => 'No.',
            'B' => 'Nombre completo / Razón social',
            'C' => 'RFC',
            'D' => 'CURP',
            'E' => 'Carácter',
            'F' => 'Partes sociales',
            'G' => '%',
        ];

        foreach ($headers as $col => $label) {
            $sheet->setCellValue($col.$headerRow, $label);
        }

        $headerRange = 'A'.$headerRow.':G'.$headerRow;
        $sheet->getStyle($headerRange)->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle($headerRange)->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB('611232'); // SAT guinda
        $sheet->getStyle($headerRange)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        // -----------------------------------------------------------------
        // Data rows
        // -----------------------------------------------------------------
        $row = $headerRow + 1;
        $firstDataRow = $row;

        foreach ($compiled['rows'] as $data) {
            $sheet->setCellValue('A'.$row, $data['no']);
            $sheet->setCellValue('B'.$row, $data['nombre']);
            $sheet->setCellValue('C'.$row, $data['rfc']);
            $sheet->setCellValue('D'.$row, $data['curp']);
            $sheet->setCellValue('E'.$row, $data['cargo']);
            $sheet->setCellValue('F'.$row, $data['partes']);
            // Stored as a fraction so the 0.00% cell format renders correctly.
            $sheet->setCellValue('G'.$row, $data['porcentaje'] / 100);
            $row++;
        }

        $lastDataRow = $row - 1;

        // -----------------------------------------------------------------
        // Total row
        // -----------------------------------------------------------------
        $sheet->setCellValue('A'.$row, 'Total');
        $sheet->mergeCells('A'.$row.':E'.$row);
        $sheet->setCellValue('F'.$row, '=SUM(F'.$firstDataRow.':F'.$lastDataRow.')');
        $sheet->setCellValue('G'.$row, '=SUM(G'.$firstDataRow.':G'.$lastDataRow.')');
        $sheet->getStyle('A'.$row.':G'.$row)->getFont()->setBold(true);
        $sheet->getStyle('A'.$row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $totalRow = $row;

        // -----------------------------------------------------------------
        // Formats, borders, alignment
        // -----------------------------------------------------------------
        $sheet->getStyle('F'.$firstDataRow.':F'.$totalRow)->getNumberFormat()->setFormatCode('#,##0');
        $sheet->getStyle('G'.$firstDataRow.':G'.$totalRow)->getNumberFormat()->setFormatCode('0.00%');
        $sheet->getStyle('A'.$firstDataRow.':A'.$totalRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('C'.$firstDataRow.':E'.$lastDataRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $tableRange = 'A'.$headerRow.':G'.$totalRow;
        $sheet->getStyle($tableRange)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

        // -----------------------------------------------------------------
        // Footnote — generic RFC reminder (from the SAT acuse, requisito 6).
        // -----------------------------------------------------------------
        $noteRow = $totalRow + 2;
        $sheet->setCellValue(
            'A'.$noteRow,
            'Socios extranjeros sin obligación de inscripción usan el RFC genérico '
            .'de persona física EXTF900101NI1 (Anexo 2 RMF, requisito 6 del acuse).'
        );
        $sheet->mergeCells('A'.$noteRow.':G'.$noteRow);
        $sheet->getStyle('A'.$noteRow)->getFont()->setItalic(true)->setSize(9);

        // -----------------------------------------------------------------
        // Column widths
        // -----------------------------------------------------------------
        $widths = ['A' => 6, 'B' => 42, 'C' => 16, 'D' => 20, 'E' => 18, 'F' => 16, 'G' => 10];
        foreach ($widths as $col => $width) {
            $sheet->getColumnDimension($col)->setWidth($width);
        }

        return $spreadsheet;
    }

    /**
     * Resolve the corporate roles for the members, ordered by descending participation.
     *
     * Default rule (editable by the notary team when the acta is reviewed):
     *  - 1 socio  → Gerente Único.
     *  - 2 socios → Presidente + Secretario.
     *  - 3+ socios → Presidente + Secretario + Vocal (for the rest).
     *
     * @param  int  $count  Number of socios.
     * @return list<string> Roles indexed to match the descending-participation order.
     */
    private function cargosFor(int $count): array
    {
        if ($count <= 0) {
            return [];
        }

        if ($count === 1) {
            return ['Gerente Único'];
        }

        $cargos = ['Presidente', 'Secretario'];

        for ($i = 2; $i < $count; $i++) {
            $cargos[] = 'Vocal';
        }

        return $cargos;
    }

    /**
     * Build the full razón social from the approved denomination and company type.
     *
     * Appends the corporate suffix (S.A. DE C.V. / S. DE R.L. DE C.V. / S.A.P.I. DE C.V.)
     * only when the denomination does not already carry one.
     *
     * @param  string  $denominacion  Approved denomination (upper-cased core name).
     * @param  string  $companyType  Raw company type from the expedient.
     */
    private function razonSocial(string $denominacion, string $companyType): string
    {
        if ($denominacion === '') {
            return '';
        }

        // Already carries a corporate suffix — leave it untouched.
        if (preg_match('/\b(S\.?\s?A\.?|R\.?\s?L\.?|S\.?A\.?P\.?I\.?|C\.?\s?V\.?)\b/i', $denominacion)) {
            return $denominacion;
        }

        $normalized = strtolower(str_replace([' ', '.'], '', $companyType));

        $suffix = match (true) {
            str_contains($normalized, 'sapi') => 'S.A.P.I. DE C.V.',
            str_contains($normalized, 'srl'),
            str_contains($normalized, 'responsabilidad'),
            str_contains($normalized, 'rl') => 'S. DE R.L. DE C.V.',
            default => 'S.A. DE C.V.',
        };

        return $denominacion.' '.$suffix;
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Registration;

use App\Enums\DocumentTypeEnum;
use App\Models\Document;
use App\Models\Registration;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

/**
 * Materializes the company's e.firma as a deliverable for China, from BOTH paths that store the
 * e.firma (the soldado's "Subir e.firma" and the admin's "Manage credentials") — so it never
 * diverges and never gets forgotten (the HUA DIAN case: keys saved, but no deliverable document).
 *
 * China's relay only accepts .pdf or .zip for e_firma (not a raw .cer), so the certificate is
 * wrapped in a .zip. Creating/updating the EFIRMA document triggers the DocumentObserver, which
 * sends it to China automatically.
 */
class EfirmaDeliverableService
{
    /**
     * Build (or refresh) the e.firma .zip deliverable and its EFIRMA document for a registration.
     * No-ops when there is no stored certificate. Returns the document (or null).
     */
    public function materialize(Registration $registration): ?Document
    {
        $cer = (string) $registration->company_fiel_cer_path;
        $disk = Storage::disk();

        if (blank($cer) || ! $disk->exists($cer)) {
            return null;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'efirma_');
        $zipLocal = $tmp.'.zip';

        try {
            $zip = new ZipArchive;
            $zip->open($zipLocal, ZipArchive::CREATE | ZipArchive::OVERWRITE);
            $zip->addFromString('efirma.cer', (string) $disk->get($cer));
            $zip->close();

            $stored = 'company-credentials/efirma-'.$registration->id.'.zip';
            $disk->put($stored, (string) file_get_contents($zipLocal));
        } catch (\Throwable $e) {
            Log::warning('EfirmaDeliverableService: no se pudo armar el zip de e.firma.', [
                'registration_id' => $registration->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        } finally {
            @unlink($tmp);
            @unlink($zipLocal);
        }

        $empresa = $registration->primaryLegalName?->name ?? 'empresa';

        // updateOrCreate: si ya existía apuntando al .cer, cambia storage_path al .zip → el
        // DocumentObserver detecta el cambio y lo (re)envía a China con el formato correcto.
        return Document::updateOrCreate(
            ['registration_id' => $registration->id, 'type' => DocumentTypeEnum::EFIRMA->value],
            [
                'name' => "e.firma {$empresa}.zip",
                'storage_path' => $stored,
                'stage' => $registration->getRawOriginal('stage'),
                'verified_at' => now(),
            ],
        );
    }
}

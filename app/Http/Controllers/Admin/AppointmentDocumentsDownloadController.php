<?php

namespace App\Http\Controllers\Admin;

use App\Enums\DocumentTypeEnum;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

/**
 * Serves the documents a soldado needs for a SAT cita in a single download: the acuse
 * (acknowledgment) and the Mexican proof-of-address (comprobante de domicilio), bundled
 * as a ZIP. Gated to the notary team and the soldado who owns the appointment.
 */
class AppointmentDocumentsDownloadController extends Controller
{
    /**
     * Stream a ZIP with the appointment's acuse + comprobante de domicilio.
     *
     * @param  Appointment  $appointment  The appointment whose documents are requested.
     */
    public function download(Appointment $appointment): BinaryFileResponse|Response
    {
        $this->authorizeAccess($appointment);

        // Collect the files that exist: acuse (on the appointment) + comprobante de
        // domicilio MX (on the registration's documents).
        $files = [];

        if (filled($appointment->acknowledgment_path)) {
            $files['acuse_cita_sat.pdf'] = $appointment->acknowledgment_path;
        }

        $comprobante = $appointment->registration?->documents()
            ->where('type', DocumentTypeEnum::PROOF_OF_ADDRESS_MX->value)
            ->whereNotNull('storage_path')
            ->latest()
            ->first();

        if ($comprobante !== null) {
            $files['comprobante_domicilio.pdf'] = $comprobante->storage_path;
        }

        if ($files === []) {
            abort(Response::HTTP_NOT_FOUND, 'Esta cita aún no tiene acuse ni comprobante de domicilio.');
        }

        $zipPath = tempnam(sys_get_temp_dir(), 'cita_docs_').'.zip';
        $zip = new ZipArchive;

        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            abort(Response::HTTP_INTERNAL_SERVER_ERROR, 'No se pudo generar el archivo.');
        }

        // Stream each file from storage (R2 in prod) to a local temp file, then add it
        // by path. addFromString + Storage::get would load the ENTIRE file into a PHP
        // string (twice — the string plus the zip buffer), spiking RAM on the 1 GB
        // instance. stream_copy_to_stream copies in fixed-size chunks (flat memory),
        // and addFile reads from disk lazily at close() time.
        $tempFiles = [];

        foreach ($files as $name => $path) {
            try {
                if (! Storage::exists($path)) {
                    continue;
                }

                $source = Storage::readStream($path);

                if ($source === null) {
                    continue;
                }

                $tmp = tempnam(sys_get_temp_dir(), 'cita_doc_');
                $dest = fopen($tmp, 'wb');
                stream_copy_to_stream($source, $dest);
                fclose($dest);

                if (is_resource($source)) {
                    fclose($source);
                }

                // addFile reads the temp file when close() runs, so keep it until then.
                $zip->addFile($tmp, $name);
                $tempFiles[] = $tmp;
            } catch (\Throwable) {
                // Skip a file that cannot be read; the rest still download.
            }
        }

        $zip->close();

        // Safe to remove the temp sources now that the zip has been written.
        foreach ($tempFiles as $tmp) {
            @unlink($tmp);
        }

        $company = $appointment->registration?->primaryLegalName?->name ?? 'cita';
        $filename = 'Documentos cita SAT - '.$company.'.zip';

        return response()->download($zipPath, $filename, [
            'Content-Type' => 'application/zip',
        ])->deleteFileAfterSend(true);
    }

    /**
     * Allowed: notary-team roles, or the soldado assigned to the appointment.
     *
     * @param  Appointment  $appointment  The appointment being accessed.
     */
    private function authorizeAccess(Appointment $appointment): void
    {
        $user = Auth::user();

        if ($user === null) {
            abort(Response::HTTP_FORBIDDEN, 'No autenticado.');
        }

        $isTeam = $user->hasAnyRole(['super_admin', 'notario', 'asistente_notario']);
        $isOwnerSoldado = $appointment->soldado !== null && $appointment->soldado->user_id === $user->getKey();

        abort_unless($isTeam || $isOwnerSoldado, Response::HTTP_FORBIDDEN, 'No tienes permiso para ver estos documentos.');
    }
}

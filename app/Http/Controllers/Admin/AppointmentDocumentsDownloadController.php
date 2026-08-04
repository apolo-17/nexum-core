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

        foreach ($files as $name => $path) {
            try {
                if (Storage::exists($path)) {
                    $zip->addFromString($name, (string) Storage::get($path));
                }
            } catch (\Throwable) {
                // Skip a file that cannot be read; the rest still download.
            }
        }

        $zip->close();

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

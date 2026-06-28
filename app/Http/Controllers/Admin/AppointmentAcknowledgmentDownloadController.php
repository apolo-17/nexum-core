<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Serves the acuse (acknowledgment) file of a SAT appointment from storage.
 *
 * Acuses are stored on the default disk (R2 in production) by the appointments
 * relation manager. This controller streams them as a forced download, gated to
 * the notary-team and the soldado who owns the appointment.
 */
class AppointmentAcknowledgmentDownloadController extends Controller
{
    /**
     * Stream the appointment acuse file as a forced download.
     *
     * @param  Appointment  $appointment  The appointment whose acuse is requested.
     */
    public function download(Appointment $appointment): StreamedResponse|Response
    {
        $this->authorizeAccess($appointment);

        if (blank($appointment->acknowledgment_path)) {
            abort(Response::HTTP_NOT_FOUND, 'Esta cita no tiene acuse cargado.');
        }

        try {
            $exists = Storage::exists($appointment->acknowledgment_path);
        } catch (Throwable) {
            abort(Response::HTTP_SERVICE_UNAVAILABLE, 'No se pudo conectar al almacenamiento.');
        }

        abort_unless($exists, Response::HTTP_NOT_FOUND, 'El archivo no se encontró en el almacenamiento.');

        $filename = basename($appointment->acknowledgment_path);
        $contentType = Storage::mimeType($appointment->acknowledgment_path) ?? 'application/octet-stream';

        return Storage::response($appointment->acknowledgment_path, $filename, [
            'Content-Type' => $contentType,
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /**
     * Ensure the current user may access this appointment's acuse.
     *
     * Allowed: notary-team roles, or the soldado who is assigned to the appointment.
     * Aborts with 403 otherwise.
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

        abort_unless($isTeam || $isOwnerSoldado, Response::HTTP_FORBIDDEN, 'No tienes permiso para ver este acuse.');
    }
}

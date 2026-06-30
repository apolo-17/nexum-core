<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Soldado;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Serves a soldado's INE (credencial de elector) images from storage.
 *
 * The INE front/back files are uploaded during the soldado's invitation
 * onboarding (and editable from "Mi perfil") and stored with private
 * visibility on the default disk (R2 in production). Because the files are
 * private they cannot be linked directly; this controller streams them inline
 * so they can be embedded in the soldado detail view, gated to the notary team
 * and the soldado who owns the record.
 */
class SoldadoIneDownloadController extends Controller
{
    /**
     * Stream the requested INE side inline for browser display.
     *
     * @param  Soldado  $soldado  The soldado whose INE is requested.
     * @param  string  $side  Which side to serve: "front" or "back".
     */
    public function preview(Soldado $soldado, string $side): StreamedResponse|Response
    {
        $this->authorizeAccess($soldado);

        $path = $side === 'front' ? $soldado->ine_front_path : $soldado->ine_back_path;

        if (blank($path)) {
            abort(Response::HTTP_NOT_FOUND, 'Este soldado no tiene esta cara de la INE cargada.');
        }

        try {
            $exists = Storage::exists($path);
        } catch (Throwable) {
            abort(Response::HTTP_SERVICE_UNAVAILABLE, 'No se pudo conectar al almacenamiento.');
        }

        abort_unless($exists, Response::HTTP_NOT_FOUND, 'El archivo no se encontró en el almacenamiento.');

        $filename = basename($path);
        $contentType = Storage::mimeType($path) ?? 'application/octet-stream';

        return Storage::response($path, $filename, [
            'Content-Type' => $contentType,
            'Content-Disposition' => "inline; filename=\"{$filename}\"",
        ]);
    }

    /**
     * Ensure the current user may access this soldado's INE images.
     *
     * Allowed: notary-team roles, or the soldado who owns the record. Aborts
     * with 403 otherwise.
     *
     * @param  Soldado  $soldado  The soldado being accessed.
     */
    private function authorizeAccess(Soldado $soldado): void
    {
        $user = Auth::user();

        if ($user === null) {
            abort(Response::HTTP_FORBIDDEN, 'No autenticado.');
        }

        $isTeam = $user->hasAnyRole(['super_admin', 'notario', 'asistente_notario']);
        $isOwnerSoldado = $soldado->user_id !== null && $soldado->user_id === $user->getKey();

        abort_unless($isTeam || $isOwnerSoldado, Response::HTTP_FORBIDDEN, 'No tienes permiso para ver esta INE.');
    }
}

<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Registration;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Serves the company's safeguarded credential files (e.firma + RFC) from storage.
 *
 * The company .cer/.key files and the RFC document are stored on the default disk
 * (R2 in production) by ManageCompanyCredentialsAction. This controller streams them
 * as forced downloads, gated to the notary-team roles.
 */
class CompanyCredentialDownloadController extends Controller
{
    /**
     * Map of credential type slug → registration column holding the storage path.
     *
     * @var array<string, string>
     */
    private const TYPE_COLUMNS = [
        'cer' => 'company_fiel_cer_path',
        'key' => 'company_fiel_key_path',
        'rfc' => 'company_rfc_path',
    ];

    /**
     * Stream the requested company credential file as a forced download.
     *
     * @param  Registration  $registration  The expedient owning the file.
     * @param  string  $type  Credential type slug: cer | key | rfc.
     */
    public function download(Registration $registration, string $type): StreamedResponse|Response
    {
        $this->authorizeAccess();

        $column = self::TYPE_COLUMNS[$type] ?? null;

        abort_if($column === null, Response::HTTP_NOT_FOUND, 'Tipo de credencial no válido.');

        $path = $registration->{$column};

        if (blank($path)) {
            abort(Response::HTTP_NOT_FOUND, 'Este archivo no ha sido cargado.');
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
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /**
     * Ensure the current user belongs to the notary team before serving credentials.
     *
     * Aborts with 403 for any other authenticated session.
     */
    private function authorizeAccess(): void
    {
        $user = Auth::user();

        abort_unless(
            $user !== null && $user->hasAnyRole(['super_admin', 'notario', 'asistente_notario']),
            Response::HTTP_FORBIDDEN,
            'No tienes permiso para acceder a estas credenciales.',
        );
    }
}

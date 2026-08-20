<?php

use App\Http\Controllers\Admin\AppointmentAcknowledgmentDownloadController;
use App\Http\Controllers\Admin\CompanyCredentialDownloadController;
use App\Http\Controllers\Admin\DocumentRelayDownloadController;
use App\Http\Controllers\Admin\SoldadoIneDownloadController;
use App\Http\Middleware\EnsureCanViewApiDocs;
use App\Livewire\MiCita;
use Illuminate\Support\Facades\Route;

// Public bilingual (ES/EN) marketing landing page. Static, self-contained HTML in
// public/landing.html — served here so it lives at the clean root URL.
Route::get('/', function () {
    return response()->file(public_path('landing.html'));
});

// Bilingual API docs UI (ES default, real-time toggle to EN). The JSON specs
// themselves are served by Scramble at docs/api/{es,en}.json — see AppServiceProvider.
// Access is restricted by EnsureCanViewApiDocs: local-only, or HTTP Basic + the
// `viewApiDocs` ability (super_admin or the read-only `developer` role) in prod.
Route::middleware(EnsureCanViewApiDocs::class)
    ->get('docs/api', fn () => view('docs.api'))
    ->name('scramble.docs.ui');

// Admin panel routes — protected by Filament's standard session auth.
// Ruta 'login' que espera el middleware `auth` al redirigir sin sesión → login de Filament.
Route::get('login', fn () => redirect()->route('filament.admin.auth.login'))->name('login');

// Flujo móvil del soldado tras su cita (front limpio, sin el panel).
Route::middleware(['auth'])->get('/mi-cita', MiCita::class)->name('mi-cita');

Route::middleware(['auth'])->prefix('admin')->name('admin.')->group(function () {
    Route::get(
        'documents/{document}/relay-download',
        [DocumentRelayDownloadController::class, 'download']
    )->name('documents.relay-download');

    // Serves the file inline (Content-Disposition: inline) so it can be embedded
    // in an iframe inside the document preview modal.
    Route::get(
        'documents/{document}/preview',
        [DocumentRelayDownloadController::class, 'preview']
    )->name('documents.preview');

    // Safeguarded company credentials (e.firma .cer/.key + RFC document) download.
    Route::get(
        'registrations/{registration}/company-credentials/{type}',
        [CompanyCredentialDownloadController::class, 'download']
    )->whereIn('type', ['cer', 'key', 'rfc', 'req'])->name('company-credentials.download');

    // SAT appointment acuse (acknowledgment) download.
    Route::get(
        'appointments/{appointment}/acknowledgment',
        [AppointmentAcknowledgmentDownloadController::class, 'download']
    )->name('appointments.acknowledgment.download');

    // SAT appointment documents (acuse + comprobante de domicilio) as a single ZIP.
    Route::get(
        'appointments/{appointment}/documents',
        [\App\Http\Controllers\Admin\AppointmentDocumentsDownloadController::class, 'download']
    )->name('appointments.documents.download');

    // Soldado INE (credencial de elector) images, served inline so they can be
    // embedded in the soldado detail view. Private files, gated to the notary team.
    Route::get(
        'soldados/{soldado}/ine/{side}',
        [SoldadoIneDownloadController::class, 'preview']
    )->whereIn('side', ['front', 'back'])->name('soldados.ine.preview');
});

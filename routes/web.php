<?php

use App\Http\Controllers\Admin\AppointmentAcknowledgmentDownloadController;
use App\Http\Controllers\Admin\CompanyCredentialDownloadController;
use App\Http\Controllers\Admin\DocumentRelayDownloadController;
use App\Http\Controllers\Admin\SoldadoIneDownloadController;
use App\Http\Middleware\EnsureCanViewApiDocs;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Bilingual API docs UI (ES default, real-time toggle to EN). The JSON specs
// themselves are served by Scramble at docs/api/{es,en}.json — see AppServiceProvider.
// Access is restricted by EnsureCanViewApiDocs: local-only, or HTTP Basic + the
// `viewApiDocs` ability (super_admin or the read-only `developer` role) in prod.
Route::middleware(EnsureCanViewApiDocs::class)
    ->get('docs/api', fn () => view('docs.api'))
    ->name('scramble.docs.ui');

// Admin panel routes — protected by Filament's standard session auth.
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
    )->whereIn('type', ['cer', 'key', 'rfc'])->name('company-credentials.download');

    // SAT appointment acuse (acknowledgment) download.
    Route::get(
        'appointments/{appointment}/acknowledgment',
        [AppointmentAcknowledgmentDownloadController::class, 'download']
    )->name('appointments.acknowledgment.download');

    // Soldado INE (credencial de elector) images, served inline so they can be
    // embedded in the soldado detail view. Private files, gated to the notary team.
    Route::get(
        'soldados/{soldado}/ine/{side}',
        [SoldadoIneDownloadController::class, 'preview']
    )->whereIn('side', ['front', 'back'])->name('soldados.ine.preview');
});

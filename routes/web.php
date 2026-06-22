<?php

use App\Http\Controllers\Admin\DocumentRelayDownloadController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

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
});

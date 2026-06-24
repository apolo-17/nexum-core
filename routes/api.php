<?php

use App\Http\Controllers\Api\V3\AuthController;
use App\Http\Controllers\Api\V3\LegalNameController;
use App\Http\Controllers\Api\V3\MuaBotCallbackController;
use App\Http\Controllers\Api\V3\MuaPendingController;
use App\Http\Controllers\Api\V3\RegistrationController;
use App\Http\Controllers\Api\V3\WebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes — V3
|--------------------------------------------------------------------------
|
| All routes here are prefixed with /api automatically by Laravel.
| V3 is the single active version; V1 and V2 are not used in this project.
|
*/

Route::prefix('v3')->group(function () {

    // -------------------------------------------------------------------------
    // Public endpoints — no authentication required
    // -------------------------------------------------------------------------

    Route::prefix('auth')->group(function () {
        Route::post('login', [AuthController::class, 'login']);
    });

    // Singapur relay webhook — secured by shared secret header, not JWT
    Route::post('webhook/singapur', [WebhookController::class, 'singapur']);

    // DocuSign Connect webhook — secured by HMAC-SHA256 (X-DocuSign-Signature-1 header)
    // Configure in DocuSign Admin → Connect → Add Configuration → URL: /api/v3/webhook/docusign
    Route::post('webhook/docusign', [WebhookController::class, 'docuSign']);

    // MUA availability check — public so the Singapur relay can query before client submits names
    Route::post('legal-name/check-availability', [LegalNameController::class, 'checkAvailability']);

    // MUA bot callback — public but HMAC-secured (X-Signature header required)
    // Called by the external MUA bot when the SE resolves a denomination (approved/rejected)
    Route::post('webhook/mua-bot', [MuaBotCallbackController::class, 'handle']);

    // MUA bot pending poll — secured by X-Bot-Api-Key header
    // The bot calls this on its poll cycle to get denominations awaiting SE resolution
    Route::get('mua-bot/pending', [MuaPendingController::class, 'index']);

    // -------------------------------------------------------------------------
    // Protected endpoints — JWT required
    // -------------------------------------------------------------------------

    Route::middleware('auth:api')->group(function () {
        Route::prefix('auth')->group(function () {
            Route::get('me', [AuthController::class, 'me']);
            Route::post('logout', [AuthController::class, 'logout']);
            Route::post('refresh', [AuthController::class, 'refresh']);
        });

        // Registrations
        Route::get('registrations', [RegistrationController::class, 'index']);
        Route::get('registrations/{singapurClientCode}', [RegistrationController::class, 'show']);
        Route::post('registrations/{singapurClientCode}/advance', [RegistrationController::class, 'advance']);

        // Legal names (denominations)
        Route::post('registrations/{registration}/legal-names', [LegalNameController::class, 'store']);
        Route::delete('registrations/{registration}/legal-names/{legalName}', [LegalNameController::class, 'destroy']);
    });
});

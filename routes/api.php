<?php

use App\Http\Controllers\Api\V3\AuthController;
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

    // -------------------------------------------------------------------------
    // Protected endpoints — JWT required
    // -------------------------------------------------------------------------

    Route::middleware('auth:api')->group(function () {
        Route::prefix('auth')->group(function () {
            Route::get('me', [AuthController::class, 'me']);
            Route::post('logout', [AuthController::class, 'logout']);
            Route::post('refresh', [AuthController::class, 'refresh']);
        });

        // Registrations — read-only for the Singapur relay and notary team
        Route::get('registrations', [RegistrationController::class, 'index']);
        Route::get('registrations/{singapurClientCode}', [RegistrationController::class, 'show']);
    });
});

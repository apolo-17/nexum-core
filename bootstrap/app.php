<?php

use App\Console\Commands\SubmitDenominationsToMuaCommand;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withSchedule(function (Schedule $schedule): void {
        // mua:submit is disabled — denomination submission is now triggered manually
        // from the "Denominaciones (Pool)" dashboard (DenominationResource), since the
        // team generates and sends the names itself. The command still exists and can
        // be run on demand (`php artisan mua:submit`) as a fallback.
        // $schedule->command(SubmitDenominationsToMuaCommand::class)
        //     ->everyFiveMinutes()
        //     ->withoutOverlapping()
        //     ->runInBackground();

        // mua:poll is disabled — the MUA bot notifies us via webhook callback (POST /api/v3/webhook/mua-bot)
        // when the SE resolves a denomination. Re-enable here if a polling fallback is ever needed.
    })
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();

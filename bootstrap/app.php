<?php

use App\Console\Commands\PollMuaStatusCommand;
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
        // Submit WAIT denominations to the MUA portal every 5 minutes.
        $schedule->command(SubmitDenominationsToMuaCommand::class)
            ->everyFiveMinutes()
            ->withoutOverlapping()
            ->runInBackground();

        // Poll MUA for APPROVED / REJECTED updates 3 times a day (08:00, 14:00, 20:00 MX time).
        // Polling more often is unnecessary — SE resolves denominations in batches during business hours.
        $schedule->command(PollMuaStatusCommand::class)
            ->twiceDaily(8, 14)
            ->withoutOverlapping()
            ->runInBackground();
        $schedule->command(PollMuaStatusCommand::class)
            ->dailyAt('20:00')
            ->withoutOverlapping()
            ->runInBackground();
    })
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();

<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// MUA bot — submit pending denominations every minute.
// The command exits immediately if outside business hours or no FIEL is available.
Schedule::command('mua:submit-denominations')->everyMinute()->withoutOverlapping();

// MUA bot — poll SE portal for status updates on in-flight denominations.
Schedule::command('mua:poll-status')->everyMinute()->withoutOverlapping();

<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('health-scores:compute')->everySixHours();
Schedule::command('health-scores:compute')->dailyAt('08:00');
Schedule::command('sentiment:compute')->everyFifteenMinutes();
Schedule::command('deadlines:check')->dailyAt('09:00');
Schedule::command('digests:generate')->weeklyOn(1, '08:00');

<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Each data source syncs incrementally on its own cron (hourly by default)...
Schedule::command('cis:sync --due --queue')->everyMinute()->withoutOverlapping();

// ...and every source is fully reconciled overnight, which also catches records deleted at the source.
Schedule::command('cis:sync --all --full --queue')->dailyAt('02:00');

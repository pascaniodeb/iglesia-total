<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Integrity: the activity log chain is verified every night; a broken link fails the run.
Schedule::command('activity:verify')->dailyAt('03:00')->withoutOverlapping();

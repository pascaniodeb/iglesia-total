<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Integrity: the activity log chain is verified every night; a broken link fails the run.
Schedule::command('activity:verify')->dailyAt('03:00')->withoutOverlapping();

// Integrity: the sealed ledger is verified every night against itself and against the books.
Schedule::command('ledger:verify')->dailyAt('03:15')->withoutOverlapping();

// Accounting: the journal's arithmetic is recomputed every night.
Schedule::command('journal:verify')->dailyAt('03:05')->withoutOverlapping();

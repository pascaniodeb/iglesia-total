<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Integrity: the activity log chain is verified every night; a broken link fails the run.
Schedule::command('activity:verify')->dailyAt('03:00')->withoutOverlapping();

// Integrity: closed periods still pending a seal are sealed, then the sealed ledger is verified
// every night against itself and against the books.
Schedule::command('ledger:seal-pending')->dailyAt('03:10')->withoutOverlapping();
Schedule::command('ledger:verify')->dailyAt('03:15')->withoutOverlapping();

// Accounting: the journal's arithmetic is recomputed every night.
Schedule::command('journal:verify')->dailyAt('03:05')->withoutOverlapping();

// Treasury: official rates change on business days; synced hourly in Venezuelan office hours.
Schedule::command('treasury:sync-rates')
    ->weekdays()
    ->hourly()
    ->between('7:00', '20:00')
    ->timezone((string) config('treasury.rates.timezone'))
    ->withoutOverlapping();

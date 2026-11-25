<?php

use Illuminate\Support\Carbon;

beforeEach(function () {
    seedThreeLegacyMonths();
});

afterEach(function () {
    Carbon::setTestNow();
    dropLegacySource();
});

test('it stops at the first step that fails and does not claim a migration', function () {
    // The fixture has no old members table: the first step fails and nothing after it runs.
    $this->artisan('migrate:legacy M07A1985C0001')
        ->doesntExpectOutputToContain('Migrated')
        ->assertFailed();
});

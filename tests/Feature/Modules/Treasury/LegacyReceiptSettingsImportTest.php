<?php

use App\Modules\Integrity\Infrastructure\Models\ActivityLogEntry;
use App\Modules\Treasury\Infrastructure\Models\ReceiptSetting;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    createLegacySource();
    createLegacyTreasuryTables();
});

afterEach(function () {
    dropLegacySource();
});

test('the starting number of the book comes from the old system, once', function () {
    DB::connection('legacy')->table('receipt_settings')->insert(['id' => 1, 'start_number' => 500]);

    $this->artisan('treasury:import-legacy-receipt-settings')->assertSuccessful();
    $this->artisan('treasury:import-legacy-receipt-settings')->expectsOutputToContain('already starts at that number')->assertSuccessful();

    expect(ReceiptSetting::current()->start_number)->toBe(500)
        ->and(ActivityLogEntry::query()->where('action', 'receipt_settings.updated')->count())->toBe(1);
});

test('a dry run changes nothing and a missing row is reported', function () {
    $this->artisan('treasury:import-legacy-receipt-settings')->expectsOutputToContain('no receipt settings')->assertFailed();

    DB::connection('legacy')->table('receipt_settings')->insert(['id' => 1, 'start_number' => 500]);
    $this->artisan('treasury:import-legacy-receipt-settings', ['--dry-run' => true])->assertSuccessful();

    expect(ReceiptSetting::current()->start_number)->toBe(1);
});

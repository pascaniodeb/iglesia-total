<?php

use App\Modules\Integrity\Infrastructure\Models\ActivityLogEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    seedThreeLegacyMonths();
    $this->artisan('accounting:import-legacy-books')->assertSuccessful();
});

afterEach(function () {
    Carbon::setTestNow();
    dropLegacySource();
});

test('the new books equal the old ones on every figure', function () {
    $this->artisan('reconcile:legacy')->assertSuccessful();

    expect(ActivityLogEntry::query()->where('action', 'reconciliation.run')->count())->toBe(1);
});

test('an offering that differs in the old system is a difference', function () {
    DB::connection('legacy')->table('offerings')->where('id', 1)->update(['amount_cop' => '100000.01']);

    $this->artisan('reconcile:legacy')->assertFailed();
});

test('a manual entry that differs in the old system is a difference', function () {
    DB::connection('legacy')->table('accounting_entries')->where('id', 3)->update(['amount_cop' => '30000.01']);

    $this->artisan('reconcile:legacy')->assertFailed();
});

test('a weekly closure that differs in the old system is a difference', function () {
    DB::connection('legacy')->table('weekly_closures')->where('week_key', '2025-W01')->update(['total_cop' => '1.00']);

    $this->artisan('reconcile:legacy')->assertFailed();
});

test('an opening balance that the old system carried differently is a difference', function () {
    DB::connection('legacy')->table('accounting_entries')->where('id', 8)->update(['amount_cop' => '120000.01']);

    $this->artisan('reconcile:legacy')->assertFailed();
});

test('a stale seal of the old chain is a finding, not a difference', function () {
    DB::connection('legacy')->table('ledger_blocks')->insert([
        'id' => 1, 'height' => 1, 'kind' => 'monthly', 'period_key' => '2025-01', 'prev_hash' => 'x', 'merkle_root' => 'x', 'payload_hash' => 'x', 'hash' => 'x',
        'payload' => json_encode(['opening' => ['ves' => '0', 'usd' => '2.00', 'cop' => '10000.00'], 'income' => ['ves' => '0', 'usd' => '0', 'cop' => '100000.00'],
            'expense' => ['ves' => '0', 'usd' => '0', 'cop' => '30000.00'], 'closing' => ['ves' => '0', 'usd' => '2.00', 'cop' => '1.00'], 'byCode' => []]),
        'sealed_at' => '2025-02-01 00:00:00+00',
    ]);

    $this->artisan('reconcile:legacy')->assertSuccessful();
});

test('there is nothing to reconcile without old entries', function () {
    DB::connection('legacy')->table('accounting_entries')->delete();

    $this->artisan('reconcile:legacy')->assertFailed();
});

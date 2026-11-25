<?php

use App\Modules\Integrity\Application\Actions\CreateLedgerGenesis;
use App\Modules\Integrity\Infrastructure\Models\ActivityLogEntry;
use App\Modules\Integrity\Infrastructure\Models\LedgerBlock;
use App\Modules\Treasury\Infrastructure\Models\Offering;
use App\Modules\Treasury\Infrastructure\Models\OfferingCategory;
use App\Modules\Treasury\Infrastructure\Models\WeeklyClosure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    Carbon::setTestNow('2026-11-25 14:00:00');
    createLegacySource();
    createLegacyTreasuryTables();

    $category = OfferingCategory::factory()->create();
    // 2025-W01 is Monday 30 December 2024 to Sunday 5 January 2025.
    Offering::factory()->create(['offering_category_id' => $category->id, 'offering_date' => '2025-01-05', 'amount_cop' => '1000.00']);
    Offering::factory()->create(['offering_category_id' => $category->id, 'offering_date' => '2025-01-08', 'amount_cop' => '2000.00']);
    Offering::factory()->create(['offering_category_id' => $category->id, 'offering_date' => '2025-01-14', 'amount_cop' => '3000.00', 'amount_usd' => '10.00']);

    app(CreateLedgerGenesis::class)('M07A1985C0001', '2025-W01');

    legacyWeeklyClosure(1, '2025-W01', '2024-12-30', ['total_cop' => '1000.00']);
    legacyWeeklyClosure(2, '2025-W02', '2025-01-06', ['total_cop' => '2000.00']);
    legacyWeeklyClosure(3, '2025-W03', '2025-01-13', ['total_cop' => '3000.00', 'total_usd' => '10.00']);
});

afterEach(function () {
    Carbon::setTestNow();
    dropLegacySource();
});

test('the weeks close in order, each equal to the old system, and each one is sealed', function () {
    $this->artisan('treasury:import-legacy-closures')->assertSuccessful();

    expect(WeeklyClosure::query()->orderBy('week_start')->pluck('week_key')->all())->toBe(['2025-W01', '2025-W02', '2025-W03'])
        ->and(LedgerBlock::query()->where('kind', 'weekly')->orderBy('height')->pluck('period_key')->all())->toBe(['2025-W01', '2025-W02', '2025-W03'])
        ->and(WeeklyClosure::query()->where('week_key', '2025-W03')->sole()->total_usd)->toBe('10.00');

    $log = ActivityLogEntry::query()->where('action', 'weekly_closures.imported_legacy')->sole();

    expect($log->changes['weeks'])->toBe(3)
        ->and($log->changes['first'])->toBe('2025-W01')
        ->and($log->changes['last'])->toBe('2025-W03');
});

test('not one week is closed when a week of the old system does not match its offerings', function () {
    DB::connection('legacy')->table('weekly_closures')->where('id', 2)->update(['total_cop' => '2500.00']);
    DB::connection('legacy')->table('weekly_closures')->where('id', 3)->update(['offerings_count' => 4]);

    $this->artisan('treasury:import-legacy-closures')
        ->expectsOutputToContain('The week 2025-W02: the old system closed with COP 2500.00, the offerings imported add up to 2000.00')
        ->expectsOutputToContain('The week 2025-W03: the old system closed with 4 offerings')
        ->assertFailed();

    expect(WeeklyClosure::query()->count())->toBe(0)
        ->and(LedgerBlock::query()->where('kind', 'weekly')->count())->toBe(0);
});

test('weeks that skip one or do not start where the book starts are refused', function () {
    DB::connection('legacy')->table('weekly_closures')->where('id', 2)->delete();

    $this->artisan('treasury:import-legacy-closures')
        ->expectsOutputToContain('The week 2025-W03 does not follow the book: the next to close is 2025-W02')
        ->assertFailed();

    expect(WeeklyClosure::query()->count())->toBe(0);

    DB::connection('legacy')->table('weekly_closures')->where('id', 1)->delete();
    legacyWeeklyClosure(2, '2025-W02', '2025-01-06', ['total_cop' => '2000.00']);

    $this->artisan('treasury:import-legacy-closures')
        ->expectsOutputToContain('does not follow the book: the next to close is 2025-W01')
        ->assertFailed();
});

test('a week of the old system whose dates are not the ISO week is refused', function () {
    DB::connection('legacy')->table('weekly_closures')->where('id', 1)->update(['week_start' => '2025-01-01']);

    $this->artisan('treasury:import-legacy-closures')->expectsOutputToContain('not the ISO week')->assertFailed();

    expect(WeeklyClosure::query()->count())->toBe(0);
});

test('weeks the old system never closed are skipped, and nothing after a gap is invented', function () {
    DB::connection('legacy')->table('weekly_closures')->where('id', 3)->update(['status' => 'draft']);

    $this->artisan('treasury:import-legacy-closures')->expectsOutputToContain('2025-W03 is not closed in the old system')->assertSuccessful();

    expect(WeeklyClosure::query()->pluck('week_key')->sort()->values()->all())->toBe(['2025-W01', '2025-W02']);
});

test('running it again checks the closed weeks and leaves them alone', function () {
    $this->artisan('treasury:import-legacy-closures')->assertSuccessful();
    $this->artisan('treasury:import-legacy-closures')->expectsOutputToContain('Already closed (equal to the old system)')->assertSuccessful();

    expect(WeeklyClosure::query()->count())->toBe(3)
        ->and(LedgerBlock::query()->where('kind', 'weekly')->count())->toBe(3);
});

test('a dry run checks every week and closes none', function () {
    $this->artisan('treasury:import-legacy-closures', ['--dry-run' => true])->expectsOutputToContain('Dry run')->assertSuccessful();

    expect(WeeklyClosure::query()->count())->toBe(0);
});

test('the sealed ledger must be open so that each week is sealed as it closes', function () {
    DB::statement('ALTER TABLE ledger_blocks DISABLE TRIGGER ledger_blocks_no_delete');
    DB::statement('DELETE FROM ledger_blocks');
    DB::statement('ALTER TABLE ledger_blocks ENABLE TRIGGER ledger_blocks_no_delete');

    $this->artisan('treasury:import-legacy-closures')->expectsOutputToContain('ledger:genesis --continue-legacy')->assertFailed();

    expect(WeeklyClosure::query()->count())->toBe(0);
});

<?php

use App\Modules\Accounting\Application\Ledger;
use App\Modules\Accounting\Application\Queries\MonthSummary;
use App\Modules\Accounting\Domain\Journal\JournalEntryDraft;
use App\Modules\Accounting\Domain\Journal\JournalLine;
use App\Modules\Accounting\Infrastructure\Models\AccountingAccount;
use App\Modules\Accounting\Infrastructure\Models\AccountingPeriod;
use App\Modules\Accounting\Infrastructure\Models\JournalEntry;
use App\Modules\Integrity\Application\Actions\CreateLedgerGenesis;
use App\Modules\Integrity\Application\Actions\VerifyLedgerChain;
use App\Modules\Integrity\Infrastructure\Models\ActivityLogEntry;
use App\Modules\Integrity\Infrastructure\Models\LedgerBlock;
use App\Modules\Shared\Money\Currency;
use App\Modules\Shared\Money\Money;
use App\Modules\Shared\Time\Month;
use App\Modules\Treasury\Application\Actions\CloseWeek;
use App\Modules\Treasury\Application\Closures\WeeklyClosureSchedule;
use App\Modules\Treasury\Infrastructure\Models\Offering;
use App\Modules\Treasury\Infrastructure\Models\OfferingCategory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
 * Three months of the old books, small enough to read:
 *
 *   January:  opening USD 2 + COP 10.000 · offerings COP 100.000 · expense COP 30.000   → closes COP 80.000
 *   February: opening USD 2 + COP 80.000 · offerings COP 50.000 + USD 5 · income USD 10 + COP 5.000 · expense COP 15.000
 *             → closes USD 17 + COP 120.000
 *   March:    opening USD 17 + COP 120.000 · nothing else
 *
 * The first month's opening is brought as capital; the next ones are only compared.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-11-25 14:00:00');
    createLegacySource();
    createLegacyTreasuryTables();

    $this->daily = AccountingAccount::factory()->create(['code' => 'I-002', 'name' => 'OFRENDAS DIARIAS']);
    AccountingAccount::factory()->create(['code' => 'I-007', 'name' => 'APORTES']);
    AccountingAccount::factory()->expense()->create(['code' => 'E-024', 'name' => 'SERVICIOS PÚBLICOS']);
    $category = OfferingCategory::factory()->create(['account_id' => $this->daily->id]);

    // 2025-01-05 and 2025-02-02 are Sundays.
    Offering::factory()->create(['offering_category_id' => $category->id, 'offering_date' => '2025-01-05', 'amount_cop' => '100000.00']);
    Offering::factory()->create(['offering_category_id' => $category->id, 'offering_date' => '2025-02-02', 'amount_cop' => '50000.00', 'amount_usd' => '5.00']);

    app(CreateLedgerGenesis::class)('M07A1985C0001', '2025-W01');

    // Every week up to the one with 31 March (a Monday, 2025-W14) is closed.
    while (($week = WeeklyClosureSchedule::next()) !== null && $week->key() <= '2025-W14') {
        app(CloseWeek::class)($week);
    }

    foreach ([[1, 'I-001', 'income'], [2, 'I-002', 'income'], [3, 'I-007', 'income'], [4, 'E-024', 'expense']] as [$id, $code, $type]) {
        DB::connection('legacy')->table('account_codes')->insert(['id' => $id, 'code' => $code, 'name' => "{$code} (old)", 'type' => $type]);
    }

    foreach ([[1, '2025-01', 1, '0', '0', '100000.00'], [2, '2025-02', 1, '0', '5.00', '50000.00'], [3, '2025-03', 0, '0', '0', '0']] as [$id, $month, $count, $ves, $usd, $cop]) {
        DB::connection('legacy')->table('monthly_closures')->insert(['id' => $id, 'month_key' => $month, 'offerings_count' => $count, 'total_ves' => $ves, 'total_usd' => $usd, 'total_cop' => $cop]);
    }

    legacyEntry(1, 'I-001', '2025-01-01', ['amount_usd' => '2.00', 'amount_cop' => '10000.00', 'concept' => 'Saldo anterior del mes 2025-01']);
    legacyEntry(2, 'I-002', '2025-01-31', ['amount_cop' => '100000.00', 'monthly_closure_id' => 1]);
    legacyEntry(3, 'E-024', '2025-01-15', ['amount_cop' => '30000.00', 'concept' => 'Pago de la luz']);
    legacyEntry(4, 'I-001', '2025-02-01', ['amount_usd' => '2.00', 'amount_cop' => '80000.00']);
    legacyEntry(5, 'I-002', '2025-02-28', ['amount_usd' => '5.00', 'amount_cop' => '50000.00', 'monthly_closure_id' => 2]);
    legacyEntry(6, 'I-007', '2025-02-10', ['amount_usd' => '10.00', 'amount_cop' => '5000.00', 'concept' => 'Aporte']);
    legacyEntry(7, 'E-024', '2025-02-20', ['amount_cop' => '15000.00', 'concept' => '']);
    legacyEntry(8, 'I-001', '2025-03-01', ['amount_usd' => '17.00', 'amount_cop' => '120000.00']);
    // In the old trash: not imported.
    legacyEntry(9, 'E-024', '2025-02-21', ['amount_cop' => '999.00', 'deleted_at' => '2025-02-22 00:00:00+00']);
    legacyEntry(10, 'I-001', '2025-02-01', ['amount_cop' => '1.00', 'deleted_at' => '2025-02-02 00:00:00+00']);
});

afterEach(function () {
    Carbon::setTestNow();
    dropLegacySource();
});

test('the months close in order with the old system\'s figures, each one sealed', function () {
    $this->artisan('accounting:import-legacy-books')
        ->expectsOutputToContain('1 entry · opening ✓ · by code ✓ · closing COP 80000.00')
        ->expectsOutputToContain('Skipped (in the old trash): #9')
        ->assertSuccessful();

    $closing = fn (string $month, string $currency) => MonthSummary::of(Month::fromKey($month))['currencies'][$currency]['closing'];

    expect(AccountingPeriod::query()->orderBy('month')->pluck('month')->all())->toBe(['2025-01', '2025-02', '2025-03'])
        ->and(LedgerBlock::query()->where('kind', 'monthly')->orderBy('height')->pluck('period_key')->all())->toBe(['2025-01', '2025-02', '2025-03'])
        ->and($closing('2025-01', 'COP'))->toBe('80000.00')
        ->and($closing('2025-02', 'COP'))->toBe('120000.00')
        ->and($closing('2025-02', 'USD'))->toBe('17.00')
        ->and($closing('2025-03', 'COP'))->toBe('120000.00')
        ->and(app(VerifyLedgerChain::class)()['matches_books'])->toBeTrue();
});

test('the first opening is brought as capital, an entry in two currencies stays one entry, and the origin says where it came from', function () {
    $this->artisan('accounting:import-legacy-books')->assertSuccessful();

    $opening = JournalEntry::query()->where('origin_type', 'legacy_entry')->where('origin_id', '1')->with('lines.account')->sole();
    $contribution = JournalEntry::query()->where('origin_type', 'legacy_entry')->where('origin_id', '6')->with('lines.account')->sole();

    expect($opening->origin_purpose)->toBe('opening')
        ->and($opening->entry_date->toDateString())->toBe('2025-01-01')
        ->and($opening->lines->map(fn ($line) => [$line->account->code, $line->currency->value, $line->debit, $line->credit])->all())
        ->toBe([['A-001', 'USD', '2.00', '0.00'], ['I-001', 'USD', '0.00', '2.00'], ['A-001', 'COP', '10000.00', '0.00'], ['I-001', 'COP', '0.00', '10000.00']])
        ->and(MonthSummary::of(Month::fromKey('2025-01'))['currencies']['COP']['capital'])->toBe('10000.00')
        ->and($contribution->lines)->toHaveCount(4)
        ->and($contribution->description)->toBe('Aporte')
        // Later openings (4 and 8) were compared, not imported; the trash and the consolidations neither.
        ->and(JournalEntry::query()->where('origin_type', 'legacy_entry')->pluck('origin_id')->sort()->values()->all())->toBe(['1', '3', '6', '7']);
});

test('an entry without concept takes the name of its account as description', function () {
    $this->artisan('accounting:import-legacy-books')->assertSuccessful();

    expect(JournalEntry::query()->where('origin_id', '7')->sole()->description)->toBe('E-024 (old)');
});

test('the offering income of each month comes from the new closure and equals what the old system consolidated', function () {
    $this->artisan('accounting:import-legacy-books')->assertSuccessful();

    $income = collect(MonthSummary::of(Month::fromKey('2025-02'))['by_code'])->firstWhere('code', 'I-002');

    expect($income)->toMatchArray(['usd' => '5.00', 'cop' => '50000.00'])
        // March had no offerings: its closure has nothing to post.
        ->and(JournalEntry::query()->where('origin_type', 'monthly_offering_closure')->count())->toBe(2);
});

test('running it again leaves what is closed alone', function () {
    $this->artisan('accounting:import-legacy-books')->assertSuccessful();
    $entries = JournalEntry::query()->count();

    $this->artisan('accounting:import-legacy-books')->expectsOutputToContain('already closed')->assertSuccessful();

    expect(JournalEntry::query()->count())->toBe($entries)
        ->and(ActivityLogEntry::query()->where('action', 'journal.imported_legacy')->count())->toBe(2);
});

test('it stops before closing the month whose opening differs, and what was closed stays closed', function () {
    DB::connection('legacy')->table('accounting_entries')->where('id', 4)->update(['amount_cop' => '81000.00']);

    $this->artisan('accounting:import-legacy-books')
        ->expectsOutputToContain('The month 2025-02: the old system opens with COP 81000.00, the new books derive 80000.00')
        ->assertFailed();

    expect(AccountingPeriod::isClosed('2025-01'))->toBeTrue()
        ->and(AccountingPeriod::isClosed('2025-02'))->toBeFalse();
});

test('a month is not closed when the new books hold an entry the old system does not have', function () {
    app(Ledger::class)->post(new JournalEntryDraft('2025-01-20', 'Cargado a mano en el sistema nuevo', 'manual_movement', 'typed-by-hand', 'expense', [
        JournalLine::debit('E-024', Money::of('1000.00', Currency::COP)),
        JournalLine::credit('A-001', Money::of('1000.00', Currency::COP)),
    ]));

    $this->artisan('accounting:import-legacy-books')
        ->expectsOutputToContain('E-024 in COP: the old system has 30000.00, the new books 31000.00')
        ->assertFailed();

    expect(AccountingPeriod::isClosed('2025-01'))->toBeFalse();
});

test('nothing is written when the offerings do not add up to what the old system consolidated', function () {
    DB::connection('legacy')->table('accounting_entries')->where('id', 2)->update(['amount_cop' => '99000.00']);

    $this->artisan('accounting:import-legacy-books')
        ->expectsOutputToContain('The month 2025-01, I-002, COP: the old system consolidated 99000.00, the offerings imported add up to 100000.00')
        ->assertFailed();

    expect(JournalEntry::query()->count())->toBe(0)
        ->and(AccountingPeriod::query()->count())->toBe(0);
});

test('nothing is written when the old books cannot be brought as they are', function (callable $break, string $message) {
    $break();

    $this->artisan('accounting:import-legacy-books')->expectsOutputToContain($message)->assertFailed();

    expect(JournalEntry::query()->count())->toBe(0);
})->with([
    'an account that is not in the new chart' => [
        fn () => AccountingAccount::query()->where('code', 'E-024')->update(['is_active' => false]),
        'The account E-024 used by the old entries is missing or inactive',
    ],
    'a negative amount in a manual entry' => [
        fn () => DB::connection('legacy')->table('accounting_entries')->where('id', 3)->update(['amount_cop' => '-5.00']),
        'has no positive amount or has a negative one',
    ],
    'an entry with no amount at all' => [
        fn () => DB::connection('legacy')->table('accounting_entries')->where('id', 3)->update(['amount_cop' => '0.00']),
        'has no positive amount or has a negative one',
    ],
    'two live openings in one month' => [
        fn () => legacyEntry(11, 'I-001', '2025-02-01', ['amount_cop' => '80000.00']),
        'has 2 live opening balances',
    ],
    'an income entry on an expense account' => [
        fn () => DB::connection('legacy')->table('accounting_entries')->where('id', 3)->update(['type' => 'income']),
        'is income but the account E-024 is not',
    ],
    'a negative opening of the first month' => [
        fn () => DB::connection('legacy')->table('accounting_entries')->where('id', 1)->update(['amount_cop' => '-10.00']),
        'opening balance of the first month is negative',
    ],
]);

test('a month whose weeks are not all closed stops the import', function () {
    // The old system closed April, but the weeks of April were never closed in the new one.
    DB::connection('legacy')->table('monthly_closures')->insert(['id' => 4, 'month_key' => '2025-04', 'offerings_count' => 0]);
    legacyEntry(12, 'I-001', '2025-04-01', ['amount_usd' => '17.00', 'amount_cop' => '120000.00']);

    $this->artisan('accounting:import-legacy-books')->expectsOutputToContain('is not closed yet: run treasury:import-legacy-closures first')->assertFailed();

    expect(JournalEntry::query()->count())->toBe(0);
});

test('the sealed ledger must be open', function () {
    DB::statement('ALTER TABLE ledger_blocks DISABLE TRIGGER ledger_blocks_no_delete');
    DB::statement('DELETE FROM ledger_blocks');
    DB::statement('ALTER TABLE ledger_blocks ENABLE TRIGGER ledger_blocks_no_delete');

    $this->artisan('accounting:import-legacy-books')->expectsOutputToContain('ledger:genesis --continue-legacy')->assertFailed();

    expect(JournalEntry::query()->count())->toBe(0);
});

test('a dry run checks everything and writes nothing', function () {
    $this->artisan('accounting:import-legacy-books', ['--dry-run' => true])->expectsOutputToContain('Dry run')->assertSuccessful();

    expect(JournalEntry::query()->where('origin_type', 'legacy_entry')->count())->toBe(0)
        ->and(AccountingPeriod::query()->count())->toBe(0);
});

test('months the old system never closed are closed too, and the report says so', function () {
    DB::connection('legacy')->table('monthly_closures')->where('month_key', '2025-01')->update(['status' => 'draft']);

    $this->artisan('accounting:import-legacy-books')
        ->expectsOutputToContain('Closed retroactively, because months close in order: 2025-01')
        ->assertSuccessful();

    expect(AccountingPeriod::isClosed('2025-01'))->toBeTrue();

    $log = ActivityLogEntry::query()->where('action', 'journal.imported_legacy')->sole();
    expect($log->changes['closed_without_being_closed_in_the_old_system'])->toBe(['2025-01']);
});

test('months after the last one the old system closed receive their entries and stay open', function () {
    legacyEntry(12, 'E-024', '2025-04-10', ['amount_cop' => '20000.00', 'concept' => 'Abril']);
    legacyEntry(13, 'I-001', '2025-04-01', ['amount_usd' => '17.00', 'amount_cop' => '120000.00']);

    $this->artisan('accounting:import-legacy-books')->expectsOutputToContain('1 entry · opening ✓ · by code ✓ · closing COP 100000.00')->assertSuccessful();

    expect(AccountingPeriod::isClosed('2025-03'))->toBeTrue()
        ->and(AccountingPeriod::isClosed('2025-04'))->toBeFalse()
        ->and(JournalEntry::query()->where('origin_id', '12')->sole()->month)->toBe('2025-04')
        ->and(MonthSummary::of(Month::fromKey('2025-04'))['currencies']['COP']['closing'])->toBe('100000.00');
});

test('history goes in as it is, even when the cash dips below zero and the month closes negative', function () {
    // An expense of 150.000 on 5 January, before the offerings of the month reach the journal:
    // January closes at COP -70.000, February opens with that and closes at COP -30.000.
    legacyEntry(14, 'E-024', '2025-01-05', ['amount_cop' => '150000.00', 'concept' => 'Gasto grande']);
    DB::connection('legacy')->table('accounting_entries')->where('id', 4)->update(['amount_cop' => '-70000.00']);
    DB::connection('legacy')->table('accounting_entries')->where('id', 8)->update(['amount_cop' => '-30000.00']);

    $this->artisan('accounting:import-legacy-books')->assertSuccessful();

    $summary = fn (string $month) => MonthSummary::of(Month::fromKey($month))['currencies']['COP'];

    expect($summary('2025-01')['closing'])->toBe('-70000.00')
        ->and($summary('2025-02')['opening'])->toBe('-70000.00')
        ->and($summary('2025-03')['opening'])->toBe('-30000.00')
        ->and(AccountingPeriod::isClosed('2025-03'))->toBeTrue();
});

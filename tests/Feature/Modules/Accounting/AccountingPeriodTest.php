<?php

use App\Enums\UserRole;
use App\Modules\Accounting\Application\Actions\ClosePeriod;
use App\Modules\Accounting\Application\Ledger;
use App\Modules\Accounting\Application\Queries\MonthSummary;
use App\Modules\Accounting\Domain\Journal\JournalEntryDraft;
use App\Modules\Accounting\Domain\Journal\JournalLine;
use App\Modules\Accounting\Infrastructure\Models\AccountingAccount;
use App\Modules\Accounting\Infrastructure\Models\AccountingPeriod;
use App\Modules\Integrity\Application\Actions\CreateLedgerGenesis;
use App\Modules\Integrity\Application\Actions\VerifyLedgerChain;
use App\Modules\Integrity\Domain\Ledger\BlockIssue;
use App\Modules\Integrity\Infrastructure\Models\LedgerBlock;
use App\Modules\Shared\Money\Currency;
use App\Modules\Shared\Money\Money;
use App\Modules\Shared\Time\Month;
use App\Modules\Treasury\Application\Actions\CloseMonthOfferings;
use App\Modules\Treasury\Application\Actions\CloseWeek;
use App\Modules\Treasury\Application\Closures\WeeklyClosureSchedule;
use App\Modules\Treasury\Infrastructure\Models\Offering;
use App\Modules\Treasury\Infrastructure\Models\OfferingCategory;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    // Monday 9 November 2026 in Caracas: October has ended, November has not.
    Carbon::setTestNow('2026-11-09 14:00:00');
    signInAs(UserRole::Treasurer);

    $this->income = AccountingAccount::factory()->create(['code' => 'I-002', 'name' => 'OFRENDAS DIARIAS']);
    $this->expense = AccountingAccount::factory()->expense()->create(['code' => 'E-024', 'name' => 'SERVICIOS PÚBLICOS']);
    $this->category = OfferingCategory::factory()->create(['account_id' => $this->income->id]);
});

afterEach(function () {
    Carbon::setTestNow();
});

function pesos(string $amount): Money
{
    return Money::of($amount, Currency::COP);
}

function postEntry(string $date, string $origin, array $lines): void
{
    app(Ledger::class)->post(new JournalEntryDraft($date, "Asiento {$origin}", 'test', $origin, 'test', $lines));
}

/** Runs steps 1 and 2 of October: offerings, every week closed, offerings posted. */
function settleOctoberOfferings(): void
{
    Offering::factory()->create(['offering_date' => '2026-10-04', 'offering_category_id' => test()->category->id, 'amount_cop' => '100000.00']);
    Offering::factory()->create(['offering_date' => '2026-10-20', 'offering_category_id' => test()->category->id, 'amount_cop' => '50000.50']);

    for ($week = WeeklyClosureSchedule::next(); $week !== null && $week->key() <= '2026-W44'; $week = WeeklyClosureSchedule::next()) {
        app(CloseWeek::class)($week);
    }

    app(CloseMonthOfferings::class)(Month::fromKey('2026-10'));
}

test('the whole month circuit: weeks, offerings to the journal, expenses, accounting close and seal', function () {
    app(CreateLedgerGenesis::class)('M07A1985C0001', '2026-W40');
    postEntry('2026-10-01', 'opening', [JournalLine::debit('A-001', pesos('591700.00')), JournalLine::credit('I-001', pesos('591700.00'))]);
    settleOctoberOfferings();
    postEntry('2026-10-15', 'light-bill', [JournalLine::debit('E-024', pesos('40000.00')), JournalLine::credit('A-001', pesos('40000.00'))]);

    $result = app(ClosePeriod::class)(Month::fromKey('2026-10'));
    $october = MonthSummary::of(Month::fromKey('2026-10'))['currencies']['COP'];
    $november = MonthSummary::of(Month::fromKey('2026-11'))['currencies']['COP'];

    expect($result['sealed'])->toBeTrue()
        ->and($october)->toBe([
            'opening' => '0.00',
            'income' => '150000.50',
            'expense' => '40000.00',
            'capital' => '591700.00',
            'exchange' => '0.00',
            'closing' => '701700.50',
        ])
        ->and($november['opening'])->toBe('701700.50')
        ->and(AccountingPeriod::query()->sole()->balances()->count())->toBe(4)
        ->and(LedgerBlock::query()->where('kind', 'monthly')->sole()->payload()->currencies->COP->closing)->toBe('701700.50')
        ->and(app(VerifyLedgerChain::class)()['matches_books'])->toBeTrue();
});

test('a month closes in order, only once it has ended and its offerings are settled', function () {
    Offering::factory()->create(['offering_date' => '2026-10-04', 'offering_category_id' => $this->category->id, 'amount_cop' => '1000.00']);
    postEntry('2026-10-15', 'expense', [JournalLine::debit('E-024', pesos('10.00')), JournalLine::credit('A-001', pesos('10.00'))]);

    expect(fn () => app(ClosePeriod::class)(Month::fromKey('2026-10')))->toThrow(ValidationException::class, 'paso 2');

    settleOctoberOfferings();
    expect(fn () => app(ClosePeriod::class)(Month::fromKey('2026-11')))->toThrow(ValidationException::class, 'en orden');

    app(ClosePeriod::class)(Month::fromKey('2026-10'));

    expect(fn () => app(ClosePeriod::class)(Month::fromKey('2026-10')))->toThrow(ValidationException::class)
        ->and(fn () => app(ClosePeriod::class)(Month::fromKey('2026-11')))->toThrow(ValidationException::class, 'no ha terminado');
});

test('a closed month cannot receive entries, not even from the database', function () {
    settleOctoberOfferings();
    app(ClosePeriod::class)(Month::fromKey('2026-10'));

    expect(fn () => postEntry('2026-10-31', 'late', [JournalLine::debit('E-024', pesos('1.00')), JournalLine::credit('A-001', pesos('1.00'))]))
        ->toThrow(QueryException::class, 'closed');

    postEntry('2026-11-02', 'on-time', [JournalLine::debit('E-024', pesos('1.00')), JournalLine::credit('A-001', pesos('1.00'))]);
    expect(MonthSummary::of(Month::fromKey('2026-11'))['currencies']['COP']['expense'])->toBe('1.00');
});

test('the database refuses a period out of order and a balance snapshot that is not the journal', function () {
    settleOctoberOfferings();

    expect(fn () => DB::transaction(function () {
        DB::table('accounting_periods')->insert(['month' => '2026-10']);
        DB::table('accounting_periods')->insert(['month' => '2026-12']);
    }))->toThrow(QueryException::class, 'in order');

    expect(fn () => DB::transaction(function () {
        $id = (string) Str::uuid7();
        DB::table('accounting_periods')->insert(['id' => $id, 'month' => '2026-10']);
        DB::table('accounting_period_balances')->insert([
            'accounting_period_id' => $id,
            'account_id' => AccountingAccount::query()->where('code', 'A-001')->value('id'),
            'currency' => 'COP',
            'total_debit' => '150000.49',
            'total_credit' => 0,
            'balance' => '150000.49',
        ]);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }))->toThrow(QueryException::class, 'do not match the journal');

    expect(AccountingPeriod::query()->count())->toBe(0);
});

test('periods and their balances are append-only', function () {
    settleOctoberOfferings();
    app(ClosePeriod::class)(Month::fromKey('2026-10'));

    expect(fn () => DB::transaction(fn () => DB::statement('DELETE FROM accounting_periods')))->toThrow(QueryException::class, 'append-only')
        ->and(fn () => DB::transaction(fn () => DB::statement('UPDATE accounting_period_balances SET balance = 0')))->toThrow(QueryException::class, 'append-only');
});

test('a currency exchange keeps each currency balanced and shows in the statement', function () {
    postEntry('2026-10-01', 'opening', [JournalLine::debit('A-001', Money::of('100.00', Currency::USD)), JournalLine::credit('I-001', Money::of('100.00', Currency::USD))]);
    postEntry('2026-10-10', 'exchange', [
        JournalLine::debit('A-001', pesos('360000.00')),
        JournalLine::credit('A-002', pesos('360000.00')),
        JournalLine::debit('A-002', Money::of('100.00', Currency::USD)),
        JournalLine::credit('A-001', Money::of('100.00', Currency::USD)),
    ]);

    $summary = MonthSummary::of(Month::fromKey('2026-10'))['currencies'];

    expect($summary['COP']['exchange'])->toBe('360000.00')
        ->and($summary['COP']['closing'])->toBe('360000.00')
        ->and($summary['USD']['exchange'])->toBe('-100.00')
        ->and($summary['USD']['closing'])->toBe('0.00');
});

test('without a genesis the month closes pending a seal, and a journal line changed after the seal is drift', function () {
    settleOctoberOfferings();

    expect(app(ClosePeriod::class)(Month::fromKey('2026-10'))['sealed'])->toBeFalse();

    app(CreateLedgerGenesis::class)('M07A1985C0001', '2026-W40');
    $this->artisan('ledger:seal-pending')->assertSuccessful();
    expect(LedgerBlock::query()->where('kind', 'monthly')->count())->toBe(1);

    DB::statement('ALTER TABLE journal_entry_lines DISABLE TRIGGER journal_entry_lines_no_update');
    DB::statement("UPDATE journal_entry_lines SET credit = credit + 1 WHERE credit > 0 AND currency = 'COP'");
    DB::statement('ALTER TABLE journal_entry_lines ENABLE TRIGGER journal_entry_lines_no_update');

    $issues = collect(app(VerifyLedgerChain::class)()['issues']);

    expect($issues->where('type', BlockIssue::DRIFT)->pluck('periodKey')->all())->toContain('2026-10');
});

test('a support changed after the month was sealed is drift', function () {
    app(CreateLedgerGenesis::class)('M07A1985C0001', '2026-W40');
    settleOctoberOfferings();
    DB::table('journal_entry_supports')->insert([
        'journal_entry_id' => DB::table('journal_entries')->where('month', '2026-10')->value('id'),
        'receipt_number' => 'R-0001',
    ]);
    app(ClosePeriod::class)(Month::fromKey('2026-10'));

    expect(app(VerifyLedgerChain::class)()['matches_books'])->toBeTrue();

    DB::statement('ALTER TABLE journal_entry_supports DISABLE TRIGGER journal_entry_supports_no_update');
    DB::statement("UPDATE journal_entry_supports SET receipt_number = 'R-9999'");
    DB::statement('ALTER TABLE journal_entry_supports ENABLE TRIGGER journal_entry_supports_no_update');

    $issues = collect(app(VerifyLedgerChain::class)()['issues']);

    expect($issues->where('type', BlockIssue::DRIFT)->pluck('periodKey')->all())->toContain('2026-10');
});

test('the screen shows the month to close with its statement and blocker; only who manages accounting closes it', function () {
    settleOctoberOfferings();

    $this->get(route('accounting.periods.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('accounting/periods/index')
            ->where('next.month', '2026-10')
            ->where('next.blocker', null)
            ->where('next.summary.currencies.COP.closing', '150000.50'));

    signInAs(UserRole::Comptroller);
    $this->get(route('accounting.periods.index'))->assertOk();
    $this->post(route('accounting.periods.store'), ['month' => '2026-10'])->assertForbidden();

    signInAs(UserRole::Treasurer);
    $this->post(route('accounting.periods.store'), ['month' => '2026-10'])->assertSessionHasNoErrors();

    $this->get(route('accounting.periods.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('periods.total', 1)
            ->where('periods.data.0.currencies.COP.closing', '150000.50')
            ->where('next.month', '2026-11')
            ->where('next.summary.currencies.COP.opening', '150000.50'));
});

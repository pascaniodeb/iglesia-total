<?php

use App\Enums\UserRole;
use App\Modules\Accounting\Application\Actions\ClosePeriod;
use App\Modules\Accounting\Application\Actions\ReverseJournalEntry;
use App\Modules\Accounting\Application\Ledger;
use App\Modules\Accounting\Application\Queries\GeneralLedger;
use App\Modules\Accounting\Application\Queries\MonthSummary;
use App\Modules\Accounting\Application\Queries\PeriodReport;
use App\Modules\Accounting\Domain\Journal\JournalEntryDraft;
use App\Modules\Accounting\Domain\Journal\JournalLine;
use App\Modules\Accounting\Infrastructure\Models\AccountingAccount;
use App\Modules\Accounting\Infrastructure\Models\JournalEntry;
use App\Modules\Shared\Money\Currency;
use App\Modules\Shared\Money\Money;
use App\Modules\Shared\Time\Month;
use App\Modules\Shared\Time\ReportPeriod;
use App\Modules\Treasury\Application\Actions\CloseMonthOfferings;
use App\Modules\Treasury\Application\Actions\CloseWeek;
use App\Modules\Treasury\Application\Closures\WeeklyClosureSchedule;
use App\Modules\Treasury\Infrastructure\Models\Offering;
use App\Modules\Treasury\Infrastructure\Models\OfferingCategory;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    // Wednesday 16 December 2026 in Caracas: October and November have ended.
    Carbon::setTestNow('2026-12-16 14:00:00');
    signInAs(UserRole::Treasurer);

    $this->offerings = AccountingAccount::factory()->create(['code' => 'I-002', 'name' => 'OFRENDAS DIARIAS']);
    AccountingAccount::factory()->create(['code' => 'I-007', 'name' => 'APORTES']);
    AccountingAccount::factory()->expense()->create(['code' => 'E-024', 'name' => 'SERVICIOS PÚBLICOS']);
    $this->category = OfferingCategory::factory()->create(['account_id' => $this->offerings->id]);
});

afterEach(function () {
    Carbon::setTestNow();
});

function copAmount(string $amount): Money
{
    return Money::of($amount, Currency::COP);
}

function booked(string $date, string $origin, array $lines): string
{
    return app(Ledger::class)->post(new JournalEntryDraft($date, "Asiento {$origin}", 'test', $origin, 'test', $lines))->id;
}

function bookedIncome(string $date, string $amount, string $origin): string
{
    return booked($date, $origin, [JournalLine::debit('A-001', copAmount($amount)), JournalLine::credit('I-007', copAmount($amount))]);
}

function bookedExpense(string $date, string $amount, string $origin): string
{
    return booked($date, $origin, [JournalLine::debit('E-024', copAmount($amount)), JournalLine::credit('A-001', copAmount($amount))]);
}

/** October: the opening brought from the old books, an offering, a contribution and an expense. */
function bookOctoberAndNovember(): void
{
    booked('2026-10-01', 'opening', [JournalLine::debit('A-001', copAmount('500000.00')), JournalLine::credit('I-001', copAmount('500000.00'))]);
    Offering::factory()->create(['offering_date' => '2026-10-04', 'offering_category_id' => test()->category->id, 'amount_cop' => '80000.00']);

    for ($week = WeeklyClosureSchedule::next(); $week !== null && $week->key() <= '2026-W44'; $week = WeeklyClosureSchedule::next()) {
        app(CloseWeek::class)($week);
    }

    app(CloseMonthOfferings::class)(Month::fromKey('2026-10'));
    bookedIncome('2026-10-12', '20000.00', 'oct-income');
    bookedExpense('2026-10-20', '30000.50', 'oct-expense');

    bookedIncome('2026-11-05', '45000.00', 'nov-income');
    bookedExpense('2026-11-18', '15000.00', 'nov-expense');
}

test('a quarter has one opening and one closing: monthly openings are never added up', function () {
    bookOctoberAndNovember();
    bookedIncome('2026-12-02', '10000.00', 'dec-income');

    $quarter = PeriodReport::of(ReportPeriod::fromKey('quarter', '2026-Q4'))['currencies']['COP'];
    $october = MonthSummary::of(Month::fromKey('2026-10'))['currencies']['COP'];
    $november = MonthSummary::of(Month::fromKey('2026-11'))['currencies']['COP'];
    $december = MonthSummary::of(Month::fromKey('2026-12'))['currencies']['COP'];

    expect($quarter)->toBe([
        'opening' => '0.00',
        'income' => '155000.00',
        'expense' => '45000.50',
        'capital' => '500000.00',
        'exchange' => '0.00',
        'closing' => '609999.50',
    ])
        // Each month carries the previous closing as its opening...
        ->and($november['opening'])->toBe($october['closing'])
        ->and($december['opening'])->toBe($november['closing'])
        // ...and the quarter is the sum of the months' movements, ending where December ends.
        ->and(copAmount($october['income'])->plus(copAmount($november['income']))->plus(copAmount($december['income']))->amount())->toBe($quarter['income'])
        ->and($quarter['closing'])->toBe($december['closing']);
});

test('a range reports the net of each code over its months', function () {
    bookOctoberAndNovember();

    $report = PeriodReport::of(ReportPeriod::fromKey('range', '2026-10..2026-11'));
    $codes = collect($report['by_code'])->keyBy('code');

    expect($report['label'])->toBe('Octubre – noviembre de 2026')
        ->and($codes['I-002']['cop'])->toBe('80000.00')
        ->and($codes['I-007']['cop'])->toBe('65000.00')
        ->and($codes['I-007']['entries'])->toBe(2)
        ->and($codes['E-024']['cop'])->toBe('45000.50')
        ->and($codes->has('I-001'))->toBeFalse();
});

test('a report is a draft while any of its months is open', function () {
    bookOctoberAndNovember();
    app(ClosePeriod::class)(Month::fromKey('2026-10'));

    $october = PeriodReport::of(ReportPeriod::fromKey('month', '2026-10'));
    $quarter = PeriodReport::of(ReportPeriod::fromKey('quarter', '2026-Q4'));

    expect($october['is_draft'])->toBeFalse()
        ->and($quarter['is_draft'])->toBeTrue()
        ->and($quarter['months'])->toBe([
            ['month' => '2026-10', 'is_closed' => true],
            ['month' => '2026-11', 'is_closed' => false],
            ['month' => '2026-12', 'is_closed' => false],
        ]);
});

test('the ledger of CAJA runs from the balance before the period, entry by entry', function () {
    bookOctoberAndNovember();
    $reversed = bookedExpense('2026-11-20', '1000.00', 'nov-mistake');
    app(ReverseJournalEntry::class)(JournalEntry::query()->findOrFail($reversed), 'Duplicado');

    $ledger = GeneralLedger::of(AccountingAccount::query()->where('code', 'A-001')->sole(), ReportPeriod::fromKey('month', '2026-11'));
    $entries = collect($ledger['entries']);

    expect($ledger['opening']['COP'])->toBe('569999.50')
        ->and($entries->pluck('amounts.COP')->all())->toBe(['45000.00', '-15000.00', '-1000.00', '1000.00'])
        ->and($entries->pluck('balances.COP')->all())->toBe(['614999.50', '599999.50', '598999.50', '599999.50'])
        ->and($entries[2]['is_reversed'])->toBeTrue()
        ->and($entries[3]['is_reversal'])->toBeTrue()
        ->and($ledger['movement']['COP'])->toBe('30000.00')
        ->and($ledger['closing']['COP'])->toBe(MonthSummary::of(Month::fromKey('2026-11'))['currencies']['COP']['closing']);
});

test('the ledger of an income code adds up to its row in the period report', function () {
    bookOctoberAndNovember();
    $period = ReportPeriod::fromKey('range', '2026-10..2026-11');

    $ledger = GeneralLedger::of(AccountingAccount::query()->where('code', 'I-007')->sole(), $period);
    $row = collect(PeriodReport::of($period)['by_code'])->firstWhere('code', 'I-007');

    expect($ledger['movement']['COP'])->toBe($row['cop'])
        ->and($ledger['opening']['COP'])->toBe('0.00')
        ->and($ledger['entries'])->toHaveCount(2)
        ->and(end($ledger['entries'])['balances']['COP'])->toBe('65000.00');
});

test('the screens read the period from the URL and fall back to the current month', function () {
    bookOctoberAndNovember();

    $this->get(route('accounting.reports.index', ['granularity' => 'quarter', 'period' => '2026-Q4']))
        ->assertInertia(fn (Assert $page) => $page
            ->component('accounting/reports/index')
            ->where('report.period', '2026-Q4')
            ->where('report.currencies.COP.closing', '599999.50')
            ->where('options.month.0.key', '2026-12')
            ->where('report.short_label', '4.º trimestre de 2026')
            ->where('options.quarter.0.label', '4.º trimestre de 2026')
            ->where('options.year.0.key', '2026'));

    // A shared link to a range before the first entry still shows both of its months.
    $this->get(route('accounting.reports.index', ['granularity' => 'range', 'period' => '2026-08..2026-09']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('report.label', 'Agosto – septiembre de 2026')
            ->where('options.month', fn ($months) => collect($months)->pluck('key')->intersect(['2026-08', '2026-09'])->count() === 2));

    $this->get(route('accounting.reports.index', ['granularity' => 'quarter', 'period' => 'nonsense']))
        ->assertInertia(fn (Assert $page) => $page->where('report.period', '2026-12'));

    $this->get(route('accounting.ledger.index', ['granularity' => 'month', 'period' => '2026-11']))
        ->assertInertia(fn (Assert $page) => $page
            ->component('accounting/ledger/index')
            ->where('ledger.account.code', 'A-001')
            ->where('ledger.closing.COP', '599999.50')
            ->where('period.is_draft', true)
            ->has('ledger.entries', 2));

    $this->get(route('accounting.ledger.index', ['account' => 'E-024', 'granularity' => 'year', 'period' => '2026']))
        ->assertInertia(fn (Assert $page) => $page->where('ledger.account.code', 'E-024')->where('ledger.movement.COP', '45000.50'));
});

test('the comptroller reads the ledger and the reports; the secretary does not', function () {
    signInAs(UserRole::Comptroller);
    $this->get(route('accounting.ledger.index'))->assertOk();
    $this->get(route('accounting.reports.index'))->assertOk();

    signInAs(UserRole::Secretary);
    $this->get(route('accounting.ledger.index'))->assertForbidden();
    $this->get(route('accounting.reports.index'))->assertForbidden();
});

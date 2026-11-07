<?php

use App\Enums\UserRole;
use App\Modules\Accounting\Application\Actions\VerifyJournal;
use App\Modules\Accounting\Application\Ledger;
use App\Modules\Accounting\Domain\Journal\JournalEntryDraft;
use App\Modules\Accounting\Domain\Journal\JournalLine;
use App\Modules\Accounting\Infrastructure\Models\AccountingAccount;
use App\Modules\Accounting\Infrastructure\Models\JournalEntry;
use App\Modules\Integrity\Infrastructure\Models\ActivityLogEntry;
use App\Modules\Shared\Money\Currency;
use App\Modules\Shared\Money\Money;
use App\Modules\Shared\Time\Month;
use App\Modules\Treasury\Application\Actions\CloseMonthOfferings;
use App\Modules\Treasury\Application\Actions\CloseWeek;
use App\Modules\Treasury\Application\Closures\WeeklyClosureSchedule;
use App\Modules\Treasury\Infrastructure\Models\MonthlyOfferingClosure;
use App\Modules\Treasury\Infrastructure\Models\Offering;
use App\Modules\Treasury\Infrastructure\Models\OfferingCategory;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    // Monday 9 November 2026 in Caracas: October and every week touching it (W40 to W44) have ended.
    Carbon::setTestNow('2026-11-09 14:00:00');
    signInAs(UserRole::Treasurer);

    $this->dailyAccount = AccountingAccount::factory()->create(['code' => 'I-002', 'name' => 'OFRENDAS DIARIAS']);
    $this->buildingAccount = AccountingAccount::factory()->create(['code' => 'I-004', 'name' => 'PRO-CONSTRUCCIÓN']);
    $this->general = OfferingCategory::factory()->create(['name' => 'Ofrenda general', 'account_id' => $this->dailyAccount->id]);
    $this->tithe = OfferingCategory::factory()->create(['name' => 'Ofrenda misionera', 'account_id' => $this->dailyAccount->id]);
    $this->building = OfferingCategory::factory()->create(['name' => 'Pro-construcción', 'account_id' => $this->buildingAccount->id]);
});

afterEach(function () {
    Carbon::setTestNow();
});

function octoberOffering(string $date, OfferingCategory $category, string $cop = '0.00', string $usd = '0.00'): Offering
{
    return Offering::factory()->create(['offering_date' => $date, 'offering_category_id' => $category->id, 'amount_cop' => $cop, 'amount_usd' => $usd]);
}

/** Closes, in order, every week still open up to the given one. */
function closeWeeksUntil(string $lastKey): void
{
    for ($week = WeeklyClosureSchedule::next(); $week !== null && $week->key() <= $lastKey; $week = WeeklyClosureSchedule::next()) {
        app(CloseWeek::class)($week);
    }
}

function closeOctober(): MonthlyOfferingClosure
{
    return app(CloseMonthOfferings::class)(Month::fromKey('2026-10'));
}

test('closing the month posts one income entry: CAJA debited, each income account credited, per currency', function () {
    octoberOffering('2026-09-30', $this->general, '999.00');
    octoberOffering('2026-10-04', $this->general, '100000.10', '5.00');
    octoberOffering('2026-10-15', $this->tithe, '0.20');
    octoberOffering('2026-10-31', $this->building, '50000.00');
    octoberOffering('2026-11-01', $this->general, '777.00');
    tap(octoberOffering('2026-10-20', $this->general, '3000.00'))->delete();
    closeWeeksUntil('2026-W44');

    // September is the month of the oldest offering: it closes first.
    app(CloseMonthOfferings::class)(Month::fromKey('2026-09'));
    $closure = closeOctober();

    $entry = JournalEntry::query()->with('lines.account')->findOrFail($closure->journal_entry_id);
    $lines = $entry->lines->map(fn ($line) => [$line->account->code, $line->currency->value, $line->debit, $line->credit])->all();

    expect($closure->offerings_count)->toBe(3)
        ->and($closure->total_cop)->toBe('150000.30')
        ->and($closure->total_usd)->toBe('5.00')
        ->and($entry->entry_date->toDateString())->toBe('2026-10-31')
        ->and($entry->description)->toBe('Ofrendas de octubre de 2026')
        ->and($entry->origin_type)->toBe('monthly_offering_closure')
        ->and($lines)->toEqualCanonicalizing([
            ['A-001', 'COP', '150000.30', '0.00'],
            ['I-002', 'COP', '0.00', '100000.30'],
            ['I-004', 'COP', '0.00', '50000.00'],
            ['A-001', 'USD', '5.00', '0.00'],
            ['I-002', 'USD', '0.00', '5.00'],
        ])
        ->and($closure->lines()->count())->toBe(3)
        ->and(app(VerifyJournal::class)()['passed'])->toBeTrue()
        ->and(ActivityLogEntry::query()->where('action', 'monthly_offering_closure.closed')->count())->toBe(2);
});

test('a month closes only when every week touching it is closed, including the shared ones', function () {
    octoberOffering('2026-10-04', $this->general, '1000.00');
    closeWeeksUntil('2026-W43');

    expect(fn () => closeOctober())->toThrow(ValidationException::class, '2026-W44');

    closeWeeksUntil('2026-W44');
    closeOctober();

    expect(MonthlyOfferingClosure::isClosed('2026-10'))->toBeTrue();
});

test('months close in order and only once', function () {
    octoberOffering('2026-09-30', $this->general, '1000.00');
    octoberOffering('2026-10-10', $this->general, '500.00');
    closeWeeksUntil('2026-W44');

    expect(fn () => closeOctober())->toThrow(ValidationException::class, '2026-09');

    app(CloseMonthOfferings::class)(Month::fromKey('2026-09'));
    closeOctober();

    expect(fn () => closeOctober())->toThrow(ValidationException::class);
    expect(JournalEntry::query()->count())->toBe(2);
});

test('a month without offerings closes with no entry', function () {
    octoberOffering('2026-09-30', $this->general, '1000.00');
    closeWeeksUntil('2026-W44');
    app(CloseMonthOfferings::class)(Month::fromKey('2026-09'));

    // October has no offerings of its own (the 30 Sep offering is September's).
    $closure = closeOctober();

    expect($closure->offerings_count)->toBe(0)
        ->and($closure->journal_entry_id)->toBeNull();
});

test('an inactive income account stops the close and nothing is written', function () {
    octoberOffering('2026-10-04', $this->building, '1000.00');
    closeWeeksUntil('2026-W44');
    $this->buildingAccount->forceFill(['is_active' => false])->save();

    expect(fn () => closeOctober())->toThrow(ValidationException::class, 'I-004');

    expect(MonthlyOfferingClosure::query()->count())->toBe(0)
        ->and(JournalEntry::query()->count())->toBe(0);
});

test('the database refuses a closure with open weeks or totals that are not its offerings', function () {
    octoberOffering('2026-10-04', $this->general, '1000.00');

    expect(fn () => DB::transaction(fn () => DB::table('monthly_offering_closures')->insert([
        'month' => '2026-10', 'offerings_count' => 0, 'total_ves' => 0, 'total_usd' => 0, 'total_cop' => 0,
    ])))->toThrow(QueryException::class, 'open weeks');

    closeWeeksUntil('2026-W44');

    expect(fn () => DB::transaction(fn () => DB::table('monthly_offering_closures')->insert([
        'month' => '2026-10', 'offerings_count' => 0, 'total_ves' => 0, 'total_usd' => 0, 'total_cop' => 0,
    ])))->toThrow(QueryException::class, 'does not match its offerings');
});

test('the database refuses a closure whose entry does not post its totals to CAJA', function () {
    octoberOffering('2026-10-04', $this->general, '1000.00');
    closeWeeksUntil('2026-W44');

    $other = app(Ledger::class)->post(new JournalEntryDraft(
        '2026-10-31', 'Otro asiento', 'test', 'x', 'income', [
            JournalLine::debit('A-001', Money::of('999.00', Currency::COP)),
            JournalLine::credit('I-002', Money::of('999.00', Currency::COP)),
        ],
    ));

    expect(fn () => DB::transaction(fn () => DB::table('monthly_offering_closures')->insert([
        'month' => '2026-10', 'offerings_count' => 1, 'total_ves' => 0, 'total_usd' => 0, 'total_cop' => '1000.00', 'journal_entry_id' => $other->id,
    ])))->toThrow(QueryException::class, 'does not post its totals to CAJA');
});

test('closures and their lines are append-only', function () {
    octoberOffering('2026-10-04', $this->general, '1000.00');
    closeWeeksUntil('2026-W44');
    closeOctober();

    expect(fn () => DB::transaction(fn () => DB::statement('DELETE FROM monthly_offering_closures')))->toThrow(QueryException::class, 'append-only')
        ->and(fn () => DB::transaction(fn () => DB::statement('UPDATE monthly_offering_closure_lines SET total_cop = 0')))->toThrow(QueryException::class, 'append-only');
});

test('the screen shows the next month with its weeks, what goes to each account and its blocker', function () {
    octoberOffering('2026-10-04', $this->general, '1000.00');
    octoberOffering('2026-10-05', $this->building, '2000.00');
    closeWeeksUntil('2026-W42');

    $this->get(route('treasury.monthly-offering-closures.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('treasury/monthly-offering-closures/index')
            ->where('next.month', '2026-10')
            ->where('next.snapshot.cop', '3000.00')
            ->has('next.weeks', 5)
            ->where('next.weeks.4.is_closed', false)
            ->where('next.snapshot.categories.0.account_code', 'I-002')
            ->where('next.blocker', fn (string $blocker) => str_contains($blocker, '2026-W43')));

    closeWeeksUntil('2026-W44');
    $this->post(route('treasury.monthly-offering-closures.store'), ['month' => '2026-10'])->assertSessionHasNoErrors();

    $this->get(route('treasury.monthly-offering-closures.index'))
        ->assertInertia(fn (Assert $page) => $page->where('closures.total', 1)->where('next.month', '2026-11'));
});

test('who can close a month', function (UserRole $role, int $status) {
    octoberOffering('2026-10-04', $this->general, '1000.00');
    closeWeeksUntil('2026-W44');
    signInAs($role);

    $this->post(route('treasury.monthly-offering-closures.store'), ['month' => '2026-10'])->assertStatus($status);
})->with([
    'treasurer' => [UserRole::Treasurer, 302],
    'comptroller' => [UserRole::Comptroller, 403],
]);

<?php

use App\Enums\UserRole;
use App\Modules\Accounting\Application\Actions\ClosePeriod;
use App\Modules\Accounting\Application\Actions\CorrectMovement;
use App\Modules\Accounting\Application\Actions\RecordCurrencyExchange;
use App\Modules\Accounting\Application\Actions\RecordManualMovement;
use App\Modules\Accounting\Application\Actions\VoidMovement;
use App\Modules\Accounting\Application\Cash\AvailableCash;
use App\Modules\Accounting\Application\Queries\MonthSummary;
use App\Modules\Accounting\Infrastructure\Models\AccountingAccount;
use App\Modules\Accounting\Infrastructure\Models\JournalEntry;
use App\Modules\Shared\Money\Currency;
use App\Modules\Shared\Time\Month;
use App\Modules\Treasury\Application\Actions\CloseMonthOfferings;
use App\Modules\Treasury\Application\Actions\CloseWeek;
use App\Modules\Treasury\Application\Closures\WeeklyClosureSchedule;
use App\Modules\Treasury\Infrastructure\Models\Offering;
use App\Modules\Treasury\Infrastructure\Models\OfferingCategory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    // Monday 16 November 2026 in Caracas.
    Carbon::setTestNow('2026-11-16 14:00:00');
    signInAs(UserRole::Treasurer);

    $this->income = AccountingAccount::factory()->create(['code' => 'I-002', 'name' => 'OFRENDAS DIARIAS']);
    AccountingAccount::factory()->create(['code' => 'I-007', 'name' => 'APORTES']);
    AccountingAccount::factory()->expense()->create(['code' => 'E-024', 'name' => 'SERVICIOS PÚBLICOS']);
    $this->category = OfferingCategory::factory()->create(['account_id' => $this->income->id]);
});

afterEach(function () {
    Carbon::setTestNow();
});

function movement(string $kind, string $amount, string $date = '2026-11-10', array $overrides = []): JournalEntry
{
    return app(RecordManualMovement::class)([
        'kind' => $kind,
        'account_code' => $kind === 'income' ? 'I-007' : 'E-024',
        'currency' => 'COP',
        'amount' => $amount,
        'entry_date' => $date,
        'description' => $kind === 'income' ? 'Aporte' : 'Pago de luz',
        'token' => (string) Str::uuid7(),
        ...$overrides,
    ]);
}

function availableCop(string $day = '2026-11-16'): string
{
    return app(AvailableCash::class)->on(Currency::COP, $day)->amount();
}

test('an income enters the cash and an expense takes from it, in their accounts', function () {
    movement('income', '100000');
    movement('expense', '40000,50');

    $summary = MonthSummary::of(Month::fromKey('2026-11'))['currencies']['COP'];

    expect($summary['income'])->toBe('100000.00')
        ->and($summary['expense'])->toBe('40000.50')
        ->and($summary['closing'])->toBe('59999.50')
        ->and(availableCop())->toBe('59999.50');
});

test('an expense larger than the cash is refused, saying how much there is and how much is missing', function () {
    movement('income', '100000');

    try {
        movement('expense', '100000.01');
        $this->fail('The expense should have been refused.');
    } catch (ValidationException $exception) {
        expect($exception->errors()['amount'][0])->toContain('disponible 100.000,00')->toContain('faltan 0,01');
    }

    expect(JournalEntry::query()->count())->toBe(1);
});

test('offerings of closed weeks count as available from their own date; those of open weeks do not', function () {
    Offering::factory()->create(['offering_date' => '2026-11-03', 'offering_category_id' => $this->category->id, 'amount_cop' => '80000.00']);
    Offering::factory()->create(['offering_date' => '2026-11-12', 'offering_category_id' => $this->category->id, 'amount_cop' => '30000.00']);

    expect(availableCop())->toBe('0.00');

    // Weeks W45 (2-8 Nov) and W46 (9-15 Nov) close in order; their offerings become available.
    app(CloseWeek::class)(WeeklyClosureSchedule::next());
    expect(availableCop())->toBe('80000.00')
        ->and(availableCop('2026-11-02'))->toBe('0.00');

    app(CloseWeek::class)(WeeklyClosureSchedule::next());
    expect(availableCop())->toBe('110000.00');

    movement('expense', '90000', '2026-11-13');

    // A back-dated expense cannot take money that was not there on its date or was spent afterwards.
    expect(fn () => movement('expense', '30000', '2026-11-04'))->toThrow(ValidationException::class);
    movement('expense', '20000', '2026-11-04');

    expect(availableCop())->toBe('0.00');
});

test('posting the month offerings does not change what is available (no double count)', function () {
    Offering::factory()->create(['offering_date' => '2026-10-20', 'offering_category_id' => $this->category->id, 'amount_cop' => '50000.00']);

    for ($week = WeeklyClosureSchedule::next(); $week !== null && $week->key() <= '2026-W44'; $week = WeeklyClosureSchedule::next()) {
        app(CloseWeek::class)($week);
    }

    $before = availableCop('2026-10-25');
    app(CloseMonthOfferings::class)(Month::fromKey('2026-10'));

    expect($before)->toBe('50000.00')
        ->and(availableCop('2026-10-25'))->toBe('50000.00')
        ->and(availableCop())->toBe('50000.00');
});

test('dates must be in an open month and never in the future', function () {
    expect(fn () => movement('income', '1', '2026-11-17'))->toThrow(ValidationException::class, 'futuro');

    Offering::factory()->create(['offering_date' => '2026-10-20', 'offering_category_id' => $this->category->id, 'amount_cop' => '50000.00']);

    for ($week = WeeklyClosureSchedule::next(); $week !== null && $week->key() <= '2026-W44'; $week = WeeklyClosureSchedule::next()) {
        app(CloseWeek::class)($week);
    }

    app(CloseMonthOfferings::class)(Month::fromKey('2026-10'));
    app(ClosePeriod::class)(Month::fromKey('2026-10'));

    expect(fn () => movement('income', '1', '2026-10-30'))->toThrow(ValidationException::class);
    movement('income', '1', '2026-11-01');
});

test('sending the same form twice records it once', function () {
    $token = (string) Str::uuid7();

    movement('income', '500', overrides: ['token' => $token]);
    movement('income', '500', overrides: ['token' => $token]);

    expect(JournalEntry::query()->count())->toBe(1);
});

test('only active income or expense accounts of the right kind can be used', function (string $kind, string $code) {
    AccountingAccount::factory()->expense()->inactive()->create(['code' => 'E-099']);

    expect(fn () => movement($kind, '10', overrides: ['account_code' => $code]))->toThrow(ValidationException::class);
})->with([
    'expense account as income' => ['income', 'E-024'],
    'opening balance account' => ['income', 'I-001'],
    'cash account' => ['expense', 'A-001'],
    'inactive account' => ['expense', 'E-099'],
]);

test('a currency exchange moves money between currencies with the amounts actually exchanged', function () {
    movement('income', '100', overrides: ['currency' => 'USD']);

    app(RecordCurrencyExchange::class)([
        'from_currency' => 'USD', 'from_amount' => '60', 'to_currency' => 'COP', 'to_amount' => '216000',
        'entry_date' => '2026-11-11', 'description' => 'Cambio en casa de cambio', 'token' => (string) Str::uuid7(),
    ]);

    expect(app(AvailableCash::class)->on(Currency::USD, '2026-11-16')->amount())->toBe('40.00')
        ->and(availableCop())->toBe('216000.00');

    expect(fn () => app(RecordCurrencyExchange::class)([
        'from_currency' => 'USD', 'from_amount' => '41', 'to_currency' => 'COP', 'to_amount' => '1',
        'entry_date' => '2026-11-12', 'description' => 'Demasiado', 'token' => (string) Str::uuid7(),
    ]))->toThrow(ValidationException::class);
});

test('voiding reverses the movement; voiding an income whose money was spent is refused', function () {
    $income = movement('income', '100000');
    $expense = movement('expense', '30000');

    app(VoidMovement::class)($expense, 'Se cargó dos veces');
    expect(availableCop())->toBe('100000.00');

    movement('expense', '95000');
    expect(fn () => app(VoidMovement::class)($income, 'Error'))->toThrow(ValidationException::class);

    expect(fn () => app(VoidMovement::class)($expense, 'Otra vez'))->toThrow(ValidationException::class, 'ya se anuló');
});

test('a correction is judged on its result', function () {
    $income = movement('income', '100000');
    movement('expense', '95000');

    expect(fn () => app(CorrectMovement::class)($income, [
        'account_code' => 'I-007', 'currency' => 'COP', 'amount' => '90000', 'entry_date' => '2026-11-10',
        'description' => 'Aporte', 'token' => (string) Str::uuid7(), 'reason' => 'Monto mal escrito',
    ]))->toThrow(ValidationException::class);

    app(CorrectMovement::class)($income, [
        'account_code' => 'I-007', 'currency' => 'COP', 'amount' => '96000', 'entry_date' => '2026-11-10',
        'description' => 'Aporte', 'token' => (string) Str::uuid7(), 'reason' => 'Monto mal escrito',
    ]);

    expect(availableCop())->toBe('1000.00')
        ->and(JournalEntry::query()->whereNotNull('reversal_of')->count())->toBe(1);
});

test('automatic entries cannot be voided or corrected by hand', function () {
    Offering::factory()->create(['offering_date' => '2026-10-20', 'offering_category_id' => $this->category->id, 'amount_cop' => '50000.00']);

    for ($week = WeeklyClosureSchedule::next(); $week !== null && $week->key() <= '2026-W44'; $week = WeeklyClosureSchedule::next()) {
        app(CloseWeek::class)($week);
    }

    $closure = app(CloseMonthOfferings::class)(Month::fromKey('2026-10'));
    $entry = JournalEntry::query()->findOrFail($closure->journal_entry_id);

    expect(fn () => app(VoidMovement::class)($entry, 'No'))->toThrow(ValidationException::class);
});

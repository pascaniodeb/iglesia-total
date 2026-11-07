<?php

use App\Enums\UserRole;
use App\Modules\Accounting\Infrastructure\Models\AccountingAccount;
use App\Modules\Accounting\Infrastructure\Models\JournalEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Carbon::setTestNow('2026-11-16 14:00:00');
    AccountingAccount::factory()->create(['code' => 'I-007', 'name' => 'APORTES']);
    AccountingAccount::factory()->expense()->create(['code' => 'E-024', 'name' => 'SERVICIOS PÚBLICOS']);
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * @param  array<string, string>  $overrides
 * @return array<string, string>
 */
function journalForm(string $kind, string $amount, array $overrides = []): array
{
    return [
        'kind' => $kind,
        'account_code' => $kind === 'income' ? 'I-007' : 'E-024',
        'currency' => 'COP',
        'amount' => $amount,
        'entry_date' => '2026-11-10',
        'description' => $kind === 'income' ? 'Aporte' : 'Pago de luz',
        'token' => (string) Str::uuid7(),
        ...$overrides,
    ];
}

test('the treasurer records income and expenses and sees the month statement, codes and what is available', function () {
    signInAs(UserRole::Treasurer);

    $this->post(route('accounting.journal.store'), journalForm('income', '100000'))->assertSessionHasNoErrors();
    $this->post(route('accounting.journal.store'), journalForm('expense', '40000,50', ['entry_date' => '2026-11-12']))->assertSessionHasNoErrors();

    $this->get(route('accounting.journal.index', ['month' => '2026-11']))
        ->assertInertia(fn (Assert $page) => $page
            ->component('accounting/journal/index')
            ->where('status', 'open')
            ->where('summary.currencies.COP.closing', '59999.50')
            ->where('available.COP', '59999.50')
            ->where('summary.by_code.0.code', 'E-024')
            ->where('entries.total', 2)
            ->where('entries.data.0.cash.COP', '-40000.50')
            ->where('entries.data.0.can_correct', true)
            ->where('accounts.income.0.code', 'I-007')
            ->where('can_manage', true));
});

test('an expense without money comes back as a form error saying how much is available', function () {
    signInAs(UserRole::Treasurer);

    $this->post(route('accounting.journal.store'), journalForm('expense', '10'))
        ->assertSessionHasErrors(['amount' => 'No alcanza en COP: disponible 0,00, faltan 10,00.']);

    expect(JournalEntry::query()->count())->toBe(0);
});

test('exchange, void and correct go through the screen', function () {
    signInAs(UserRole::Treasurer);
    $this->post(route('accounting.journal.store'), journalForm('income', '100', ['currency' => 'USD']))->assertSessionHasNoErrors();

    $this->post(route('accounting.journal.exchange'), [
        'from_currency' => 'USD', 'from_amount' => '50', 'to_currency' => 'COP', 'to_amount' => '180000',
        'entry_date' => '2026-11-11', 'description' => 'Cambio', 'token' => (string) Str::uuid7(),
    ])->assertSessionHasNoErrors();

    // The pesos came in with the exchange of the 11th: an expense in pesos on the 10th would be refused (D4).
    $this->post(route('accounting.journal.store'), journalForm('expense', '1000'))->assertSessionHasErrors('amount');
    $this->post(route('accounting.journal.store'), journalForm('expense', '1000', ['entry_date' => '2026-11-12']))->assertSessionHasNoErrors();
    $expense = JournalEntry::query()->where('origin_purpose', 'expense')->sole();

    $this->post(route('accounting.journal.cancel', $expense), ['reason' => 'Duplicado'])->assertSessionHasNoErrors();
    $this->post(route('accounting.journal.cancel', $expense), ['reason' => 'Otra vez'])->assertSessionHasErrors('entry');

    $income = JournalEntry::query()->where('origin_purpose', 'income')->sole();
    $this->post(route('accounting.journal.correct', $income), [
        'account_code' => 'I-007', 'currency' => 'USD', 'amount' => '120', 'entry_date' => '2026-11-10',
        'description' => 'Aporte', 'token' => (string) Str::uuid7(), 'reason' => 'Eran 120',
    ])->assertSessionHasNoErrors();

    $this->get(route('accounting.journal.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.currencies.USD.closing', '70.00')
            ->where('summary.currencies.COP.closing', '180000.00'));
});

test('the comptroller reads the journal but cannot write to it; the secretary cannot open it', function () {
    signInAs(UserRole::Comptroller);

    $this->get(route('accounting.journal.index'))
        ->assertInertia(fn (Assert $page) => $page->where('can_manage', false));
    $this->post(route('accounting.journal.store'), journalForm('income', '10'))->assertForbidden();

    signInAs(UserRole::Secretary);
    $this->get(route('accounting.journal.index'))->assertForbidden();
});

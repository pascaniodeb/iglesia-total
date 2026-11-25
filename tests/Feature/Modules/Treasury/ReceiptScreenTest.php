<?php

use App\Enums\UserRole;
use App\Modules\Accounting\Infrastructure\Models\AccountingAccount;
use App\Modules\Treasury\Infrastructure\Models\Receipt;
use App\Modules\Treasury\Infrastructure\Models\ReceiptVoid;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    // Monday 16 November 2026 in Caracas.
    Carbon::setTestNow('2026-11-16 14:00:00');

    $this->income = AccountingAccount::factory()->create(['code' => 'I-007', 'name' => 'APORTES']);
    $this->expense = AccountingAccount::factory()->expense()->create(['code' => 'E-024', 'name' => 'SERVICIOS PÚBLICOS']);
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function screenReceipt(array $overrides = []): array
{
    return [
        'type' => 'income',
        'issued_on' => '2026-11-10',
        'account_id' => test()->income->id,
        'holder' => 'María Pérez',
        'amount_cop' => '100000',
        'concept' => 'Aporte para el templo',
        'payment_method' => 'cash',
        'token' => (string) Str::uuid7(),
        ...$overrides,
    ];
}

test('the treasurer issues a receipt and lands on it', function () {
    signInAs(UserRole::Treasurer);

    $this->post(route('treasury.receipts.store'), screenReceipt())
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('treasury.receipts.show', 1));

    expect(Receipt::query()->sole()->holder)->toBe('María Pérez');
});

test('a refused receipt comes back as form errors, saying how much there is', function () {
    signInAs(UserRole::Treasurer);

    $this->post(route('treasury.receipts.store'), screenReceipt(['type' => 'expense', 'account_id' => $this->expense->id, 'amount_cop' => '10']))
        ->assertSessionHasErrors(['amount_cop' => 'No alcanza en COP: disponible 0,00, faltan 10,00.']);

    $this->post(route('treasury.receipts.store'), screenReceipt(['amount_ves' => '-1', 'amount_cop' => null]))
        ->assertSessionHasErrors('amount_ves');

    $this->post(route('treasury.receipts.store'), screenReceipt(['payment_method' => 'barter', 'token' => 'not-a-uuid']))
        ->assertSessionHasErrors(['payment_method', 'token']);

    expect(Receipt::query()->count())->toBe(0);
});

test('the number is not something the form can set', function () {
    signInAs(UserRole::Treasurer);

    $this->post(route('treasury.receipts.store'), [...screenReceipt(), 'number' => 500])->assertSessionHasNoErrors();

    expect(Receipt::query()->sole()->number)->toBe(1);
});

test('the book lists the newest first, with its state, and filters by type, state and search', function () {
    signInAs(UserRole::Treasurer);
    $this->post(route('treasury.receipts.store'), screenReceipt(['holder' => 'María Pérez']));
    $this->post(route('treasury.receipts.store'), screenReceipt(['holder' => 'José Gómez', 'amount_cop' => '5000']));
    $this->post(route('treasury.receipts.store'), screenReceipt(['type' => 'expense', 'account_id' => $this->expense->id, 'holder' => 'Corpoelec', 'amount_cop' => '2000']));

    signInAs(UserRole::Pastor);
    $this->post(route('treasury.receipts.cancel', 2), ['reason' => 'Error de captura'])->assertSessionHasNoErrors();

    $this->get(route('treasury.receipts.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('treasury/receipts/index')
            ->where('receipts.total', 3)
            ->where('receipts.data.0.number_label', '000003')
            ->where('receipts.data.0.account_code', 'E-024')
            ->where('receipts.data.1.is_void', true)
            ->where('receipts.data.2.number_label', '000001')
            ->where('next_number', '000004')
            ->where('start_number', 1)
            ->where('months', ['2026-11']));

    $this->get(route('treasury.receipts.index', ['state' => 'void']))->assertInertia(fn (Assert $page) => $page->where('receipts.total', 1));
    $this->get(route('treasury.receipts.index', ['state' => 'issued', 'type' => 'income']))->assertInertia(fn (Assert $page) => $page->where('receipts.total', 1));
    $this->get(route('treasury.receipts.index', ['search' => 'jose gomez']))->assertInertia(fn (Assert $page) => $page->where('receipts.total', 1)->where('receipts.data.0.number_label', '000002'));
    $this->get(route('treasury.receipts.index', ['search' => '3']))->assertInertia(fn (Assert $page) => $page->where('receipts.data.0.number_label', '000003'));
    $this->get(route('treasury.receipts.index', ['month' => '2026-10']))->assertInertia(fn (Assert $page) => $page->where('receipts.total', 0));
});

test('who can issue gets the form data; who can only read does not', function () {
    signInAs(UserRole::Treasurer);
    $this->post(route('treasury.receipts.store'), screenReceipt(['amount_usd' => '40', 'amount_cop' => null]));

    $this->get(route('treasury.receipts.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('can_issue', true)
            ->where('can_administer', false)
            ->where('form.accounts.income.0.code', 'I-007')
            ->where('form.accounts.expense.0.code', 'E-024')
            ->where('form.available.USD', '40.00')
            ->where('form.window.max', '2026-11-16')
            ->where('form.payment_methods.0.value', 'cash'));

    signInAs(UserRole::Comptroller);

    $this->get(route('treasury.receipts.index'))
        ->assertInertia(fn (Assert $page) => $page->where('can_issue', false)->where('form', null));
});

test('a receipt page shows what the paper says and, once voided, why', function () {
    signInAs(UserRole::Treasurer);
    $this->post(route('treasury.receipts.store'), screenReceipt([
        'payment_method' => 'transfer', 'bank' => 'Banesco', 'payment_reference' => '00123', 'payment_date' => '2026-11-09',
        'received_by_name' => 'Pablo Ascanio', 'received_by_id_number' => 'V-1234567',
    ]));

    $this->get(route('treasury.receipts.show', 1))
        ->assertInertia(fn (Assert $page) => $page
            ->component('treasury/receipts/show')
            ->where('receipt.number_label', '000001')
            ->where('receipt.type_label', 'Ingreso')
            ->where('receipt.account_code', 'I-007')
            ->where('receipt.month', '2026-11')
            ->where('receipt.amount_cop', '100000.00')
            ->where('receipt.payment_method_label', 'Transferencia')
            ->where('receipt.bank', 'Banesco')
            ->where('receipt.received_by_id_number', 'V-1234567')
            ->where('receipt.void', null)
            ->where('can_administer', false));

    signInAs(UserRole::Pastor);
    $this->post(route('treasury.receipts.cancel', 1), ['reason' => 'Monto mal escrito']);

    $this->get(route('treasury.receipts.show', 1))
        ->assertInertia(fn (Assert $page) => $page
            ->where('receipt.void.reason', 'Monto mal escrito')
            ->where('receipt.void.voided_by', fn ($name) => is_string($name))
            ->where('can_administer', true));

    $this->get(route('treasury.receipts.show', 99))->assertNotFound();
});

test('only who administers receipts voids them, and a void needs its reason and happens once', function () {
    signInAs(UserRole::Treasurer);
    $this->post(route('treasury.receipts.store'), screenReceipt());

    $this->post(route('treasury.receipts.cancel', 1), ['reason' => 'Error de captura'])->assertForbidden();
    expect(ReceiptVoid::query()->count())->toBe(0);

    signInAs(UserRole::Pastor);
    $this->post(route('treasury.receipts.cancel', 1), [])->assertSessionHasErrors('reason');
    $this->post(route('treasury.receipts.cancel', 1), ['reason' => 'no'])->assertSessionHasErrors('reason');
    $this->post(route('treasury.receipts.cancel', 1), ['reason' => 'Error de captura'])->assertSessionHasNoErrors();
    $this->post(route('treasury.receipts.cancel', 1), ['reason' => 'Otra vez, por favor'])->assertSessionHasErrors('receipt');

    expect(ReceiptVoid::query()->count())->toBe(1);
});

test('only who administers receipts sets the number the book starts at', function () {
    signInAs(UserRole::Treasurer);
    $this->put(route('treasury.receipts.settings'), ['start_number' => 500])->assertForbidden();

    signInAs(UserRole::Pastor);
    $this->put(route('treasury.receipts.settings'), ['start_number' => 0])->assertSessionHasErrors('start_number');
    $this->put(route('treasury.receipts.settings'), ['start_number' => 'abc'])->assertSessionHasErrors('start_number');
    $this->put(route('treasury.receipts.settings'), ['start_number' => 500])->assertSessionHasNoErrors();

    $this->get(route('treasury.receipts.index'))->assertInertia(fn (Assert $page) => $page->where('start_number', 500)->where('next_number', '000500'));
});

test('the comptroller reads the book but cannot issue; the secretary cannot open it', function () {
    signInAs(UserRole::Treasurer);
    $this->post(route('treasury.receipts.store'), screenReceipt());

    signInAs(UserRole::Comptroller);

    $this->get(route('treasury.receipts.index'))->assertOk();
    $this->post(route('treasury.receipts.store'), screenReceipt())->assertForbidden();

    signInAs(UserRole::Secretary);

    $this->get(route('treasury.receipts.index'))->assertForbidden();
    $this->get(route('treasury.receipts.show', 1))->assertForbidden();
});

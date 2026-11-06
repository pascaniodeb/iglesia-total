<?php

use App\Enums\UserRole;
use App\Models\User;
use App\Modules\Accounting\Application\Actions\SaveAccountingAccount;
use App\Modules\Accounting\Application\Actions\SetAccountingAccountActive;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\Enums\SystemFunction;
use App\Modules\Accounting\Infrastructure\Models\AccountingAccount;
use App\Modules\Integrity\Infrastructure\Models\ActivityLogEntry;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

function actingWithRole(UserRole $role): User
{
    $user = User::factory()->create();
    $user->forceFill(['role' => $role])->save();
    test()->actingAs($user);

    return $user;
}

test('the chart starts with the system accounts', function () {
    expect(AccountingAccount::system(SystemFunction::Cash)->code)->toBe('A-001')
        ->and(AccountingAccount::system(SystemFunction::CurrencyExchange)->code)->toBe('A-002')
        ->and(AccountingAccount::system(SystemFunction::OpeningBalance))
        ->code->toBe('I-001')
        ->type->toBe(AccountType::Equity);
});

test('the code decides the type of a new account', function () {
    actingWithRole(UserRole::Pastor);

    $income = app(SaveAccountingAccount::class)(null, ['code' => 'I-010', 'name' => 'DONACIONES']);
    $expense = app(SaveAccountingAccount::class)(null, ['code' => 'E-040', 'name' => 'TRANSPORTE']);

    expect($income->type)->toBe(AccountType::Income)
        ->and($expense->type)->toBe(AccountType::Expense)
        ->and(ActivityLogEntry::query()->where('action', 'accounting_account.created')->count())->toBe(2);
});

test('renaming records the change and system accounts cannot be edited or deactivated', function () {
    actingWithRole(UserRole::Pastor);
    $account = AccountingAccount::factory()->create(['code' => 'I-010', 'name' => 'DONACIONES']);

    app(SaveAccountingAccount::class)($account, ['name' => 'DONACIONES ESPECIALES']);

    expect(ActivityLogEntry::query()->where('action', 'accounting_account.updated')->sole()->changes['name'])
        ->toEqual(['from' => 'DONACIONES', 'to' => 'DONACIONES ESPECIALES']);

    $cash = AccountingAccount::system(SystemFunction::Cash);
    expect(fn () => app(SaveAccountingAccount::class)($cash, ['name' => 'OTRA']))->toThrow(ValidationException::class)
        ->and(fn () => app(SetAccountingAccountActive::class)($cash, false))->toThrow(ValidationException::class);
});

test('deactivating and reactivating is recorded', function () {
    actingWithRole(UserRole::Pastor);
    $account = AccountingAccount::factory()->create();

    app(SetAccountingAccountActive::class)($account, false);
    app(SetAccountingAccountActive::class)($account, true);

    expect(ActivityLogEntry::query()->orderBy('seq')->pluck('action')->all())
        ->toBe(['accounting_account.deactivated', 'accounting_account.activated']);
});

test('the database refuses to delete an account or change its code, type or function', function (string $statement) {
    AccountingAccount::factory()->create(['code' => 'I-010']);

    expect(fn () => DB::statement($statement))->toThrow(QueryException::class);
})->with([
    'delete' => "DELETE FROM accounting_accounts WHERE code = 'I-010'",
    'change code' => "UPDATE accounting_accounts SET code = 'I-011' WHERE code = 'I-010'",
    'change type' => "UPDATE accounting_accounts SET type = 'expense' WHERE code = 'I-010'",
    'code against type' => "INSERT INTO accounting_accounts (code, name, type) VALUES ('E-099', 'X', 'income')",
    'deactivate system' => "UPDATE accounting_accounts SET is_active = false WHERE system_function = 'cash'",
]);

test('who can see and who can change the chart', function (UserRole $role, int $view, int $create) {
    actingWithRole($role);

    $this->get(route('accounting.accounts.index'))->assertStatus($view);
    $this->post(route('accounting.accounts.store'), ['code' => 'I-010', 'name' => 'DONACIONES'])->assertStatus($create);
})->with([
    'pastor' => [UserRole::Pastor, 200, 302],
    'treasurer' => [UserRole::Treasurer, 200, 403],
    'comptroller' => [UserRole::Comptroller, 200, 403],
    'secretary' => [UserRole::Secretary, 403, 403],
]);

test('the form validates the code and keeps it fixed when editing', function () {
    actingWithRole(UserRole::Pastor);
    $account = AccountingAccount::factory()->create(['code' => 'I-010']);

    $this->post(route('accounting.accounts.store'), ['code' => 'X-001', 'name' => 'MAL'])->assertSessionHasErrors('code');
    $this->post(route('accounting.accounts.store'), ['code' => 'i-010', 'name' => 'REPETIDA'])->assertSessionHasErrors('code');
    $this->put(route('accounting.accounts.update', $account), ['code' => 'I-099', 'name' => 'OTRA'])->assertSessionHasErrors('code');

    $this->post(route('accounting.accounts.store'), ['code' => ' e-050 ', 'name' => 'viáticos'])->assertSessionHasNoErrors();
    expect(AccountingAccount::query()->where('code', 'E-050')->sole()->name)->toBe('VIÁTICOS');
});

test('the listing searches without accents and filters by type and status', function () {
    actingWithRole(UserRole::Treasurer);
    AccountingAccount::factory()->create(['code' => 'I-010', 'name' => 'OFRENDA PRO-CONSTRUCCIÓN']);
    AccountingAccount::factory()->expense()->inactive()->create(['code' => 'E-030', 'name' => 'ASESORÍA']);

    $this->get(route('accounting.accounts.index', ['search' => 'construccion']))
        ->assertInertia(fn (Assert $page) => $page
            ->component('accounting/accounts/index')
            ->where('accounts.total', 1)
            ->where('accounts.data.0.code', 'I-010'));

    $this->get(route('accounting.accounts.index', ['type' => 'expense', 'status' => 'inactive']))
        ->assertInertia(fn (Assert $page) => $page->where('accounts.total', 1)->where('accounts.data.0.code', 'E-030'));

    $this->get(route('accounting.accounts.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('accounts.total', 4)
            ->where('accounts.data.0.code', 'A-001')
            ->where('accounts.data.0.is_system', true));
});

test('imports the old chart, attaching I-001 to the system opening balance account', function () {
    createLegacySource();
    Schema::connection('legacy')->create('account_codes', function (Blueprint $table) {
        $table->integer('id')->primary();
        $table->text('code');
        $table->text('name');
        $table->text('type');
        $table->boolean('is_active')->default(true);
        $table->timestampTz('created_at');
    });
    DB::connection('legacy')->table('account_codes')->insert([
        ['id' => 1, 'code' => 'I-001', 'name' => 'SALDO ANTERIOR', 'type' => 'income', 'is_active' => true, 'created_at' => '2025-01-01 00:00:00+00'],
        ['id' => 2, 'code' => 'I-002', 'name' => 'OFRENDAS DIARIAS', 'type' => 'income', 'is_active' => true, 'created_at' => '2025-01-01 00:00:00+00'],
        ['id' => 3, 'code' => 'E-021', 'name' => 'ENVÍO DE OFRENDAS', 'type' => 'expense', 'is_active' => false, 'created_at' => '2025-01-01 00:00:00+00'],
    ]);

    $this->artisan('accounting:import-legacy-accounts')->assertSuccessful();
    $this->artisan('accounting:import-legacy-accounts')->assertSuccessful();

    expect(AccountingAccount::query()->count())->toBe(5)
        ->and(AccountingAccount::system(SystemFunction::OpeningBalance)->legacy_id)->toBe(1)
        ->and(AccountingAccount::query()->where('code', 'I-002')->sole()->type)->toBe(AccountType::Income)
        ->and(AccountingAccount::query()->where('code', 'E-021')->sole()->is_active)->toBeFalse();

    dropLegacySource();
});

test('the import refuses a code whose type does not fit, without writing anything', function () {
    createLegacySource();
    Schema::connection('legacy')->create('account_codes', function (Blueprint $table) {
        $table->integer('id')->primary();
        $table->text('code');
        $table->text('name');
        $table->text('type');
        $table->boolean('is_active')->default(true);
        $table->timestampTz('created_at');
    });
    DB::connection('legacy')->table('account_codes')->insert([
        ['id' => 2, 'code' => 'I-002', 'name' => 'OK', 'type' => 'income', 'is_active' => true, 'created_at' => '2025-01-01 00:00:00+00'],
        ['id' => 9, 'code' => 'E-099', 'name' => 'MAL', 'type' => 'income', 'is_active' => true, 'created_at' => '2025-01-01 00:00:00+00'],
    ]);

    $this->artisan('accounting:import-legacy-accounts')->assertFailed();

    expect(AccountingAccount::query()->count())->toBe(3);

    dropLegacySource();
});

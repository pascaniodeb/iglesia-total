<?php

use App\Enums\UserRole;
use App\Modules\Accounting\Domain\Enums\SystemFunction;
use App\Modules\Accounting\Infrastructure\Models\AccountingAccount;
use App\Modules\Integrity\Infrastructure\Models\ActivityLogEntry;
use App\Modules\Treasury\Application\Actions\SaveOfferingCategory;
use App\Modules\Treasury\Application\Actions\SetOfferingCategoryActive;
use App\Modules\Treasury\Infrastructure\Models\OfferingCategory;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

test('a category is created with an active income account and recorded', function () {
    signInAs(UserRole::Pastor);
    $income = AccountingAccount::factory()->create(['code' => 'I-002', 'name' => 'OFRENDAS DIARIAS']);

    $category = app(SaveOfferingCategory::class)(null, ['name' => 'Ofrenda general', 'account_id' => $income->id]);

    expect($category->account_id)->toBe($income->id)
        ->and($category->is_active)->toBeTrue()
        ->and(ActivityLogEntry::query()->sole()->summary)->toBe('Creó la categoría de ofrenda Ofrenda general (I-002).');
});

test('refuses an expense, inactive, system or unknown account', function (Closure $account) {
    signInAs(UserRole::Pastor);

    expect(fn () => app(SaveOfferingCategory::class)(null, ['name' => 'X', 'account_id' => $account()]))
        ->toThrow(ValidationException::class);
})->with([
    'expense' => [fn () => AccountingAccount::factory()->expense()->create()->id],
    'inactive' => [fn () => AccountingAccount::factory()->inactive()->create()->id],
    'opening balance' => [fn () => AccountingAccount::system(SystemFunction::OpeningBalance)->id],
    'unknown' => [fn () => (string) Str::uuid7()],
]);

test('the database also refuses a category pointing to a non-income account', function () {
    $expense = AccountingAccount::factory()->expense()->create();

    expect(fn () => DB::table('offering_categories')->insert(['name' => 'Mal', 'account_id' => $expense->id]))
        ->toThrow(QueryException::class, 'income account');
});

test('names are unique without minding case or accents, and categories are never deleted', function () {
    signInAs(UserRole::Pastor);
    $income = AccountingAccount::factory()->create();
    app(SaveOfferingCategory::class)(null, ['name' => 'Pro-construcción', 'account_id' => $income->id]);

    expect(fn () => app(SaveOfferingCategory::class)(null, ['name' => 'PRO-CONSTRUCCION', 'account_id' => $income->id]))
        ->toThrow(ValidationException::class);

    expect(fn () => DB::statement('DELETE FROM offering_categories'))->toThrow(QueryException::class, 'append-only');
});

test('changing the account and deactivating are recorded', function () {
    signInAs(UserRole::Pastor);
    $category = OfferingCategory::factory()->create(['name' => 'Misiones']);
    $other = AccountingAccount::factory()->create(['code' => 'I-007']);

    app(SaveOfferingCategory::class)($category, ['name' => 'Misiones', 'account_id' => $other->id]);
    app(SetOfferingCategoryActive::class)($category, false);

    expect(ActivityLogEntry::query()->orderBy('seq')->pluck('action')->all())
        ->toBe(['offering_category.updated', 'offering_category.deactivated'])
        ->and($category->refresh()->account_id)->toBe($other->id);
});

test('who can see and who can change the categories', function (UserRole $role, int $view, int $create) {
    signInAs($role);
    $income = AccountingAccount::factory()->create();

    $this->get(route('treasury.offering-categories.index'))->assertStatus($view);
    $this->post(route('treasury.offering-categories.store'), ['name' => 'Nueva', 'account_id' => $income->id])->assertStatus($create);
})->with([
    'pastor' => [UserRole::Pastor, 200, 302],
    'treasurer' => [UserRole::Treasurer, 200, 403],
    'comptroller' => [UserRole::Comptroller, 200, 403],
    'secretary' => [UserRole::Secretary, 403, 403],
]);

test('the listing shows the account of each category and offers only active income accounts', function () {
    signInAs(UserRole::Treasurer);
    $income = AccountingAccount::factory()->create(['code' => 'I-002', 'name' => 'OFRENDAS DIARIAS']);
    AccountingAccount::factory()->inactive()->create(['code' => 'I-009']);
    AccountingAccount::factory()->expense()->create(['code' => 'E-030']);
    OfferingCategory::factory()->create(['name' => 'Ofrenda general', 'account_id' => $income->id]);

    $this->get(route('treasury.offering-categories.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('treasury/offering-categories/index')
            ->where('categories.data.0.account_code', 'I-002')
            ->where('categories.data.0.account_name', 'OFRENDAS DIARIAS')
            ->has('accounts', 1)
            ->where('accounts.0.code', 'I-002'));
});

test('imports the old categories with their income account', function () {
    createLegacySource();
    Schema::connection('legacy')->create('offering_categories', function (Blueprint $table) {
        $table->integer('id')->primary();
        $table->text('name');
        $table->integer('account_code_id');
        $table->boolean('is_active')->default(true);
        $table->timestampTz('created_at');
    });
    $income = AccountingAccount::factory()->create(['code' => 'I-002']);
    $income->forceFill(['legacy_id' => 2])->save();
    DB::connection('legacy')->table('offering_categories')->insert([
        ['id' => 1, 'name' => 'Ofrenda general', 'account_code_id' => 2, 'is_active' => true, 'created_at' => '2025-01-01 00:00:00+00'],
    ]);

    $this->artisan('treasury:import-legacy-categories')->assertSuccessful();
    $this->artisan('treasury:import-legacy-categories')->assertSuccessful();

    expect(OfferingCategory::query()->sole())
        ->legacy_id->toBe(1)
        ->account_id->toBe($income->id);

    DB::connection('legacy')->table('offering_categories')->insert([
        ['id' => 2, 'name' => 'Huérfana', 'account_code_id' => 99, 'is_active' => true, 'created_at' => '2025-01-01 00:00:00+00'],
    ]);

    $this->artisan('treasury:import-legacy-categories')->assertFailed();
    expect(OfferingCategory::query()->count())->toBe(1);

    dropLegacySource();
});

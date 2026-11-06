<?php

use App\Enums\UserRole;
use App\Modules\Integrity\Infrastructure\Models\ActivityLogEntry;
use App\Modules\Members\Application\MemberDirectory;
use App\Modules\Members\Infrastructure\Models\Member;
use App\Modules\Treasury\Application\Actions\SaveOffering;
use App\Modules\Treasury\Application\Actions\TrashOffering;
use App\Modules\Treasury\Infrastructure\Models\Offering;
use App\Modules\Treasury\Infrastructure\Models\OfferingCategory;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    // Friday 6 November 2026, 10:00 in Caracas.
    Carbon::setTestNow('2026-11-06 14:00:00');
    $this->category = OfferingCategory::factory()->create(['name' => 'Ofrenda general']);
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function offeringData(array $overrides = []): array
{
    return [
        'offering_date' => '2026-11-01',
        'offering_category_id' => test()->category->id,
        'member_id' => null,
        'concept' => null,
        'amount_ves' => '',
        'amount_usd' => '10',
        'amount_cop' => '141000,50',
        ...$overrides,
    ];
}

test('an offering keeps each currency apart and gets its ISO week and month from the database', function () {
    signInAs(UserRole::Treasurer);
    $member = Member::factory()->create(['full_name' => 'Ana Pérez']);

    $offering = app(SaveOffering::class)(null, offeringData(['member_id' => $member->id]));

    expect($offering->amount_ves)->toBe('0.00')
        ->and($offering->amount_usd)->toBe('10.00')
        ->and($offering->amount_cop)->toBe('141000.50')
        ->and($offering->week_key)->toBe('2026-W44')
        ->and($offering->month_key)->toBe('2026-11')
        ->and($offering->created_by)->toBe(auth()->id())
        ->and(ActivityLogEntry::query()->sole()->summary)->toBe('Registró una ofrenda de Ana Pérez del 01/11/2026.');
});

test('an offering without a member is anonymous', function () {
    signInAs(UserRole::Treasurer);

    $offering = app(SaveOffering::class)(null, offeringData());

    expect($offering->member_id)->toBeNull()
        ->and(ActivityLogEntry::query()->sole()->summary)->toContain('anónima');
});

test('refuses future dates, inactive categories, trashed members and empty or malformed amounts', function (Closure $data, string $field) {
    signInAs(UserRole::Treasurer);

    try {
        app(SaveOffering::class)(null, $data());
        $this->fail('The offering should have been refused.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($field);
    }

    expect(Offering::query()->count())->toBe(0);
})->with([
    'tomorrow in Caracas' => [fn () => offeringData(['offering_date' => '2026-11-07']), 'offering_date'],
    'inactive category' => [fn () => offeringData(['offering_category_id' => OfferingCategory::factory()->inactive()->create()->id]), 'offering_category_id'],
    'trashed member' => [fn () => offeringData(['member_id' => tap(Member::factory()->create())->delete()->id]), 'member_id'],
    'no amount' => [fn () => offeringData(['amount_usd' => '', 'amount_cop' => '0']), 'amount_ves'],
    'thousands separator' => [fn () => offeringData(['amount_cop' => '141.000,50']), 'amount_ves'],
    'negative' => [fn () => offeringData(['amount_usd' => '-5']), 'amount_ves'],
    'three decimals' => [fn () => offeringData(['amount_usd' => '1.005']), 'amount_ves'],
]);

test('a correction records only what changed and keeps a deactivated category already on it', function () {
    signInAs(UserRole::Treasurer);
    $offering = app(SaveOffering::class)(null, offeringData());
    $this->category->forceFill(['is_active' => false])->save();

    app(SaveOffering::class)($offering, offeringData(['amount_usd' => '12']));

    $correction = ActivityLogEntry::query()->where('action', 'offering.updated')->sole();

    expect($correction->changes)->toEqual(['amount_usd' => ['from' => '10.00', 'to' => '12.00']]);
});

test('sending to the trash keeps the row and its record; it can never be physically deleted', function () {
    signInAs(UserRole::Treasurer);
    $offering = app(SaveOffering::class)(null, offeringData());

    app(TrashOffering::class)($offering);

    expect(Offering::withTrashed()->sole()->trashed())->toBeTrue()
        ->and(ActivityLogEntry::query()->where('action', 'offering.trashed')->exists())->toBeTrue();

    expect(fn () => DB::statement('DELETE FROM offerings'))->toThrow(QueryException::class, 'append-only');
});

test('the database refuses negative amounts and an offering without any amount', function (array $amounts) {
    expect(fn () => DB::table('offerings')->insert([
        'offering_date' => '2026-11-01',
        'offering_category_id' => test()->category->id,
        ...$amounts,
    ]))->toThrow(QueryException::class);
})->with([
    'all zero' => [['amount_ves' => 0, 'amount_usd' => 0, 'amount_cop' => 0]],
    'negative' => [['amount_ves' => -1, 'amount_usd' => 5, 'amount_cop' => 0]],
]);

test('who can see and who can record offerings', function (UserRole $role, int $view, int $store) {
    signInAs($role);

    $this->get(route('treasury.offerings.index'))->assertStatus($view);
    $this->post(route('treasury.offerings.store'), offeringData())->assertStatus($store);
})->with([
    'pastor' => [UserRole::Pastor, 200, 302],
    'treasurer' => [UserRole::Treasurer, 200, 302],
    'comptroller' => [UserRole::Comptroller, 200, 403],
    'secretary' => [UserRole::Secretary, 403, 403],
]);

test('the registry filters by month and category, searches by giver, and totals what it filters', function () {
    signInAs(UserRole::Treasurer);
    $ana = Member::factory()->create(['full_name' => 'Ana Pérez']);
    $other = OfferingCategory::factory()->create(['name' => 'Misiones']);
    Offering::factory()->create(['offering_date' => '2026-11-01', 'member_id' => $ana->id, 'amount_cop' => '100000.00', 'amount_usd' => '5.00', 'offering_category_id' => $this->category->id]);
    Offering::factory()->create(['offering_date' => '2026-11-02', 'amount_cop' => '0.10', 'offering_category_id' => $this->category->id]);
    Offering::factory()->create(['offering_date' => '2026-10-25', 'amount_cop' => '999.00', 'offering_category_id' => $other->id]);
    tap(Offering::factory()->create(['offering_date' => '2026-11-03', 'amount_cop' => '5000.00', 'offering_category_id' => $this->category->id]))->delete();

    $this->get(route('treasury.offerings.index', ['month' => '2026-11']))
        ->assertInertia(fn (Assert $page) => $page
            ->component('treasury/offerings/index')
            ->where('offerings.total', 2)
            ->where('offerings.data.0.offering_date', '2026-11-02')
            ->where('offerings.data.1.member.name', 'Ana Pérez')
            ->where('totals', ['count' => 2, 'ves' => '0.00', 'usd' => '5.00', 'cop' => '100000.10'])
            ->where('months', ['2026-11', '2026-10']));

    $this->get(route('treasury.offerings.index', ['search' => 'perez']))
        ->assertInertia(fn (Assert $page) => $page->where('offerings.total', 1)->where('totals.cop', '100000.00'));

    $this->get(route('treasury.offerings.index', ['category' => $other->id]))
        ->assertInertia(fn (Assert $page) => $page->where('totals.cop', '999.00'));

    $this->get(route('treasury.offerings.index', ['status' => 'inactive']))
        ->assertInertia(fn (Assert $page) => $page->where('offerings.total', 1)->where('totals.cop', '5000.00'));
});

test('the member search offers only members of the registry', function () {
    signInAs(UserRole::Treasurer);
    Member::factory()->create(['full_name' => 'José Gómez']);
    tap(Member::factory()->create(['full_name' => 'José Trashed']))->delete();

    $this->getJson(route('treasury.offerings.member-options', ['search' => 'jose']))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'José Gómez');
});

test('the member directory names trashed members of old offerings but never offers them', function () {
    $member = Member::factory()->create(['full_name' => 'María López']);
    $member->delete();
    $directory = app(MemberDirectory::class);

    expect($directory->find($member->id)?->name)->toBe('María López')
        ->and($directory->find($member->id)?->isInRegistry)->toBeFalse()
        ->and($directory->search('maria'))->toBe([])
        ->and($directory->idsMatching('MARÍA'))->toBe([$member->id])
        ->and($directory->find('not-a-uuid'))->toBeNull();
});

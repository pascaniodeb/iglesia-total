<?php

use App\Enums\UserRole;
use App\Modules\Integrity\Infrastructure\Models\ActivityLogEntry;
use App\Modules\Members\Infrastructure\Models\Member;
use App\Modules\Treasury\Infrastructure\Models\Tithe;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Carbon::setTestNow('2026-11-16 14:00:00');
    $this->member = Member::factory()->create(['full_name' => 'María Pérez']);
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function titheForm(array $overrides = []): array
{
    return [
        'tithe_date' => '2026-11-15',
        'member_id' => test()->member->id,
        'amount_cop' => '50000',
        'notes' => 'Domingo',
        ...$overrides,
    ];
}

test('the pastor records a tithe of a member and sees it with the totals', function () {
    signInAs(UserRole::Pastor);

    $this->post(route('treasury.tithes.store'), titheForm(['amount_usd' => '10,50']))->assertSessionHasNoErrors();

    $this->get(route('treasury.tithes.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('treasury/tithes/index')
            ->where('tithes.total', 1)
            ->where('tithes.data.0.member.name', 'María Pérez')
            ->where('tithes.data.0.amount_usd', '10.50')
            ->where('totals.cop', '50000.00')
            ->where('totals.usd', '10.50')
            ->where('months', ['2026-11']));

    $log = ActivityLogEntry::query()->where('action', 'tithe.created')->sole();
    expect($log->summary)->toContain('María Pérez');
});

test('a tithe always has a member of the registry, a past date and an amount', function (array $overrides, string $field) {
    signInAs(UserRole::Pastor);
    $trashed = Member::factory()->create();
    $trashed->delete();

    $this->post(route('treasury.tithes.store'), titheForm([...$overrides, 'member_id' => $overrides['member_id'] ?? ($overrides['_trashed'] ?? false ? $trashed->id : test()->member->id)]))
        ->assertSessionHasErrors($field);

    expect(Tithe::query()->count())->toBe(0);
})->with([
    'no member' => [['member_id' => ''], 'member_id'],
    'a member that does not exist' => [['member_id' => '0198f3a0-0000-7000-8000-000000000001'], 'member_id'],
    'a member in the trash' => [['_trashed' => true], 'member_id'],
    'a future date' => [['tithe_date' => '2026-11-17'], 'tithe_date'],
    'an impossible date' => [['tithe_date' => '2026-02-30'], 'tithe_date'],
    'no amount' => [['amount_cop' => null], 'amount_ves'],
    'a negative amount' => [['amount_cop' => '-1'], 'amount_ves'],
    'three decimals' => [['amount_cop' => '10.005'], 'amount_ves'],
]);

test('correcting a tithe logs what changed; the member it already has may stay after being trashed', function () {
    signInAs(UserRole::Pastor);
    $this->post(route('treasury.tithes.store'), titheForm());
    $tithe = Tithe::query()->sole();
    $this->member->delete();

    $this->put(route('treasury.tithes.update', $tithe), titheForm(['amount_cop' => '60000']))->assertSessionHasNoErrors();

    expect($tithe->fresh()->amount_cop)->toBe('60000.00');

    $log = ActivityLogEntry::query()->where('action', 'tithe.updated')->sole();
    expect(json_encode($log->changes))->toContain('amount_cop')->toContain('60000.00');

    $this->put(route('treasury.tithes.update', $tithe), titheForm(['amount_cop' => '60000']))->assertSessionHasNoErrors();
    expect(ActivityLogEntry::query()->where('action', 'tithe.updated')->count())->toBe(1);
});

test('a tithe in the trash stops adding up and comes back when restored', function () {
    signInAs(UserRole::Pastor);
    $this->post(route('treasury.tithes.store'), titheForm());
    $tithe = Tithe::query()->sole();

    $this->delete(route('treasury.tithes.destroy', $tithe))->assertSessionHasNoErrors();

    $this->get(route('treasury.tithes.index'))->assertInertia(fn (Assert $page) => $page->where('tithes.total', 0)->where('totals.cop', '0.00'));
    $this->get(route('treasury.tithes.index', ['status' => 'inactive']))->assertInertia(fn (Assert $page) => $page->where('tithes.total', 1)->where('tithes.data.0.is_deleted', true));

    $this->post(route('treasury.tithes.restore', $tithe->id))->assertSessionHasNoErrors();

    $this->get(route('treasury.tithes.index'))->assertInertia(fn (Assert $page) => $page->where('tithes.total', 1));
    expect(ActivityLogEntry::query()->whereIn('action', ['tithe.trashed', 'tithe.restored'])->count())->toBe(2)
        ->and(fn () => DB::transaction(fn () => DB::statement('DELETE FROM tithes')))->toThrow(QueryException::class, 'append-only');
});

test('the book filters by month and searches by the name of the member or the notes', function () {
    signInAs(UserRole::Pastor);
    $other = Member::factory()->create(['full_name' => 'José Gómez']);
    $this->post(route('treasury.tithes.store'), titheForm());
    $this->post(route('treasury.tithes.store'), titheForm(['member_id' => $other->id, 'tithe_date' => '2026-10-04', 'notes' => 'Culto especial']));

    $this->get(route('treasury.tithes.index', ['month' => '2026-10']))->assertInertia(fn (Assert $page) => $page->where('tithes.total', 1)->where('tithes.data.0.member.name', 'José Gómez'));
    $this->get(route('treasury.tithes.index', ['search' => 'maria perez']))->assertInertia(fn (Assert $page) => $page->where('tithes.total', 1));
    $this->get(route('treasury.tithes.index', ['search' => 'especial']))->assertInertia(fn (Assert $page) => $page->where('tithes.total', 1)->where('tithes.data.0.member.name', 'José Gómez'));
});

test('only the pastor holds the tithes: nobody else reads, records or searches them', function (UserRole $role) {
    signInAs(UserRole::Pastor);
    $this->post(route('treasury.tithes.store'), titheForm());
    $tithe = Tithe::query()->sole();

    signInAs($role);

    $this->get(route('treasury.tithes.index'))->assertForbidden();
    $this->post(route('treasury.tithes.store'), titheForm())->assertForbidden();
    $this->put(route('treasury.tithes.update', $tithe), titheForm())->assertForbidden();
    $this->delete(route('treasury.tithes.destroy', $tithe))->assertForbidden();
    $this->get(route('treasury.tithes.member-options'))->assertForbidden();
})->with([UserRole::Treasurer, UserRole::Comptroller, UserRole::Secretary]);

test('a tithe never reaches the books: no entry, no receipt, no closure, no seal', function () {
    signInAs(UserRole::Pastor);
    $this->post(route('treasury.tithes.store'), titheForm(['amount_ves' => '100', 'amount_usd' => '5']));

    expect(DB::table('journal_entries')->count())->toBe(0)
        ->and(DB::table('receipts')->count())->toBe(0)
        ->and(DB::table('ledger_blocks')->count())->toBe(0)
        ->and(ActivityLogEntry::query()->where('action', 'like', 'journal%')->count())->toBe(0);
});

// One rule per class: Pest does not check each class of a list.
foreach ([
    'App\Modules\Treasury\Application\Actions\SaveTithe',
    'App\Modules\Treasury\Application\Actions\TrashTithe',
    'App\Modules\Treasury\Http\Controllers\TitheController',
    'App\Modules\Treasury\Http\Tables\TitheListing',
    'App\Modules\Treasury\Http\Requests\TitheRequest',
    'App\Modules\Treasury\Http\Controllers\TitheImportController',
    'App\Modules\Treasury\Http\Controllers\TitheInsightsController',
    'App\Modules\Treasury\Application\Import\PreviewTitheImport',
    'App\Modules\Treasury\Application\Import\ImportTithes',
    'App\Modules\Treasury\Application\Tithes\TitheReport',
    'App\Modules\Treasury\Application\Tithes\TitheAnalysis',
    'App\Modules\Treasury\Infrastructure\Models\Tithe',
] as $titheClass) {
    arch('tithes stay out of the books, the receipts and the seal: '.class_basename($titheClass))
        ->expect($titheClass)
        ->not->toUse([
            'App\Modules\Accounting',
            'App\Modules\Integrity\Domain\Ledger',
            'App\Modules\Integrity\Application\Ledger',
            'App\Modules\Treasury\Domain\Receipts',
            'App\Modules\Treasury\Infrastructure\Models\Receipt',
        ]);
}

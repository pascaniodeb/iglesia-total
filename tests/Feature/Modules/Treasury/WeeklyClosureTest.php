<?php

use App\Enums\UserRole;
use App\Modules\Integrity\Application\Actions\CreateLedgerGenesis;
use App\Modules\Integrity\Application\Actions\VerifyLedgerChain;
use App\Modules\Integrity\Domain\Ledger\BlockIssue;
use App\Modules\Integrity\Infrastructure\Models\ActivityLogEntry;
use App\Modules\Integrity\Infrastructure\Models\LedgerBlock;
use App\Modules\Shared\Time\IsoWeek;
use App\Modules\Treasury\Application\Actions\CloseWeek;
use App\Modules\Treasury\Application\Actions\SaveOffering;
use App\Modules\Treasury\Application\Actions\TrashOffering;
use App\Modules\Treasury\Application\Closures\WeeklyClosureSchedule;
use App\Modules\Treasury\Application\Import\PreviewOfferingImport;
use App\Modules\Treasury\Infrastructure\Models\Offering;
use App\Modules\Treasury\Infrastructure\Models\OfferingCategory;
use App\Modules\Treasury\Infrastructure\Models\WeeklyClosure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    // Friday 6 November 2026 in Caracas: week 2026-W44 (26 Oct - 1 Nov) has ended, W45 has not.
    Carbon::setTestNow('2026-11-06 14:00:00');
    signInAs(UserRole::Treasurer);
    $this->category = OfferingCategory::factory()->create();
});

afterEach(function () {
    Carbon::setTestNow();
});

function offeringOn(string $date, string $cop = '1000.00', string $usd = '0.00'): Offering
{
    return Offering::factory()->create([
        'offering_date' => $date,
        'offering_category_id' => test()->category->id,
        'amount_cop' => $cop,
        'amount_usd' => $usd,
    ]);
}

function closeWeek(string $key): array
{
    return app(CloseWeek::class)(IsoWeek::fromKey($key));
}

test('closing a week snapshots its live offerings and records it', function () {
    offeringOn('2026-10-26', '100000.10');
    offeringOn('2026-11-01', '0.20', '5.00');
    tap(offeringOn('2026-10-27', '999.00'))->delete();

    $closure = closeWeek('2026-W44')['closure'];

    expect($closure->offerings_count)->toBe(2)
        ->and($closure->total_cop)->toBe('100000.30')
        ->and($closure->total_usd)->toBe('5.00')
        ->and($closure->week_start->toDateString())->toBe('2026-10-26')
        ->and($closure->week_end->toDateString())->toBe('2026-11-01')
        ->and(ActivityLogEntry::query()->where('action', 'weekly_closure.closed')->exists())->toBeTrue();
});

test('weeks close in order, only once they have ended, and never twice', function () {
    offeringOn('2026-10-19');

    expect(fn () => closeWeek('2026-W44'))->toThrow(ValidationException::class, 'en orden');

    closeWeek('2026-W43');
    closeWeek('2026-W44');

    expect(fn () => closeWeek('2026-W44'))->toThrow(ValidationException::class)
        ->and(fn () => closeWeek('2026-W45'))->toThrow(ValidationException::class, 'termina');

    expect(WeeklyClosureSchedule::next()?->key())->toBe('2026-W45');
});

test('a week without offerings is closed too, so no week is skipped', function () {
    offeringOn('2026-10-19');
    closeWeek('2026-W43');

    $closure = closeWeek('2026-W44')['closure'];

    expect($closure->offerings_count)->toBe(0)
        ->and($closure->total_cop)->toBe('0.00');
});

test('the offerings of a closed week cannot be recorded, corrected, trashed or moved', function () {
    $offering = offeringOn('2026-10-28');
    closeWeek('2026-W44');

    $data = [
        'offering_date' => '2026-10-29',
        'offering_category_id' => $this->category->id,
        'amount_cop' => '500',
    ];

    expect(fn () => app(SaveOffering::class)(null, $data))->toThrow(ValidationException::class, 'cerrada')
        ->and(fn () => app(SaveOffering::class)($offering, [...$data, 'offering_date' => '2026-11-03']))->toThrow(ValidationException::class, 'cerrada')
        ->and(fn () => app(TrashOffering::class)($offering))->toThrow(ValidationException::class, 'cerrada');

    // And the database refuses it even when the application is bypassed.
    expect(fn () => DB::transaction(fn () => DB::table('offerings')->where('id', $offering->id)->update(['amount_cop' => '1.00'])))->toThrow(QueryException::class, 'closed')
        ->and(fn () => DB::transaction(fn () => DB::table('offerings')->insert(['offering_date' => '2026-10-30', 'offering_category_id' => $this->category->id, 'amount_cop' => 1])))->toThrow(QueryException::class, 'closed');
});

test('the database refuses a closure whose totals are not the sum of its offerings', function () {
    offeringOn('2026-10-28', '1000.00');

    expect(fn () => DB::transaction(fn () => DB::table('weekly_closures')->insert([
        'week_key' => '2026-W44',
        'week_start' => '2026-10-26',
        'week_end' => '2026-11-01',
        'offerings_count' => 1,
        'total_ves' => 0,
        'total_usd' => 0,
        'total_cop' => '999.99',
    ])))->toThrow(QueryException::class, 'does not match its offerings');

    expect(WeeklyClosure::query()->count())->toBe(0);
});

test('closures are append-only', function () {
    offeringOn('2026-10-28');
    closeWeek('2026-W44');

    expect(fn () => DB::transaction(fn () => DB::statement('DELETE FROM weekly_closures')))->toThrow(QueryException::class, 'append-only')
        ->and(fn () => DB::transaction(fn () => DB::statement('UPDATE weekly_closures SET total_cop = 0')))->toThrow(QueryException::class, 'append-only');
});

test('without a genesis the week closes and stays pending a seal, which catches up later', function () {
    offeringOn('2026-10-28');

    $result = closeWeek('2026-W44');

    expect($result['sealed'])->toBeFalse()
        ->and($result['seal_error'])->toContain('génesis')
        ->and(WeeklyClosure::query()->count())->toBe(1);

    app(CreateLedgerGenesis::class)('M07A1985C0001', '2026-W44');
    $this->artisan('ledger:seal-pending')->assertSuccessful();

    expect(LedgerBlock::query()->where('kind', 'weekly')->sole()->period_key)->toBe('2026-W44');
});

test('a closed week is sealed with one Merkle leaf per offering and verifies against the books', function () {
    app(CreateLedgerGenesis::class)('M07A1985C0001', '2026-W44');
    offeringOn('2026-10-28', '1000.00');
    offeringOn('2026-10-29', '2000.00');

    $result = closeWeek('2026-W44');
    $block = LedgerBlock::query()->where('kind', 'weekly')->sole();

    expect($result['sealed'])->toBeTrue()
        ->and($block->leaves_count)->toBe(2)
        ->and($block->payload()->totals->cop)->toBe('3000.00')
        ->and($block->payload()->closure->offeringsCount)->toBe(2)
        ->and(app(VerifyLedgerChain::class)()['matches_books'])->toBeTrue();
});

test('an offering changed behind the guards after the seal is reported as drift', function () {
    app(CreateLedgerGenesis::class)('M07A1985C0001', '2026-W44');
    $offering = offeringOn('2026-10-28', '1000.00');
    closeWeek('2026-W44');

    DB::statement('ALTER TABLE offerings DISABLE TRIGGER offerings_closed_week_guard');
    DB::table('offerings')->where('id', $offering->id)->update(['amount_cop' => '480000.00']);
    DB::statement('ALTER TABLE offerings ENABLE TRIGGER offerings_closed_week_guard');

    $result = app(VerifyLedgerChain::class)();

    expect($result['intact'])->toBeTrue()
        ->and($result['matches_books'])->toBeFalse()
        ->and($result['issues'][0]->type)->toBe(BlockIssue::DRIFT)
        ->and($result['issues'][0]->periodKey)->toBe('2026-W44');
});

test('the import refuses rows of a closed week', function () {
    offeringOn('2026-10-28');
    closeWeek('2026-W44');

    $path = offeringWorkbook([['28/10/2026', '', $this->category->name, '', '', '10', '']]);
    $preview = app(PreviewOfferingImport::class)($path)->toArray();

    expect($preview['can_import'])->toBeFalse()
        ->and($preview['rejected'][0]['reason'])->toContain('cerrada');
});

test('the screen shows the next week with its snapshot and blocker, and the closed weeks with their seal', function () {
    offeringOn('2026-10-28', '1500.00');

    $this->get(route('treasury.weekly-closures.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('treasury/weekly-closures/index')
            ->where('next.week_key', '2026-W44')
            ->where('next.snapshot.cop', '1500.00')
            ->where('next.blocker', null)
            ->where('next.months', ['2026-10', '2026-11']));

    $this->post(route('treasury.weekly-closures.store'), ['week_key' => '2026-W44'])->assertSessionHasNoErrors();

    $this->get(route('treasury.weekly-closures.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('closures.total', 1)
            ->where('closures.data.0.seal', null)
            ->where('next.week_key', '2026-W45')
            ->where('next.blocker', fn (string $blocker) => str_contains($blocker, '08/11/2026')));
});

test('who can see and who can close weeks', function (UserRole $role, int $view, int $close) {
    offeringOn('2026-10-28');
    signInAs($role);

    $this->get(route('treasury.weekly-closures.index'))->assertStatus($view);
    $this->post(route('treasury.weekly-closures.store'), ['week_key' => '2026-W44'])->assertStatus($close);
})->with([
    'treasurer' => [UserRole::Treasurer, 200, 302],
    'comptroller' => [UserRole::Comptroller, 200, 403],
    'secretary' => [UserRole::Secretary, 403, 403],
]);

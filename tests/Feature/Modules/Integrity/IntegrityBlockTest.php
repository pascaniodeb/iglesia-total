<?php

use App\Enums\UserRole;
use App\Modules\Accounting\Infrastructure\Models\AccountingAccount;
use App\Modules\Integrity\Application\Actions\CreateLedgerGenesis;
use App\Modules\Integrity\Domain\Ledger\MerkleSide;
use App\Modules\Integrity\Domain\Ledger\MerkleStep;
use App\Modules\Integrity\Domain\Ledger\MerkleTree;
use App\Modules\Shared\Time\IsoWeek;
use App\Modules\Treasury\Application\Actions\CloseWeek;
use App\Modules\Treasury\Infrastructure\Models\Offering;
use App\Modules\Treasury\Infrastructure\Models\OfferingCategory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    // Friday 6 November 2026: week 2026-W44 (26 Oct - 1 Nov) has ended.
    Carbon::setTestNow('2026-11-06 14:00:00');
    signInAs(UserRole::Pastor);

    $category = OfferingCategory::factory()->create(['account_id' => AccountingAccount::factory()->create()->id]);
    $this->first = Offering::factory()->create(['offering_date' => '2026-10-28', 'offering_category_id' => $category->id, 'amount_cop' => '100.00']);
    $this->second = Offering::factory()->create(['offering_date' => '2026-11-01', 'offering_category_id' => $category->id, 'amount_cop' => '250.50']);

    app(CreateLedgerGenesis::class)('M07A1985C0001', '2026-W40');
    app(CloseWeek::class)(IsoWeek::fromKey('2026-W44'));
});

afterEach(function () {
    Carbon::setTestNow();
});

function weeklyBlockHeight(): int
{
    return (int) DB::table('ledger_blocks')->where('kind', 'weekly')->where('period_key', '2026-W44')->value('height');
}

test('a block shows its fields, that its hash recomputes and that the books still match', function () {
    $this->get(route('integrity.blocks.show', weeklyBlockHeight()))->assertInertia(fn (Assert $page) => $page
        ->component('integrity/block')
        ->where('block.kind', 'weekly')
        ->where('block.period_key', '2026-W44')
        ->where('block.leaves_count', 2)
        ->where('hash_recomputes', true)
        ->where('payload_recomputes', true)
        ->where('books', 'matches')
        ->where('proofs_offered', true)
        ->where('payload.weekKey', '2026-W44')
        ->where('leaves_total', 2)
        ->where('leaves', fn ($leaves) => collect($leaves)->pluck('key')->sort()->values()->all() === collect([$this->first->id, $this->second->id])->sort()->values()->all()));
});

test('a block shows that the books moved after the seal', function () {
    DB::statement('ALTER TABLE offerings DISABLE TRIGGER offerings_closed_week_guard');
    DB::statement("UPDATE offerings SET amount_cop = '999.00' WHERE id = '{$this->first->id}'");
    DB::statement('ALTER TABLE offerings ENABLE TRIGGER offerings_closed_week_guard');

    $this->get(route('integrity.blocks.show', weeklyBlockHeight()))->assertInertia(fn (Assert $page) => $page
        ->where('hash_recomputes', true)
        ->where('books', 'drifted'));
});

test('the genesis block has no records to prove', function () {
    $this->get(route('integrity.blocks.show', 0))->assertInertia(fn (Assert $page) => $page
        ->where('block.kind', 'genesis')
        ->where('proofs_offered', false)
        ->where('books', 'matches'));
});

test('a block that does not exist is a 404', function () {
    $this->get(route('integrity.blocks.show', 9999))->assertNotFound();
});

test('the proof of an offering reaches the sealed root and anyone can check it', function () {
    $response = $this->get(route('integrity.proof', ['kind' => 'weekly', 'period' => '2026-W44', 'record' => $this->first->id]));

    $response->assertInertia(fn (Assert $page) => $page
        ->component('integrity/proof')
        ->where('proof.status', 'valid')
        ->where('proof.height', weeklyBlockHeight()));

    $json = $this->get(route('integrity.proof', ['kind' => 'weekly', 'period' => '2026-W44', 'record' => $this->first->id, 'format' => 'json']))
        ->assertOk()
        ->assertHeader('Content-Disposition')
        ->json();
    $root = DB::table('ledger_blocks')->where('height', weeklyBlockHeight())->value('merkle_root');
    $steps = array_map(fn (array $step) => new MerkleStep(MerkleSide::from($step['side']), $step['hash']), $json['steps']);

    // Checked from the file alone, with no access to the books.
    expect($json['status'])->toBe('valid')
        ->and(MerkleTree::verify($json['leaf'], $steps, trim($root)))->toBeTrue()
        ->and(MerkleTree::verify($json['leaf'], $steps, str_repeat('a', 64)))->toBeFalse();
});

test('an offering changed after the seal cannot be proven with the root that was sealed', function () {
    DB::statement('ALTER TABLE offerings DISABLE TRIGGER offerings_closed_week_guard');
    DB::statement("UPDATE offerings SET amount_cop = '999.00' WHERE id = '{$this->first->id}'");
    DB::statement('ALTER TABLE offerings ENABLE TRIGGER offerings_closed_week_guard');

    $this->get(route('integrity.proof', ['kind' => 'weekly', 'period' => '2026-W44', 'record' => $this->first->id]))
        ->assertInertia(fn (Assert $page) => $page->where('proof.status', 'changed'));
});

test('proofs say when there is nothing to prove', function () {
    $this->get(route('integrity.proof', ['kind' => 'weekly', 'period' => '2026-W44', 'record' => (string) Str::uuid7()]))
        ->assertInertia(fn (Assert $page) => $page->where('proof.status', 'missing'));

    $this->get(route('integrity.proof', ['kind' => 'weekly', 'period' => '2026-W30', 'record' => $this->first->id]))
        ->assertInertia(fn (Assert $page) => $page->where('proof.status', 'unsealed'));

    $this->get(route('integrity.proof', ['kind' => 'nonsense', 'period' => '2026-W44', 'record' => $this->first->id]))
        ->assertSessionHasErrors('kind');
});

test('only who may read the sealed ledger sees blocks and proofs', function () {
    signInAs(UserRole::Treasurer);

    $this->get(route('integrity.blocks.show', weeklyBlockHeight()))->assertForbidden();
    $this->get(route('integrity.proof', ['kind' => 'weekly', 'period' => '2026-W44', 'record' => $this->first->id]))->assertForbidden();
});

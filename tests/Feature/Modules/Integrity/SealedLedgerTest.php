<?php

use App\Modules\Integrity\Application\Actions\CreateLedgerGenesis;
use App\Modules\Integrity\Application\Actions\SealPeriod;
use App\Modules\Integrity\Application\Actions\VerifyLedgerChain;
use App\Modules\Integrity\Application\Ledger\SealableSources;
use App\Modules\Integrity\Domain\Ledger\BlockHash;
use App\Modules\Integrity\Domain\Ledger\BlockIssue;
use App\Modules\Integrity\Domain\Ledger\Canonical;
use App\Modules\Integrity\Domain\Ledger\LedgerException;
use App\Modules\Integrity\Domain\Ledger\MerkleTree;
use App\Modules\Integrity\Domain\Ledger\SealablePayload;
use App\Modules\Integrity\Domain\Ledger\SealableSource;
use App\Modules\Integrity\Infrastructure\Models\ActivityLogEntry;
use App\Modules\Integrity\Infrastructure\Models\LedgerBlock;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Stands in for Treasury: weeks with their offerings (amounts as text), changeable after the
 * close to simulate someone moving the books.
 */
final class FakeWeeklySource implements SealableSource
{
    /** @var array<string, list<array{id: int, cop: string}>> */
    public array $weeks = [];

    public function kind(): string
    {
        return 'weekly';
    }

    public function build(string $periodKey): ?SealablePayload
    {
        if (! array_key_exists($periodKey, $this->weeks)) {
            return null;
        }

        $offerings = $this->weeks[$periodKey];
        $total = array_reduce($offerings, fn (string $sum, array $offering) => bcadd($sum, $offering['cop'], 2), '0.00');

        return new SealablePayload(
            ['kind' => 'weekly', 'weekKey' => $periodKey, 'offeringsCount' => count($offerings), 'totals' => ['cop' => $total]],
            array_map(fn (array $offering) => Canonical::hash($offering), $offerings),
        );
    }

    public function closedPeriods(): array
    {
        return array_keys($this->weeks);
    }
}

beforeEach(function () {
    $this->source = new FakeWeeklySource;
    app(SealableSources::class)->register($this->source);
});

function openLedger(): LedgerBlock
{
    return app(CreateLedgerGenesis::class)('M07A1985C0001', '2026-W45');
}

test('the genesis opens the chain at height 0 pointing to the zero hash', function () {
    $genesis = openLedger();

    expect($genesis->height)->toBe(0)
        ->and($genesis->kind)->toBe('genesis')
        ->and($genesis->prev_hash)->toBe(Canonical::ZERO_HASH)
        ->and($genesis->hash)->toBe(BlockHash::of(0, Canonical::ZERO_HASH, Canonical::ZERO_HASH, $genesis->payload_hash))
        ->and($genesis->payload()->continues)->toBeNull()
        ->and(ActivityLogEntry::query()->where('action', 'ledger.genesis')->exists())->toBeTrue();

    expect(fn () => openLedger())->toThrow(LedgerException::class);
});

test('the genesis can declare the head of the chain it continues', function () {
    $genesis = app(CreateLedgerGenesis::class)('M07A1985C0001', '2025-W01', ['height' => 109, 'hash' => str_repeat('a', 64)]);

    expect($genesis->payload()->continues)->toEqual((object) ['chain' => 'libro-sellado', 'height' => 109, 'hash' => str_repeat('a', 64)]);
});

test('sealing a closed week appends the next block with its Merkle root', function () {
    $genesis = openLedger();
    $this->source->weeks['2026-W45'] = [['id' => 1, 'cop' => '50000.00'], ['id' => 2, 'cop' => '25000.50']];

    $block = app(SealPeriod::class)('weekly', '2026-W45');

    $leaves = array_map(fn (array $offering) => Canonical::hash($offering), $this->source->weeks['2026-W45']);

    expect($block->height)->toBe(1)
        ->and($block->prev_hash)->toBe($genesis->hash)
        ->and($block->merkle_root)->toBe(MerkleTree::root($leaves))
        ->and($block->leaves_count)->toBe(2)
        ->and($block->payload()->totals->cop)->toBe('75000.50')
        ->and($block->hash)->toBe(BlockHash::of(1, $genesis->hash, $block->merkle_root, $block->payload_hash))
        ->and(ActivityLogEntry::query()->where('action', 'ledger.sealed')->sole()->subject_id)->toBe($block->id);
});

test('sealing the same week again returns the same block', function () {
    openLedger();
    $this->source->weeks['2026-W45'] = [['id' => 1, 'cop' => '10.00']];

    $first = app(SealPeriod::class)('weekly', '2026-W45');
    $again = app(SealPeriod::class)('weekly', '2026-W45');

    expect($again->id)->toBe($first->id)
        ->and(LedgerBlock::query()->count())->toBe(2);
});

test('refuses to seal without a genesis, a period without a close or an unknown kind', function (Closure $seal, string $message) {
    expect($seal)->toThrow(LedgerException::class, $message);
})->with([
    'no genesis' => [fn () => app(SealPeriod::class)('weekly', '2026-W45'), 'no genesis'],
    'not closed' => [function () {
        openLedger();

        return app(SealPeriod::class)('weekly', '2026-W46');
    }, 'is not closed'],
    'unknown kind' => [fn () => app(SealPeriod::class)('yearly', '2026'), 'Nothing can seal'],
]);

test('the database refuses a block that does not continue the head or carries a forged hash', function () {
    $genesis = openLedger();
    $payloadHash = Canonical::hash(['kind' => 'weekly']);
    $insert = fn (array $overrides) => DB::table('ledger_blocks')->insert([
        'height' => 1,
        'kind' => 'weekly',
        'period_key' => '2026-W45',
        'prev_hash' => $genesis->hash,
        'merkle_root' => Canonical::ZERO_HASH,
        'payload_hash' => $payloadHash,
        'hash' => BlockHash::of(1, $genesis->hash, Canonical::ZERO_HASH, $payloadHash),
        'payload' => '{"kind":"weekly"}',
        ...$overrides,
    ]);

    expect(fn () => DB::transaction(fn () => $insert(['height' => 5])))->toThrow(QueryException::class, 'point to the current head')
        ->and(fn () => DB::transaction(fn () => $insert(['prev_hash' => str_repeat('b', 64)])))->toThrow(QueryException::class, 'point to the current head')
        ->and(fn () => DB::transaction(fn () => $insert(['hash' => str_repeat('c', 64)])))->toThrow(QueryException::class, 'block hash does not match');

    expect(LedgerBlock::query()->count())->toBe(1);
});

test('blocks cannot be changed, deleted or truncated', function (string $statement) {
    openLedger();

    expect(fn () => DB::statement($statement))->toThrow(QueryException::class, 'append-only');
})->with([
    'update' => "UPDATE ledger_blocks SET kind = 'weekly'",
    'delete' => 'DELETE FROM ledger_blocks',
    'truncate' => 'TRUNCATE ledger_blocks',
]);

test('verification of an intact chain that matches the books finds nothing and lists pending seals', function () {
    openLedger();
    $this->source->weeks['2026-W45'] = [['id' => 1, 'cop' => '10.00']];
    app(SealPeriod::class)('weekly', '2026-W45');
    $this->source->weeks['2026-W46'] = [['id' => 2, 'cop' => '20.00']];

    $result = app(VerifyLedgerChain::class)();

    expect($result['issues'])->toBe([])
        ->and($result['intact'])->toBeTrue()
        ->and($result['matches_books'])->toBeTrue()
        ->and($result['blocks'])->toBe(2)
        ->and($result['unsealed'])->toBe([['kind' => 'weekly', 'period_key' => '2026-W46']]);
});

test('verification reports drift when the books move after the seal', function () {
    openLedger();
    $this->source->weeks['2026-W45'] = [['id' => 1, 'cop' => '10.00']];
    app(SealPeriod::class)('weekly', '2026-W45');

    $this->source->weeks['2026-W45'][] = ['id' => 9, 'cop' => '480000.00'];

    $result = app(VerifyLedgerChain::class)();

    expect($result['intact'])->toBeTrue()
        ->and($result['matches_books'])->toBeFalse()
        ->and($result['issues'])->toHaveCount(1)
        ->and($result['issues'][0]->type)->toBe(BlockIssue::DRIFT)
        ->and($result['issues'][0]->detail())->toContain('(offeringsCount, totals, records)');
});

test('verification reports a block rewritten directly in the database as a chain issue', function () {
    openLedger();
    $this->source->weeks['2026-W45'] = [['id' => 1, 'cop' => '10.00']];
    app(SealPeriod::class)('weekly', '2026-W45');

    DB::statement('ALTER TABLE ledger_blocks DISABLE TRIGGER ledger_blocks_no_update');
    DB::statement(<<<'SQL'
        UPDATE ledger_blocks SET payload = jsonb_set(payload, '{totals,cop}', '"9.00"') WHERE height = 1
    SQL);
    DB::statement('ALTER TABLE ledger_blocks ENABLE TRIGGER ledger_blocks_no_update');

    $result = app(VerifyLedgerChain::class)();

    expect($result['intact'])->toBeFalse()
        ->and(collect($result['issues'])->where('type', BlockIssue::CHAIN)->pluck('height')->all())->toBe([1]);
});

test('the verify command records the result and fails on any issue', function () {
    openLedger();
    $this->source->weeks['2026-W45'] = [['id' => 1, 'cop' => '10.00']];
    app(SealPeriod::class)('weekly', '2026-W45');

    $this->artisan('ledger:verify')->assertSuccessful();

    $this->source->weeks['2026-W45'][0]['cop'] = '11.00';

    $this->artisan('ledger:verify')->assertFailed();

    $verifications = ActivityLogEntry::query()->where('action', 'ledger.verified')->orderBy('seq')->get();
    expect($verifications)->toHaveCount(2)
        ->and($verifications[0]->changes['matches_books'])->toBeTrue()
        ->and($verifications[1]->changes['matches_books'])->toBeFalse();
});

test('the genesis command validates the continued head', function () {
    $this->artisan('ledger:genesis', ['church-code' => 'M07A1985C0001', 'first-period' => '2025-W01', '--continues-height' => '109'])
        ->assertFailed();

    $this->artisan('ledger:genesis', [
        'church-code' => 'M07A1985C0001',
        'first-period' => '2025-W01',
        '--continues-height' => '109',
        '--continues-hash' => '129c1c7bca3397d5dcfd073fdc23ec88cecaf2a9b4cf77858f6590238303b617',
    ])->assertSuccessful();

    expect(LedgerBlock::query()->sole()->payload()->continues->height)->toBe(109);
});

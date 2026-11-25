<?php

use App\Modules\Integrity\Application\Actions\VerifyLedgerChain;
use App\Modules\Integrity\Infrastructure\Models\LedgerBlock;
use App\Modules\Integrity\Infrastructure\Models\LegacyLedgerBlock;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    createLegacySource();

    Schema::connection('legacy')->create('ledger_blocks', function (Blueprint $table) {
        $table->integer('id')->primary();
        $table->integer('height');
        $table->text('kind');
        $table->text('period_key')->nullable();
        $table->text('prev_hash');
        $table->text('merkle_root');
        $table->text('payload_hash');
        $table->text('hash');
        $table->jsonb('payload');
        $table->timestampTz('sealed_at');
        $table->integer('sealed_by')->nullable();
        $table->text('anchor_key')->nullable();
    });
});

afterEach(function () {
    dropLegacySource();
});

/**
 * The first three blocks of the old system's real chain (genesis and two weeks): contiguous.
 *
 * @return list<array<string, mixed>>
 */
function oldChain(int $blocks = 3): array
{
    $rows = json_decode((string) file_get_contents(dirname(__DIR__, 3).'/Fixtures/Integrity/legacy-ledger-blocks.json'), true);

    return array_map(fn (array $row, int $index): array => [
        'id' => $index + 1,
        'height' => $row['height'],
        'kind' => $row['kind'],
        'period_key' => $row['period_key'],
        'prev_hash' => $row['prev_hash'],
        'merkle_root' => $row['merkle_root'],
        'payload_hash' => $row['payload_hash'],
        'hash' => $row['hash'],
        'payload' => json_encode($row['payload']),
        'sealed_at' => '2026-08-22 14:00:00+00',
        'sealed_by' => $index === 0 ? null : 1,
        'anchor_key' => null,
    ], array_slice($rows, 0, $blocks), array_keys(array_slice($rows, 0, $blocks)));
}

/**
 * @param  list<array<string, mixed>>  $rows
 */
function writeOldChain(array $rows): void
{
    DB::connection('legacy')->table('ledger_blocks')->insert($rows);
}

test('the old chain is archived verbatim, with its original dates', function () {
    writeOldChain(oldChain());

    $this->artisan('ledger:import-legacy')->assertSuccessful();

    $archived = LegacyLedgerBlock::query()->orderBy('height')->get();

    expect($archived)->toHaveCount(3)
        ->and($archived->pluck('hash')->all())->toBe(array_column(oldChain(), 'hash'))
        ->and($archived[1]->period_key)->toBe('2025-W01')
        ->and($archived[1]->sealed_at->format('Y-m-d H:i'))->toBe('2026-08-22 14:00')
        ->and($archived[1]->legacy_sealed_by)->toBe(1)
        ->and($archived[0]->legacy_sealed_by)->toBeNull()
        ->and(app(VerifyLedgerChain::class)()['legacy'])->toMatchArray(['blocks' => 3, 'height' => 2, 'intact' => true]);
});

test('running it again writes nothing', function () {
    writeOldChain(oldChain());

    $this->artisan('ledger:import-legacy')->assertSuccessful();
    $this->artisan('ledger:import-legacy')->expectsOutputToContain('Already archived')->assertSuccessful();

    expect(LegacyLedgerBlock::query()->count())->toBe(3);
});

test('a dry run verifies the old chain and writes nothing', function () {
    writeOldChain(oldChain());

    $this->artisan('ledger:import-legacy', ['--dry-run' => true])
        ->expectsOutputToContain('The old chain is intact')
        ->assertSuccessful();

    expect(LegacyLedgerBlock::query()->count())->toBe(0);
});

test('a chain that is not intact is refused before anything is written', function (callable $tamper) {
    writeOldChain($tamper(oldChain()));

    $this->artisan('ledger:import-legacy')->expectsOutputToContain('not intact')->assertFailed();

    expect(LegacyLedgerBlock::query()->count())->toBe(0);
})->with([
    'a payload that was edited' => [function (array $rows): array {
        $payload = json_decode($rows[1]['payload'], true);
        $payload['offeringsCount'] = 8;
        $rows[1]['payload'] = json_encode($payload);

        return $rows;
    }],
    'a block that was removed' => [function (array $rows): array {
        unset($rows[1]);

        return array_values($rows);
    }],
    'a hash that was replaced' => [function (array $rows): array {
        $rows[2]['hash'] = str_repeat('a', 64);

        return $rows;
    }],
]);

test('an empty old chain is refused', function () {
    $this->artisan('ledger:import-legacy')->expectsOutputToContain('no sealed ledger')->assertFailed();
});

test('an archived block that the old system has since replaced is refused', function () {
    writeOldChain(oldChain());
    $this->artisan('ledger:import-legacy')->assertSuccessful();

    // Another block now sits at height 2 in the old system: the archive never changes.
    DB::connection('legacy')->table('ledger_blocks')->where('height', 2)->update(['id' => 99]);

    $this->artisan('ledger:import-legacy')->expectsOutputToContain('differs')->assertFailed();

    expect(LegacyLedgerBlock::query()->where('height', 2)->sole()->legacy_id)->toBe(3);
});

test('the database refuses a block that does not continue the archive, and any change to it', function () {
    writeOldChain(oldChain());
    $this->artisan('ledger:import-legacy')->assertSuccessful();

    $row = oldChain()[2];
    $row['id'] = 50;
    $row['height'] = 3;

    expect(fn () => DB::transaction(fn () => DB::table('legacy_ledger_blocks')->insert([
        'id' => (string) Str::uuid7(), 'legacy_id' => 50, 'height' => 3, 'kind' => 'weekly', 'period_key' => '2025-W03',
        'prev_hash' => str_repeat('b', 64), 'merkle_root' => $row['merkle_root'], 'payload_hash' => $row['payload_hash'],
        'hash' => $row['hash'], 'payload' => $row['payload'], 'sealed_at' => now(),
    ])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::statement("UPDATE legacy_ledger_blocks SET kind = 'weekly'")))->toThrow(QueryException::class, 'append-only')
        ->and(fn () => DB::transaction(fn () => DB::statement('DELETE FROM legacy_ledger_blocks')))->toThrow(QueryException::class, 'append-only');
});

test('the new chain continues the archived one when asked to', function () {
    writeOldChain(oldChain());
    $this->artisan('ledger:import-legacy')->assertSuccessful();
    $head = array_values(array_slice(oldChain(), -1))[0];

    $this->artisan('ledger:genesis', ['church-code' => 'M07A1985C0001', 'first-period' => '2025-W01', '--continue-legacy' => true])->assertSuccessful();

    $payload = LedgerBlock::query()->where('kind', 'genesis')->sole()->payload();

    expect($payload->continues->height)->toBe(2)
        ->and($payload->continues->hash)->toBe($head['hash'])
        ->and($payload->continues->chain)->toBe('libro-sellado');
});

test('the new chain cannot continue an old chain that is not archived or that was altered', function () {
    $this->artisan('ledger:genesis', ['church-code' => 'X', 'first-period' => '2025-W01', '--continue-legacy' => true])
        ->expectsOutputToContain('not archived yet')
        ->assertFailed();

    writeOldChain(oldChain());
    $this->artisan('ledger:import-legacy')->assertSuccessful();
    $this->artisan('ledger:genesis', ['church-code' => 'X', 'first-period' => '2025-W01', '--continue-legacy' => true, '--continues-height' => '1'])
        ->expectsOutputToContain('not both')
        ->assertFailed();

    DB::statement('ALTER TABLE legacy_ledger_blocks DISABLE TRIGGER legacy_ledger_blocks_no_update');
    DB::statement("UPDATE legacy_ledger_blocks SET payload = jsonb_set(payload, '{offeringsCount}', '99') WHERE height = 1");
    DB::statement('ALTER TABLE legacy_ledger_blocks ENABLE TRIGGER legacy_ledger_blocks_no_update');

    $this->artisan('ledger:genesis', ['church-code' => 'X', 'first-period' => '2025-W01', '--continue-legacy' => true])
        ->expectsOutputToContain('not intact')
        ->assertFailed();

    expect(LedgerBlock::query()->count())->toBe(0);
});

test('verifying the ledger also verifies the archive and fails when it was altered', function () {
    writeOldChain(oldChain());
    $this->artisan('ledger:import-legacy')->assertSuccessful();
    $this->artisan('ledger:verify')->expectsOutputToContain('Old chain (archived)')->assertSuccessful();

    DB::statement('ALTER TABLE legacy_ledger_blocks DISABLE TRIGGER legacy_ledger_blocks_no_update');
    DB::statement("UPDATE legacy_ledger_blocks SET payload = jsonb_set(payload, '{offeringsCount}', '99') WHERE height = 1");
    DB::statement('ALTER TABLE legacy_ledger_blocks ENABLE TRIGGER legacy_ledger_blocks_no_update');

    $result = app(VerifyLedgerChain::class)()['legacy'];

    expect($result['intact'])->toBeFalse()
        ->and($result['issues'][0]->height)->toBe(1);

    $this->artisan('ledger:verify')->assertFailed();
});

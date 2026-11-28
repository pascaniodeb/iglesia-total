<?php

use App\Modules\Accounting\Application\Ledger;
use App\Modules\Accounting\Domain\Journal\JournalEntryDraft;
use App\Modules\Accounting\Domain\Journal\JournalLine;
use App\Modules\Accounting\Infrastructure\Models\AccountingAccount;
use App\Modules\Integrity\Application\IntegrityChecks;
use App\Modules\Integrity\Application\Seals\RecordSeal;
use App\Modules\Integrity\Application\Seals\SealedModels;
use App\Modules\Integrity\Application\Seals\VerifyRowSeals;
use App\Modules\Integrity\Infrastructure\Models\ActivityLogEntry;
use App\Modules\Shared\Money\Currency;
use App\Modules\Shared\Money\Money;
use App\Modules\Treasury\Infrastructure\Models\Offering;
use App\Modules\Treasury\Infrastructure\Models\OfferingCategory;
use App\Modules\Treasury\Infrastructure\Models\Tithe;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

const SEAL_KEY = 'a-test-key-that-is-at-least-32-characters-long';

beforeEach(function () {
    Carbon::setTestNow('2026-11-06 14:00:00');
    config(['integrity.seal_key' => SEAL_KEY]);
    $this->category = OfferingCategory::factory()->create(['account_id' => AccountingAccount::factory()->create()->id]);
});

afterEach(function () {
    Carbon::setTestNow();
});

function sealsOf(Offering $offering): int
{
    return DB::table('row_seals')->where('table_name', 'offerings')->where('row_id', $offering->id)->count();
}

function sealed(): array
{
    return app(VerifyRowSeals::class)();
}

function sealedOffering(string $cop = '100.00'): Offering
{
    return Offering::factory()->create(['offering_date' => '2026-11-02', 'offering_category_id' => test()->category->id, 'amount_cop' => $cop]);
}

test('a row is sealed when it is saved, again when it changes, and each time it is deleted or restored', function () {
    $offering = sealedOffering();
    expect(sealsOf($offering))->toBe(1);

    $offering->update(['amount_cop' => '250.00']);
    expect(sealsOf($offering))->toBe(2);

    $offering->delete();
    $offering->restore();

    expect(sealsOf($offering))->toBe(4)
        ->and(sealed()['changed'])->toBe([])
        ->and(sealed()['counts'])->toBe(['changed' => 0, 'unsealed' => 0, 'gone' => 0]);
});

test('without an integrity key nothing is sealed and nothing is verified', function () {
    config(['integrity.seal_key' => null]);

    $offering = sealedOffering();

    expect(sealsOf($offering))->toBe(0)
        ->and(sealed()['enabled'])->toBeFalse()
        ->and(sealed()['checked'])->toBe(0)
        ->and(collect(app(IntegrityChecks::class)->run())->firstWhere('id', 'seals.rows')['passed'])->toBeTrue();
});

test('a row changed with direct SQL is found, even though no trigger stopped it', function () {
    $offering = sealedOffering();

    DB::table('offerings')->where('id', $offering->id)->update(['amount_cop' => '999999.00']);

    $result = sealed();

    expect($result['changed'])->toBe(["offerings:{$offering->id}"])
        ->and($result['counts']['changed'])->toBe(1);
});

test('a seal forged without the key does not hold', function () {
    $offering = sealedOffering();
    DB::table('offerings')->where('id', $offering->id)->update(['amount_cop' => '999999.00']);

    $payload = (string) DB::selectOne('SELECT (to_jsonb(t) - ?::text[])::text AS payload FROM offerings t WHERE t.id = ?', ['{}', $offering->id])->payload;
    DB::table('row_seals')->insert(['table_name' => 'offerings', 'row_id' => $offering->id, 'seal' => (new RecordSeal('another-key-another-key-another-key-123'))->sign('offerings', $payload)]);

    expect(sealed()['changed'])->toBe(["offerings:{$offering->id}"]);
});

test('a row erased outside the Actions leaves its seal behind', function () {
    $offering = sealedOffering();

    DB::statement('ALTER TABLE offerings DISABLE TRIGGER USER');
    DB::table('offerings')->where('id', $offering->id)->delete();
    DB::statement('ALTER TABLE offerings ENABLE TRIGGER USER');

    expect(sealed()['gone'])->toBe(["offerings:{$offering->id}"]);
});

test('rows that came in before sealing are reported, sealed by the command and never sealed over when changed', function () {
    config(['integrity.seal_key' => null]);
    $old = sealedOffering('10.00');
    $changed = sealedOffering('20.00');
    config(['integrity.seal_key' => SEAL_KEY]);

    expect(sealed()['counts']['unsealed'])->toBe(2);

    $this->artisan('integrity:seal-rows')->assertSuccessful();

    expect(sealed()['counts'])->toBe(['changed' => 0, 'unsealed' => 0, 'gone' => 0])
        ->and(ActivityLogEntry::query()->where('action', 'integrity.rows_sealed')->count())->toBe(1);

    DB::table('offerings')->where('id', $changed->id)->update(['amount_cop' => '77.00']);
    $this->artisan('integrity:seal-rows')->assertSuccessful();

    // The command only seals what has no seal: the changed row is still a finding.
    expect(sealed()['changed'])->toBe(["offerings:{$changed->id}"])
        ->and(sealsOf($old))->toBe(1);
});

test('sealing rows needs the key', function () {
    config(['integrity.seal_key' => null]);

    $this->artisan('integrity:seal-rows')->assertFailed();
});

test('journal entries and their lines are sealed when they are posted', function () {
    AccountingAccount::factory()->create(['code' => 'I-007', 'name' => 'APORTES']);
    $amount = Money::of('5000.00', Currency::COP);

    app(Ledger::class)->post(new JournalEntryDraft('2026-11-02', 'Asiento', 'test', 'origin', 'test', [JournalLine::debit('A-001', $amount), JournalLine::credit('I-007', $amount)]));

    expect(DB::table('row_seals')->where('table_name', 'journal_entries')->count())->toBe(1)
        ->and(DB::table('row_seals')->where('table_name', 'journal_entry_lines')->count())->toBe(2)
        ->and(sealed()['counts'])->toBe(['changed' => 0, 'unsealed' => 0, 'gone' => 0]);
});

test('the modules register their sealed models', function () {
    $tables = array_map(fn (string $model): string => (new $model)->getTable(), app(SealedModels::class)->all());

    expect($tables)->toEqualCanonicalizing(['offerings', 'receipts', 'weekly_closures', 'monthly_offering_closures', 'journal_entries', 'journal_entry_lines']);
});

test('the tithes book is never sealed: it stays out of the books', function () {
    expect(app(SealedModels::class)->all())->not->toContain(Tithe::class);
});

test('ledger:verify fails when a sealed row changed and passes when all hold', function () {
    $offering = sealedOffering();

    $this->artisan('ledger:verify')->assertSuccessful();

    DB::table('offerings')->where('id', $offering->id)->update(['amount_cop' => '999999.00']);

    $this->artisan('ledger:verify')->assertFailed();
});

test('the integrity center lists the row seals check, which fails for a changed row', function () {
    $offering = sealedOffering();
    $check = fn (): array => collect(app(IntegrityChecks::class)->run())->firstWhere('id', 'seals.rows');

    expect($check()['passed'])->toBeTrue();

    DB::table('offerings')->where('id', $offering->id)->update(['amount_cop' => '999999.00']);

    expect($check()['passed'])->toBeFalse()
        ->and($check()['findings'][0])->toContain("offerings:{$offering->id}");
});

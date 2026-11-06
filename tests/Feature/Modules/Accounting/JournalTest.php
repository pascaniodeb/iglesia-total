<?php

use App\Enums\UserRole;
use App\Models\User;
use App\Modules\Accounting\Application\Actions\VerifyJournal;
use App\Modules\Accounting\Application\Ledger;
use App\Modules\Accounting\Domain\Journal\JournalEntryDraft;
use App\Modules\Accounting\Domain\Journal\JournalLine;
use App\Modules\Accounting\Domain\Journal\LedgerConflict;
use App\Modules\Accounting\Infrastructure\Models\AccountingAccount;
use App\Modules\Accounting\Infrastructure\Models\JournalEntry;
use App\Modules\Integrity\Infrastructure\Models\ActivityLogEntry;
use App\Modules\Shared\Money\Currency;
use App\Modules\Shared\Money\Money;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $treasurer = User::factory()->create(['name' => 'Rosa Tesorera']);
    $treasurer->forceFill(['role' => UserRole::Treasurer])->save();
    $this->actingAs($treasurer);

    AccountingAccount::factory()->create(['code' => 'I-002', 'name' => 'OFRENDAS DIARIAS']);
    AccountingAccount::factory()->expense()->create(['code' => 'E-024', 'name' => 'SERVICIOS PÚBLICOS']);
});

function offeringIncome(string $originId = 'week-45', string $cop = '141000.00', string $usd = '10.00'): JournalEntryDraft
{
    return new JournalEntryDraft('2026-11-30', 'Ofrendas de noviembre', 'monthly_offering_closure', $originId, 'income', [
        JournalLine::debit('A-001', Money::of($cop, Currency::COP)),
        JournalLine::credit('I-002', Money::of($cop, Currency::COP)),
        JournalLine::debit('A-001', Money::of($usd, Currency::USD)),
        JournalLine::credit('I-002', Money::of($usd, Currency::USD)),
    ]);
}

function ledger(): Ledger
{
    return app(Ledger::class);
}

test('posting writes the entry, its lines and the activity record', function () {
    $posted = ledger()->post(offeringIncome());

    $entry = JournalEntry::query()->with('lines.account')->findOrFail($posted->id);

    expect($posted->month)->toBe('2026-11')
        ->and($posted->wasAlreadyPosted)->toBeFalse()
        ->and($entry->lines)->toHaveCount(4)
        ->and($entry->lines->pluck('account.code')->all())->toBe(['A-001', 'I-002', 'A-001', 'I-002'])
        ->and($entry->lines[0]->debit)->toBe('141000.00')
        ->and($entry->created_by)->toBe(auth()->id())
        ->and(ActivityLogEntry::query()->where('action', 'journal_entry.posted')->sole()->changes['totals'])
        ->toEqual(['COP' => '141000.00', 'USD' => '10.00']);
});

test('posting the same fact again returns the same entry; with other content it is refused', function () {
    $first = ledger()->post(offeringIncome());
    $again = ledger()->post(offeringIncome());

    expect($again->id)->toBe($first->id)
        ->and($again->wasAlreadyPosted)->toBeTrue()
        ->and(JournalEntry::query()->count())->toBe(1);

    expect(fn () => ledger()->post(offeringIncome(cop: '141000.01')))
        ->toThrow(LedgerConflict::class, 'already posted with different content');
});

test('refuses unknown or inactive accounts without writing anything', function () {
    AccountingAccount::query()->where('code', 'E-024')->sole()->forceFill(['is_active' => false])->save();
    $draft = fn (string $code) => new JournalEntryDraft('2026-11-05', 'Pago', 'manual_movement', $code, 'expense', [
        JournalLine::debit($code, Money::of('50.00', Currency::USD)),
        JournalLine::credit('A-001', Money::of('50.00', Currency::USD)),
    ]);

    expect(fn () => ledger()->post($draft('E-999')))->toThrow(LedgerConflict::class, 'There is no account')
        ->and(fn () => ledger()->post($draft('E-024')))->toThrow(LedgerConflict::class, 'is inactive');

    expect(JournalEntry::query()->count())->toBe(0);
});

test('a reversal inverts every line, keeps both entries and is idempotent', function () {
    $posted = ledger()->post(offeringIncome());

    $reversal = ledger()->reverse($posted->id, 'Monto mal digitado');
    $again = ledger()->reverse($posted->id, 'Monto mal digitado');

    $entry = JournalEntry::query()->with('lines.account')->findOrFail($reversal->id);

    expect($again->id)->toBe($reversal->id)
        ->and($entry->reversal_of)->toBe($posted->id)
        ->and($entry->reversal_reason)->toBe('Monto mal digitado')
        ->and($entry->description)->toBe('CONTRAASIENTO: Ofrendas de noviembre')
        ->and($entry->lines[0]->account->code)->toBe('A-001')
        ->and($entry->lines[0]->credit)->toBe('141000.00')
        ->and($entry->lines[0]->debit)->toBe('0.00')
        ->and(JournalEntry::query()->count())->toBe(2)
        ->and(ActivityLogEntry::query()->where('action', 'journal_entry.reversed')->count())->toBe(1);
});

test('a reversal needs a reason and cannot itself be reversed', function () {
    $posted = ledger()->post(offeringIncome());

    expect(fn () => ledger()->reverse($posted->id, '   '))->toThrow(LedgerConflict::class, 'needs a reason');

    $reversal = ledger()->reverse($posted->id, 'Error');

    expect(fn () => ledger()->reverse($reversal->id, 'Otra vez'))->toThrow(LedgerConflict::class, 'cannot be reversed');
});

/**
 * Inserts an entry by hand, as SQL typed directly into the database would, and asks for the
 * deferred checks right away (inside the test transaction nothing is ever committed).
 *
 * @param  list<array{string, string, string, string}>  $lines  [account code, currency, debit, credit]
 */
function insertEntryByHand(array $lines, ?string $reversalOf = null): void
{
    DB::transaction(function () use ($lines, $reversalOf) {
        $id = (string) Str::uuid7();
        DB::table('journal_entries')->insert([
            'id' => $id,
            'entry_date' => '2026-11-10',
            'month' => '2026-11',
            'description' => 'A mano',
            'origin_type' => 'by_hand',
            'origin_id' => $id,
            'origin_purpose' => 'test',
            'reversal_of' => $reversalOf,
            'reversal_reason' => $reversalOf === null ? null : 'Prueba',
            'lines_count' => count($lines),
        ]);

        foreach ($lines as $index => [$code, $currency, $debit, $credit]) {
            DB::table('journal_entry_lines')->insert([
                'journal_entry_id' => $id,
                'line_number' => $index + 1,
                'account_id' => AccountingAccount::query()->where('code', $code)->value('id'),
                'currency' => $currency,
                'debit' => $debit,
                'credit' => $credit,
            ]);
        }

        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    });
}

test('the database refuses an entry that does not balance in a currency', function () {
    expect(fn () => insertEntryByHand([
        ['A-001', 'COP', '100.00', '0'],
        ['I-002', 'COP', '0', '99.99'],
    ]))->toThrow(QueryException::class, 'does not balance');

    expect(fn () => insertEntryByHand([
        ['A-001', 'COP', '100.00', '0'],
        ['I-002', 'USD', '0', '100.00'],
    ]))->toThrow(QueryException::class, 'does not balance');

    expect(JournalEntry::query()->count())->toBe(0);
});

test('the database refuses a reversal that does not mirror its original', function () {
    $posted = ledger()->post(offeringIncome());

    expect(fn () => insertEntryByHand([
        ['I-002', 'COP', '141000.00', '0'],
        ['A-001', 'COP', '0', '141000.00'],
    ], $posted->id))->toThrow(QueryException::class, 'must invert exactly');
});

test('entries and lines cannot be changed or deleted', function (string $statement) {
    ledger()->post(offeringIncome());

    expect(fn () => DB::statement($statement))->toThrow(QueryException::class, 'append-only');
})->with([
    'update entry' => "UPDATE journal_entries SET description = 'otra'",
    'delete entry' => 'DELETE FROM journal_entries',
    'update line' => 'UPDATE journal_entry_lines SET debit = debit + 1 WHERE debit > 0',
    'delete line' => 'DELETE FROM journal_entry_lines',
    'truncate' => 'TRUNCATE journal_entry_lines',
]);

test('verification recomputes the journal and finds it balanced to the cent', function () {
    $posted = ledger()->post(offeringIncome());
    ledger()->post(offeringIncome('week-46', '0.10', '0.20'));
    ledger()->reverse($posted->id, 'Error');

    $result = app(VerifyJournal::class)();

    expect($result['passed'])->toBeTrue()
        ->and($result['entries'])->toBe(3)
        ->and($result['currencies']['COP'])->toBe(['debit' => '282000.10', 'credit' => '282000.10', 'balanced' => true])
        ->and($result['currencies']['USD'])->toBe(['debit' => '20.20', 'credit' => '20.20', 'balanced' => true]);
});

test('verification detects a line altered directly in the database', function () {
    $posted = ledger()->post(offeringIncome());

    DB::statement('ALTER TABLE journal_entry_lines DISABLE TRIGGER journal_entry_lines_no_update');
    DB::statement("UPDATE journal_entry_lines SET credit = '140000.00' WHERE line_number = 2");
    DB::statement('ALTER TABLE journal_entry_lines ENABLE TRIGGER journal_entry_lines_no_update');

    $result = app(VerifyJournal::class)();

    expect($result['passed'])->toBeFalse()
        ->and($result['unbalanced_entries'])->toBe([$posted->id])
        ->and($result['currencies']['COP']['balanced'])->toBeFalse();

    $this->artisan('journal:verify')->assertFailed();
    expect(ActivityLogEntry::query()->where('action', 'journal.verified')->sole()->changes['passed'])->toBeFalse();
});

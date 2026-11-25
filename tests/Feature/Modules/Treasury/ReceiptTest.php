<?php

use App\Enums\UserRole;
use App\Modules\Accounting\Application\Actions\ClosePeriod;
use App\Modules\Accounting\Application\CashMovements;
use App\Modules\Accounting\Application\Ledger;
use App\Modules\Accounting\Domain\Journal\JournalEntryDraft;
use App\Modules\Accounting\Domain\Journal\JournalLine;
use App\Modules\Accounting\Infrastructure\Models\AccountingAccount;
use App\Modules\Accounting\Infrastructure\Models\JournalEntry;
use App\Modules\Integrity\Application\Actions\CreateLedgerGenesis;
use App\Modules\Integrity\Application\Actions\VerifyLedgerChain;
use App\Modules\Integrity\Domain\Ledger\BlockIssue;
use App\Modules\Integrity\Infrastructure\Models\ActivityLogEntry;
use App\Modules\Shared\Money\Currency;
use App\Modules\Shared\Money\Money;
use App\Modules\Shared\Time\Month;
use App\Modules\Treasury\Application\Actions\IssueReceipt;
use App\Modules\Treasury\Application\Actions\SaveReceiptSettings;
use App\Modules\Treasury\Application\Actions\VoidReceipt;
use App\Modules\Treasury\Application\Receipts\ReceiptBook;
use App\Modules\Treasury\Infrastructure\Models\Receipt;
use App\Modules\Treasury\Infrastructure\Models\ReceiptVoid;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    // Monday 16 November 2026 in Caracas: October has ended, November is running.
    Carbon::setTestNow('2026-11-16 14:00:00');
    signInAs(UserRole::Treasurer);

    $this->income = AccountingAccount::factory()->create(['code' => 'I-007', 'name' => 'APORTES']);
    $this->expense = AccountingAccount::factory()->expense()->create(['code' => 'E-024', 'name' => 'SERVICIOS PÚBLICOS']);
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function receiptData(array $overrides = []): array
{
    return [
        'type' => 'income',
        'issued_on' => '2026-11-10',
        'account_id' => test()->income->id,
        'holder' => 'María Pérez',
        'amount_cop' => '100000',
        'concept' => 'Aporte para el templo',
        'payment_method' => 'cash',
        'token' => (string) Str::uuid7(),
        ...$overrides,
    ];
}

/**
 * @param  array<string, mixed>  $overrides
 */
function issuedReceipt(array $overrides = []): Receipt
{
    return app(IssueReceipt::class)(receiptData($overrides));
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, list<string>>
 */
function receiptRefusal(array $overrides = []): array
{
    try {
        issuedReceipt($overrides);
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    return [];
}

/**
 * @param  array<string, mixed>  $overrides
 */
function issuedExpense(array $overrides = []): Receipt
{
    return issuedReceipt(['type' => 'expense', 'account_id' => test()->expense->id, 'concept' => 'Pago de la luz', ...$overrides]);
}

test('issuing a receipt numbers it and posts its entry to CAJA and its account, per currency', function () {
    $receipt = issuedReceipt(['amount_ves' => '500,50', 'amount_usd' => '25']);
    $entry = JournalEntry::query()->with('lines.account')->findOrFail($receipt->journal_entry_id);

    expect($receipt->number)->toBe(1)
        ->and($entry->description)->toBe('Comprobante N.º 000001 · Aporte para el templo')
        ->and($entry->origin_type)->toBe('receipt')
        ->and($entry->entry_date->toDateString())->toBe('2026-11-10')
        ->and($entry->lines)->toHaveCount(6)
        ->and($entry->lines->map(fn ($line) => [$line->account->code, $line->currency->value, $line->debit, $line->credit])->all())
        ->toBe([
            ['A-001', 'VES', '500.50', '0.00'], ['I-007', 'VES', '0.00', '500.50'],
            ['A-001', 'USD', '25.00', '0.00'], ['I-007', 'USD', '0.00', '25.00'],
            ['A-001', 'COP', '100000.00', '0.00'], ['I-007', 'COP', '0.00', '100000.00'],
        ]);
});

test('income and expense share one series, with no gaps', function () {
    $numbers = [
        issuedReceipt()->number,
        issuedExpense(['amount_cop' => '30000'])->number,
        issuedReceipt(['amount_cop' => '5000'])->number,
        issuedExpense(['amount_cop' => '1000'])->number,
    ];

    expect($numbers)->toBe([1, 2, 3, 4])
        ->and(ReceiptBook::nextNumber())->toBe(5);
});

test('raising the start number begins a new book and lowering it never reuses a number', function () {
    issuedReceipt();

    app(SaveReceiptSettings::class)(500);
    expect(ReceiptBook::nextNumber())->toBe(500)
        ->and(issuedReceipt()->number)->toBe(500);

    app(SaveReceiptSettings::class)(3);
    expect(issuedReceipt()->number)->toBe(501);
});

test('the database assigns the number: one written in the row is ignored', function () {
    issuedReceipt();

    // A valid row, with its own entry, that asks for number 99.
    $id = (string) Str::uuid7();
    $entry = app(Ledger::class)->post(new JournalEntryDraft('2026-11-10', 'Comprobante escrito a mano', 'receipt', $id, 'income', [
        JournalLine::debit('A-001', Money::of('7.00', Currency::COP)),
        JournalLine::credit('I-007', Money::of('7.00', Currency::COP)),
    ]));

    DB::table('receipts')->insert([
        'id' => $id,
        'number' => 99,
        'type' => 'income',
        'issued_on' => '2026-11-10',
        'account_id' => $this->income->id,
        'amount_cop' => '7.00',
        'payment_method' => 'cash',
        'journal_entry_id' => $entry->id,
    ]);

    expect(DB::table('receipts')->where('id', $id)->value('number'))->toBe(2);
});

test('sending the same form twice issues one receipt', function () {
    $token = (string) Str::uuid7();

    $first = issuedReceipt(['token' => $token]);
    $second = issuedReceipt(['token' => $token]);

    expect($second->id)->toBe($first->id)
        ->and(Receipt::query()->count())->toBe(1)
        ->and(JournalEntry::query()->where('origin_type', 'receipt')->count())->toBe(1);
});

test('an expense takes no more than the cash has in any currency, and refuses the whole receipt', function () {
    issuedReceipt(['amount_cop' => '100000', 'amount_usd' => '10']);

    $errors = receiptRefusal(['type' => 'expense', 'account_id' => $this->expense->id, 'amount_cop' => '50000', 'amount_usd' => '10.01']);

    expect($errors)->toHaveKey('amount_usd')->not->toHaveKey('amount_cop')
        ->and($errors['amount_usd'][0])->toContain('disponible 10,00')->toContain('faltan 0,01')
        // Nothing was written, not even the currency that had the money.
        ->and(Receipt::query()->count())->toBe(1)
        ->and(JournalEntry::query()->count())->toBe(1)
        ->and(issuedExpense(['amount_cop' => '50000', 'amount_usd' => '10'])->number)->toBe(2);
});

test('the account must be an active one of the receipt type', function (string $type, string $account) {
    $ids = [
        'income' => $this->income->id,
        'expense' => $this->expense->id,
        'inactive' => AccountingAccount::factory()->expense()->inactive()->create(['code' => 'E-099'])->id,
        'system' => AccountingAccount::query()->where('code', 'A-001')->value('id'),
    ];

    expect(receiptRefusal(['type' => $type, 'account_id' => $ids[$account]]))->toHaveKey('account_id');
})->with([
    'an expense account on an income receipt' => ['income', 'expense'],
    'an income account on an expense receipt' => ['expense', 'income'],
    'an inactive account' => ['expense', 'inactive'],
    'CAJA' => ['income', 'system'],
]);

test('the date is inside an open month and never in the future', function (string $date) {
    expect(receiptRefusal(['issued_on' => $date]))->toHaveKey('issued_on');
})->with([
    'in the future' => ['2026-11-17'],
    'not a date' => ['2026-02-30'],
]);

test('the dates a receipt can have start after the last closed month and end today', function () {
    expect(app(CashMovements::class)->window())->toBe(['min' => null, 'max' => '2026-11-16']);

    app(ClosePeriod::class)(Month::fromKey('2026-10'));

    expect(app(CashMovements::class)->window())->toBe(['min' => '2026-11-01', 'max' => '2026-11-16']);
});

test('a closed month receives no receipts', function () {
    app(ClosePeriod::class)(Month::fromKey('2026-10'));

    expect(receiptRefusal(['issued_on' => '2026-10-20']))->toHaveKey('issued_on')
        ->and(issuedReceipt(['issued_on' => '2026-11-02'])->number)->toBe(1);
});

test('at least one amount, with two decimals at most', function (array $amounts) {
    expect(receiptRefusal(['amount_cop' => null, ...$amounts]))->toHaveKey('amount_ves');
})->with([
    'none' => [[]],
    'all zero' => [['amount_ves' => '0', 'amount_usd' => '0,00']],
    'three decimals' => [['amount_cop' => '10.005']],
    'a thousands separator' => [['amount_cop' => '1.000,50']],
    'negative' => [['amount_cop' => '-5']],
]);

test('a cash receipt carries no bank data and a transfer keeps it', function () {
    $bank = ['bank' => 'Banesco', 'payment_reference' => '00123', 'payment_date' => '2026-11-09'];

    $cash = issuedReceipt($bank);
    $transfer = issuedReceipt([...$bank, 'payment_method' => 'transfer']);

    expect([$cash->bank, $cash->payment_reference, $cash->payment_date])->toBe([null, null, null])
        ->and([$transfer->bank, $transfer->payment_reference, $transfer->payment_date?->toDateString()])->toBe(['Banesco', '00123', '2026-11-09']);
});

test('issuing is logged without the ID numbers of the signers', function () {
    issuedReceipt(['received_by_name' => 'Pablo Ascanio', 'received_by_id_number' => 'V-1234567']);

    $log = ActivityLogEntry::query()->where('action', 'receipt.issued')->sole();
    $changes = json_encode($log->changes);

    expect($log->summary)->toContain('000001')
        ->and($changes)->toContain('Pablo Ascanio')
        ->and($changes)->not->toContain('V-1234567');
});

test('voiding keeps the receipt and its number and reverses its entry', function () {
    $receipt = issuedReceipt();

    $void = app(VoidReceipt::class)($receipt, 'Monto mal escrito');
    $reversal = JournalEntry::query()->findOrFail($void->reversal_entry_id);

    expect($void->reason)->toBe('Monto mal escrito')
        ->and($reversal->reversal_of)->toBe($receipt->journal_entry_id)
        ->and($receipt->fresh()->isVoid())->toBeTrue()
        ->and($receipt->fresh()->number)->toBe(1)
        // The voided number is never reused: the next receipt is the following one.
        ->and(issuedReceipt()->number)->toBe(2);
});

test('a receipt is voided once, with a reason', function () {
    $receipt = issuedReceipt();

    expect(fn () => app(VoidReceipt::class)($receipt, 'no'))->toThrow(ValidationException::class);

    app(VoidReceipt::class)($receipt, 'Error de captura');

    expect(fn () => app(VoidReceipt::class)($receipt, 'Otra vez, por favor'))->toThrow(ValidationException::class, 'ya se anuló');
});

test('voiding an income whose money was spent is refused, voiding an expense gives the money back', function () {
    $income = issuedReceipt(['amount_cop' => '100000']);
    $expense = issuedExpense(['amount_cop' => '95000']);

    expect(fn () => app(VoidReceipt::class)($income, 'Error de captura'))->toThrow(ValidationException::class);

    app(VoidReceipt::class)($expense, 'Se pagó dos veces');
    app(VoidReceipt::class)($income, 'Error de captura');

    expect(ReceiptVoid::query()->count())->toBe(2);
});

test('voiding a receipt of a closed month is recorded forward and leaves that month sealed as it was', function () {
    app(CreateLedgerGenesis::class)('M07A1985C0001', '2026-W40');
    $receipt = issuedReceipt(['issued_on' => '2026-10-20', 'amount_cop' => '40000']);
    app(ClosePeriod::class)(Month::fromKey('2026-10'));

    expect(app(VerifyLedgerChain::class)()['matches_books'])->toBeTrue();

    $void = app(VoidReceipt::class)($receipt, 'Comprobante duplicado');
    $reversal = JournalEntry::query()->findOrFail($void->reversal_entry_id);

    // D7: the closed month does not change; the reversal is dated today, in an open month.
    expect($reversal->entry_date->toDateString())->toBe('2026-11-16')
        ->and($reversal->month)->toBe('2026-11')
        ->and(app(VerifyLedgerChain::class)()['matches_books'])->toBeTrue();
});

test('the seal of a month binds each receipt: a receipt changed behind the guards is drift', function () {
    app(CreateLedgerGenesis::class)('M07A1985C0001', '2026-W40');
    issuedReceipt(['issued_on' => '2026-10-20']);
    app(ClosePeriod::class)(Month::fromKey('2026-10'));

    expect(app(VerifyLedgerChain::class)()['matches_books'])->toBeTrue();

    DB::statement('ALTER TABLE receipts DISABLE TRIGGER receipts_no_update');
    DB::statement("UPDATE receipts SET holder = 'Otra persona'");
    DB::statement('ALTER TABLE receipts ENABLE TRIGGER receipts_no_update');

    $issues = collect(app(VerifyLedgerChain::class)()['issues']);

    expect($issues->where('type', BlockIssue::DRIFT)->pluck('periodKey')->all())->toContain('2026-10');
});

test('receipts and their voids are append-only', function () {
    $receipt = issuedReceipt();
    app(VoidReceipt::class)($receipt, 'Error de captura');

    expect(fn () => DB::transaction(fn () => DB::statement("UPDATE receipts SET holder = 'Otra'")))->toThrow(QueryException::class, 'append-only')
        ->and(fn () => DB::transaction(fn () => DB::statement('DELETE FROM receipts')))->toThrow(QueryException::class, 'append-only')
        ->and(fn () => DB::transaction(fn () => DB::statement("UPDATE receipt_voids SET reason = 'Otra razón'")))->toThrow(QueryException::class, 'append-only')
        ->and(fn () => DB::transaction(fn () => DB::statement('DELETE FROM receipt_voids')))->toThrow(QueryException::class, 'append-only');
});

test('the database refuses a receipt whose entry does not post its amounts', function () {
    $first = issuedReceipt(['amount_cop' => '100000']);
    $other = (array) DB::table('receipts')->where('id', $first->id)->first();
    $draft = issuedReceipt(['amount_cop' => '2000']);
    $entryId = $draft->journal_entry_id;
    $id = (string) Str::uuid7();

    // A row that points to an entry posted for another receipt, or with other amounts.
    $forged = [...$other, 'id' => $id, 'journal_entry_id' => $entryId];

    expect(fn () => DB::transaction(fn () => DB::table('receipts')->insert($forged)))->toThrow(QueryException::class, 'receipt must be the one posted for it');
});

test('the database refuses to void a receipt with another entry', function () {
    $first = issuedReceipt();
    $second = issuedReceipt(['amount_cop' => '3000']);

    expect(fn () => DB::transaction(fn () => DB::table('receipt_voids')->insert([
        'receipt_id' => $first->id,
        'reason' => 'Un motivo cualquiera',
        'reversal_entry_id' => $second->journal_entry_id,
    ])))->toThrow(QueryException::class, 'reversal of a voided receipt');
});

test('the settings are one row and a start number below one is refused', function () {
    expect(fn () => DB::transaction(fn () => DB::table('receipt_settings')->insert(['id' => 2, 'start_number' => 1])))->toThrow(QueryException::class)
        ->and(fn () => app(SaveReceiptSettings::class)(0))->toThrow(ValidationException::class)
        ->and(fn () => DB::transaction(fn () => DB::statement('DELETE FROM receipt_settings')))->toThrow(QueryException::class);
});

test('changing the start number is logged', function () {
    app(SaveReceiptSettings::class)(250);
    app(SaveReceiptSettings::class)(250);

    expect(ActivityLogEntry::query()->where('action', 'receipt_settings.updated')->count())->toBe(1);
});

<?php

use App\Modules\Integrity\Infrastructure\Models\ActivityLogEntry;
use App\Modules\Treasury\Application\Actions\IssueReceipt;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->daily = seedThreeLegacyMonths();
    $this->artisan('accounting:import-legacy-books')->assertSuccessful();
});

afterEach(function () {
    Carbon::setTestNow();
    dropLegacySource();
});

/**
 * Changes rows behind the guards, the way a tamperer with table ownership would.
 */
function tamper(string $table, string $trigger, string $sql): void
{
    DB::statement("ALTER TABLE {$table} DISABLE TRIGGER {$trigger}");
    DB::statement($sql);
    DB::statement("ALTER TABLE {$table} ENABLE TRIGGER {$trigger}");
}

test('every control passes on books that were built by the application', function () {
    app(IssueReceipt::class)([
        'type' => 'income', 'issued_on' => '2025-04-10', 'account_id' => $this->daily->id, 'holder' => 'María Pérez',
        'amount_cop' => '100000', 'amount_usd' => '5', 'concept' => 'Aporte', 'payment_method' => 'cash', 'token' => (string) Str::uuid7(),
    ]);

    $this->artisan('books:verify')->assertSuccessful();

    expect(ActivityLogEntry::query()->where('action', 'books.verified')->count())->toBe(1);
});

test('a closed week that no longer holds its offerings is found', function () {
    tamper('weekly_closures', 'weekly_closures_no_update', "UPDATE weekly_closures SET total_cop = total_cop + 1 WHERE week_key = '2025-W01'");

    $this->artisan('books:verify')->assertFailed();
});

test('a monthly closure that no longer holds its month is found', function () {
    tamper('monthly_offering_closure_lines', 'monthly_offering_closure_lines_no_update', 'UPDATE monthly_offering_closure_lines SET total_cop = total_cop + 1 WHERE id = (SELECT id FROM monthly_offering_closure_lines LIMIT 1)');

    $this->artisan('books:verify')->assertFailed();
});

test('a balance snapshot that differs from the journal is found', function () {
    tamper('accounting_period_balances', 'accounting_period_balances_no_update', 'UPDATE accounting_period_balances SET total_debit = total_debit + 1 WHERE id = (SELECT id FROM accounting_period_balances LIMIT 1)');

    $this->artisan('books:verify')->assertFailed();
});

test('a receipt whose amount differs from the cash it moved is found', function () {
    app(IssueReceipt::class)([
        'type' => 'income', 'issued_on' => '2025-04-10', 'account_id' => $this->daily->id, 'holder' => 'María Pérez',
        'amount_cop' => '100000', 'concept' => 'Aporte', 'payment_method' => 'cash', 'token' => (string) Str::uuid7(),
    ]);
    tamper('receipts', 'receipts_no_update', 'UPDATE receipts SET amount_cop = amount_cop + 1');

    $this->artisan('books:verify')->assertFailed();
});

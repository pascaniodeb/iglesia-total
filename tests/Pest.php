<?php

use App\Enums\UserRole;
use App\Models\User;
use App\Modules\Accounting\Infrastructure\Models\AccountingAccount;
use App\Modules\Integrity\Application\Actions\CreateLedgerGenesis;
use App\Modules\Treasury\Application\Actions\CloseWeek;
use App\Modules\Treasury\Application\Closures\WeeklyClosureSchedule;
use App\Modules\Treasury\Application\Import\PreviewOfferingImport;
use App\Modules\Treasury\Infrastructure\Models\Offering;
use App\Modules\Treasury\Infrastructure\Models\OfferingCategory;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * Signs in a new user that holds the given role.
 */
function signInAs(UserRole $role): User
{
    $user = User::factory()->role($role)->create();
    test()->actingAs($user);

    return $user;
}

/**
 * The old system lives in another database. In tests it is a `legacy` schema inside
 * the test database, reached through its own connection, so the import commands run
 * against real tables with the old integer-id shape.
 */
function createLegacySource(): void
{
    config(['database.connections.legacy' => [
        ...config('database.connections.pgsql'),
        'search_path' => 'legacy',
    ]]);
    DB::purge('legacy');

    $legacy = DB::connection('legacy');
    $legacy->statement('DROP SCHEMA IF EXISTS legacy CASCADE');
    $legacy->statement('CREATE SCHEMA legacy');

    Schema::connection('legacy')->create('members', function (Blueprint $table) {
        $table->integer('id')->primary();
        $table->text('full_name');
        foreach ([
            'document_type', 'document_id', 'sex', 'birth_place_city', 'birth_place_state',
            'phone', 'landline_phone', 'email', 'address_state', 'address_municipality',
            'address_parish', 'address', 'education_level', 'profession', 'occupation',
            'marital_status', 'baptism_place', 'baptism_pastor', 'status', 'pastor_notes',
            'photo_key',
        ] as $column) {
            $table->text($column)->nullable();
        }
        foreach (['birth_date', 'baptism_date', 'holy_spirit_date', 'joined_date'] as $column) {
            $table->date($column)->nullable();
        }
        $table->integer('spouse_id')->nullable();
        $table->timestampTz('created_at');
        $table->timestampTz('updated_at');
        $table->timestampTz('deleted_at')->nullable();
    });

    Schema::connection('legacy')->create('member_children', function (Blueprint $table) {
        $table->integer('id')->primary();
        $table->integer('member_id');
        $table->text('name');
        $table->date('birth_date')->nullable();
        $table->timestampTz('created_at');
    });

    Schema::connection('legacy')->create('member_roles', function (Blueprint $table) {
        $table->integer('id')->primary();
        $table->integer('member_id');
        $table->text('role');
        $table->date('since')->nullable();
        $table->date('until')->nullable();
        $table->timestampTz('created_at');
    });

    Schema::connection('legacy')->create('users', function (Blueprint $table) {
        $table->integer('id')->primary();
        $table->text('email');
        $table->text('name');
        $table->text('role');
        $table->boolean('email_verified')->default(false);
        $table->boolean('is_active')->default(true);
        $table->integer('member_id')->nullable();
        $table->timestampTz('created_at');
        $table->timestampTz('updated_at');
    });
}

/**
 * @param  array<string, mixed>  $attributes
 */
function legacyMember(int $id, array $attributes = []): void
{
    DB::connection('legacy')->table('members')->insert([
        'id' => $id,
        'full_name' => "Legacy Member {$id}",
        'created_at' => '2026-03-15 10:30:00+00',
        'updated_at' => '2026-03-16 08:00:00+00',
        ...$attributes,
    ]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function legacyUser(int $id, array $attributes = []): void
{
    DB::connection('legacy')->table('users')->insert([
        'id' => $id,
        'email' => "legacy{$id}@example.com",
        'name' => "Legacy User {$id}",
        'role' => 'secretaria',
        'created_at' => '2026-03-15 10:30:00+00',
        'updated_at' => '2026-03-16 08:00:00+00',
        ...$attributes,
    ]);
}

/**
 * The old system's tables that hold the money, with the old integer-id shape.
 */
function createLegacyTreasuryTables(): void
{
    Schema::connection('legacy')->create('offerings', function (Blueprint $table) {
        $table->integer('id')->primary();
        $table->date('date');
        $table->integer('member_id')->nullable();
        $table->integer('offering_category_id');
        $table->text('concept')->nullable();
        foreach (['amount_ves', 'amount_usd', 'amount_cop'] as $column) {
            $table->decimal($column, 18, 2)->default(0);
        }
        $table->text('week_key')->nullable();
        $table->text('month_key')->nullable();
        $table->integer('created_by')->nullable();
        $table->timestampTz('created_at');
        $table->timestampTz('updated_at');
        $table->timestampTz('deleted_at')->nullable();
    });

    Schema::connection('legacy')->create('weekly_closures', function (Blueprint $table) {
        $table->integer('id')->primary();
        $table->text('week_key');
        $table->date('week_start');
        $table->date('week_end');
        $table->text('month_key')->nullable();
        $table->integer('offerings_count');
        foreach (['total_ves', 'total_usd', 'total_cop'] as $column) {
            $table->decimal($column, 18, 2)->default(0);
        }
        $table->text('status')->default('closed');
        $table->timestampTz('closed_at')->nullable();
        $table->timestampTz('deleted_at')->nullable();
    });

    Schema::connection('legacy')->create('account_codes', function (Blueprint $table) {
        $table->integer('id')->primary();
        $table->text('code');
        $table->text('name');
        $table->text('type');
        $table->boolean('is_active')->default(true);
    });

    Schema::connection('legacy')->create('monthly_closures', function (Blueprint $table) {
        $table->integer('id')->primary();
        $table->text('month_key');
        $table->integer('offerings_count')->default(0);
        foreach (['total_ves', 'total_usd', 'total_cop'] as $column) {
            $table->decimal($column, 18, 2)->default(0);
        }
        $table->text('status')->default('closed');
        $table->timestampTz('deleted_at')->nullable();
    });

    Schema::connection('legacy')->create('accounting_entries', function (Blueprint $table) {
        $table->integer('id')->primary();
        $table->date('entry_date');
        $table->text('type');
        $table->integer('account_code_id');
        $table->text('concept')->nullable();
        $table->text('description')->nullable();
        foreach (['amount_ves', 'amount_usd', 'amount_cop'] as $column) {
            $table->decimal($column, 18, 2)->default(0);
        }
        $table->text('month_key');
        $table->integer('monthly_closure_id')->nullable();
        $table->timestampTz('posted_at')->nullable();
        $table->timestampTz('deleted_at')->nullable();
    });

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

    Schema::connection('legacy')->create('receipt_settings', function (Blueprint $table) {
        $table->integer('id')->primary();
        $table->integer('start_number');
    });
}

/**
 * @param  array<string, mixed>  $attributes
 */
function legacyOffering(int $id, array $attributes = []): void
{
    DB::connection('legacy')->table('offerings')->insert([
        'id' => $id,
        'date' => '2025-01-05',
        'member_id' => null,
        'offering_category_id' => 1,
        'amount_cop' => '1000.00',
        'created_at' => '2025-01-06 15:00:00+00',
        'updated_at' => '2025-01-06 15:00:00+00',
        ...$attributes,
    ]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function legacyWeeklyClosure(int $id, string $weekKey, string $start, array $attributes = []): void
{
    DB::connection('legacy')->table('weekly_closures')->insert([
        'id' => $id,
        'week_key' => $weekKey,
        'week_start' => $start,
        'week_end' => (new DateTimeImmutable($start))->modify('+6 days')->format('Y-m-d'),
        'offerings_count' => 1,
        'total_cop' => '1000.00',
        'status' => 'closed',
        'closed_at' => '2026-01-03 10:00:00+00',
        ...$attributes,
    ]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function legacyEntry(int $id, string $code, string $date, array $attributes = []): void
{
    $codeId = DB::connection('legacy')->table('account_codes')->where('code', $code)->value('id');

    DB::connection('legacy')->table('accounting_entries')->insert([
        'id' => $id,
        'entry_date' => $date,
        'type' => str_starts_with($code, 'E-') ? 'expense' : 'income',
        'account_code_id' => $codeId,
        'concept' => "Concepto {$id}",
        'month_key' => substr($date, 0, 7),
        ...$attributes,
    ]);
}

/**
 * The text of the page as a person would read it: the PDF streams are not compressed in the test
 * (FPDF compresses the streams: inflate each one and keep the text operators).
 */
function pdfText(string $pdf): string
{
    preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $streams);
    $text = '';

    foreach ($streams[1] as $stream) {
        $content = @gzuncompress($stream);
        preg_match_all('/\((.*?)\)\s*Tj/s', $content === false ? $stream : $content, $found);
        $text .= implode(' ', $found[1]).' ';
    }

    return mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
}

/**
 * Three months of the old books, small enough to read, with the new system ready to receive them
 * (accounts, category, offerings, the sealed ledger open and every week closed):
 *
 *   January:  opening USD 2 + COP 10.000 · offerings COP 100.000 · expense COP 30.000   → closes COP 80.000
 *   February: opening USD 2 + COP 80.000 · offerings COP 50.000 + USD 5 · income USD 10 + COP 5.000 · expense COP 15.000
 *             → closes USD 17 + COP 120.000
 *   March:    opening USD 17 + COP 120.000 · nothing else
 *
 * The old side holds the same offerings and weekly closures, the consolidations, the openings, two
 * entries in the old trash, and the monthly closures. Returns the income account of the offerings.
 */
function seedThreeLegacyMonths(): AccountingAccount
{
    Carbon::setTestNow('2026-11-25 14:00:00');
    createLegacySource();
    createLegacyTreasuryTables();

    $daily = AccountingAccount::factory()->create(['code' => 'I-002', 'name' => 'OFRENDAS DIARIAS']);
    AccountingAccount::factory()->create(['code' => 'I-007', 'name' => 'APORTES']);
    AccountingAccount::factory()->expense()->create(['code' => 'E-024', 'name' => 'SERVICIOS PÚBLICOS']);
    $category = OfferingCategory::factory()->create(['account_id' => $daily->id, 'legacy_id' => 1]);

    // 2025-01-05 and 2025-02-02 are Sundays.
    Offering::factory()->create(['legacy_id' => 1, 'offering_category_id' => $category->id, 'offering_date' => '2025-01-05', 'amount_cop' => '100000.00']);
    Offering::factory()->create(['legacy_id' => 2, 'offering_category_id' => $category->id, 'offering_date' => '2025-02-02', 'amount_cop' => '50000.00', 'amount_usd' => '5.00']);

    app(CreateLedgerGenesis::class)('M07A1985C0001', '2025-W01');

    // Every week up to the one with 31 March (a Monday, 2025-W14) is closed.
    while (($week = WeeklyClosureSchedule::next()) !== null && $week->key() <= '2025-W14') {
        app(CloseWeek::class)($week);
    }

    $old = DB::connection('legacy');
    $old->table('offerings')->insert([
        ['id' => 1, 'date' => '2025-01-05', 'offering_category_id' => 1, 'amount_ves' => '0', 'amount_usd' => '0', 'amount_cop' => '100000.00', 'created_at' => '2025-01-06 15:00:00+00', 'updated_at' => '2025-01-06 15:00:00+00'],
        ['id' => 2, 'date' => '2025-02-02', 'offering_category_id' => 1, 'amount_ves' => '0', 'amount_usd' => '5.00', 'amount_cop' => '50000.00', 'created_at' => '2025-02-03 15:00:00+00', 'updated_at' => '2025-02-03 15:00:00+00'],
    ]);

    foreach (DB::table('weekly_closures')->orderBy('week_start')->get() as $index => $closure) {
        $old->table('weekly_closures')->insert([
            'id' => $index + 1, 'week_key' => $closure->week_key, 'week_start' => $closure->week_start, 'week_end' => $closure->week_end,
            'offerings_count' => $closure->offerings_count, 'total_ves' => $closure->total_ves, 'total_usd' => $closure->total_usd, 'total_cop' => $closure->total_cop,
            'status' => 'closed',
        ]);
    }

    foreach ([[1, 'I-001', 'income'], [2, 'I-002', 'income'], [3, 'I-007', 'income'], [4, 'E-024', 'expense']] as [$id, $code, $type]) {
        $old->table('account_codes')->insert(['id' => $id, 'code' => $code, 'name' => "{$code} (old)", 'type' => $type]);
    }

    foreach ([[1, '2025-01', 1, '0', '0', '100000.00'], [2, '2025-02', 1, '0', '5.00', '50000.00'], [3, '2025-03', 0, '0', '0', '0']] as [$id, $month, $count, $ves, $usd, $cop]) {
        $old->table('monthly_closures')->insert(['id' => $id, 'month_key' => $month, 'offerings_count' => $count, 'total_ves' => $ves, 'total_usd' => $usd, 'total_cop' => $cop]);
    }

    legacyEntry(1, 'I-001', '2025-01-01', ['amount_usd' => '2.00', 'amount_cop' => '10000.00', 'concept' => 'Saldo anterior del mes 2025-01']);
    legacyEntry(2, 'I-002', '2025-01-31', ['amount_cop' => '100000.00', 'monthly_closure_id' => 1]);
    legacyEntry(3, 'E-024', '2025-01-15', ['amount_cop' => '30000.00', 'concept' => 'Pago de la luz']);
    legacyEntry(4, 'I-001', '2025-02-01', ['amount_usd' => '2.00', 'amount_cop' => '80000.00']);
    legacyEntry(5, 'I-002', '2025-02-28', ['amount_usd' => '5.00', 'amount_cop' => '50000.00', 'monthly_closure_id' => 2]);
    legacyEntry(6, 'I-007', '2025-02-10', ['amount_usd' => '10.00', 'amount_cop' => '5000.00', 'concept' => 'Aporte']);
    legacyEntry(7, 'E-024', '2025-02-20', ['amount_cop' => '15000.00', 'concept' => '']);
    legacyEntry(8, 'I-001', '2025-03-01', ['amount_usd' => '17.00', 'amount_cop' => '120000.00']);
    // In the old trash: not imported.
    legacyEntry(9, 'E-024', '2025-02-21', ['amount_cop' => '999.00', 'deleted_at' => '2025-02-22 00:00:00+00']);
    legacyEntry(10, 'I-001', '2025-02-01', ['amount_cop' => '1.00', 'deleted_at' => '2025-02-02 00:00:00+00']);

    return $daily;
}

/**
 * Drops the legacy schema, only ever in the test database and never in the real old one.
 */
function dropLegacySource(): void
{
    if (config('database.connections.legacy.database') === config('database.connections.pgsql.database')) {
        DB::connection('legacy')->statement('DROP SCHEMA IF EXISTS legacy CASCADE');
    }
}

/**
 * Writes a real .xlsx with the template header and the given rows; returns its path.
 *
 * @param  list<list<mixed>>  $rows
 * @param  list<string>|null  $header
 */
function offeringWorkbook(array $rows, ?array $header = null): string
{
    $path = tempnam(sys_get_temp_dir(), 'ofrendas').'.xlsx';
    $writer = new Writer;
    $writer->openToFile($path);
    $writer->addRow(Row::fromValues($header ?? PreviewOfferingImport::HEADERS));

    // Dates go in as real Excel date cells (with a date format), as Excel writes a typed date.
    $dateStyle = (new Style)->withFormat('dd/mm/yyyy');

    foreach ($rows as $row) {
        $writer->addRow(new Row(array_map(
            fn (mixed $value) => $value instanceof DateTimeInterface ? Cell::fromValue($value, $dateStyle) : Cell::fromValue($value),
            $row,
        )));
    }

    $writer->close();

    return $path;
}

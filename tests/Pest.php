<?php

use App\Enums\UserRole;
use App\Models\User;
use App\Modules\Treasury\Application\Import\PreviewOfferingImport;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

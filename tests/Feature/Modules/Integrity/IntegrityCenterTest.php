<?php

use App\Enums\UserRole;
use App\Modules\Accounting\Infrastructure\Models\AccountingAccount;
use App\Modules\Church\Infrastructure\Models\ChurchSetting;
use App\Modules\Integrity\Application\Actions\CreateLedgerGenesis;
use App\Modules\Integrity\Infrastructure\Models\ActivityLogEntry;
use App\Modules\Shared\Time\IsoWeek;
use App\Modules\Treasury\Application\Actions\CloseWeek;
use App\Modules\Treasury\Infrastructure\Models\Offering;
use App\Modules\Treasury\Infrastructure\Models\OfferingCategory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Carbon::setTestNow('2026-11-16 14:00:00');
    signInAs(UserRole::Pastor);
});

afterEach(function () {
    Carbon::setTestNow();
});

test('the center lists the checks of every module, all holding on clean books', function () {
    $this->get(route('integrity.index'))->assertInertia(fn (Assert $page) => $page
        ->component('integrity/index')
        ->has('checks', 6)
        ->where('checks', fn ($checks) => collect($checks)->pluck('id')->sort()->values()->all() === ['books.months', 'books.posting', 'books.receipts', 'books.snapshots', 'books.weeks', 'journal.balance'])
        ->where('checks', fn ($checks) => collect($checks)->every(fn ($check) => $check['passed'])));
});

test('a control that no longer holds says what broke it', function () {
    $category = OfferingCategory::factory()->create(['account_id' => AccountingAccount::factory()->create()->id]);
    Offering::factory()->create(['offering_date' => '2026-10-28', 'offering_category_id' => $category->id, 'amount_cop' => '100.00']);
    app(CloseWeek::class)(IsoWeek::fromKey('2026-W44'));

    DB::statement('ALTER TABLE weekly_closures DISABLE TRIGGER weekly_closures_no_update');
    DB::statement("UPDATE weekly_closures SET total_cop = total_cop + 1 WHERE week_key = '2026-W44'");
    DB::statement('ALTER TABLE weekly_closures ENABLE TRIGGER weekly_closures_no_update');

    $this->get(route('integrity.index'))->assertInertia(fn (Assert $page) => $page
        ->where('checks', fn ($checks) => collect($checks)->firstWhere('id', 'books.weeks')['passed'] === false)
        ->where('checks', fn ($checks) => str_contains(collect($checks)->firstWhere('id', 'books.weeks')['findings'][0], '2026-W44')));
});

test('the status is empty before the genesis and has the head after it', function () {
    $this->get(route('integrity.index'))->assertInertia(fn (Assert $page) => $page
        ->where('status.blocks', 0)
        ->where('status.height', null)
        ->where('status.last_chain_check', null));

    app(CreateLedgerGenesis::class)('M07A1985C0001', '2026-W40');

    $this->get(route('integrity.index'))->assertInertia(fn (Assert $page) => $page
        ->where('status.blocks', 1)
        ->where('status.height', 0)
        ->has('blocks.data', 1)
        ->where('blocks.data.0.kind', 'genesis'));
});

test('the last chain verification and the history come from the log', function () {
    $this->artisan('ledger:verify');
    $this->artisan('journal:verify');

    $this->get(route('integrity.index'))->assertInertia(fn (Assert $page) => $page
        ->where('status.last_chain_check.intact', true)
        ->has('history', 2)
        ->where('history', fn ($history) => collect($history)->pluck('action')->sort()->values()->all() === ['journal.verified', 'ledger.verified'])
        ->where('history', fn ($history) => collect($history)->every(fn ($entry) => $entry['passed'])));

    expect(ActivityLogEntry::query()->where('action', 'ledger.verified')->count())->toBe(1);
});

test('blocks can be filtered by kind', function () {
    app(CreateLedgerGenesis::class)('M07A1985C0001', '2026-W40');

    $this->get(route('integrity.index', ['kind' => 'monthly']))->assertInertia(fn (Assert $page) => $page->has('blocks.data', 0));
    $this->get(route('integrity.index', ['kind' => 'genesis']))->assertInertia(fn (Assert $page) => $page->has('blocks.data', 1));
});

test('only who may read the sealed ledger gets in', function () {
    foreach ([UserRole::Treasurer, UserRole::Secretary, UserRole::Member] as $role) {
        signInAs($role);
        $this->get(route('integrity.index'))->assertForbidden();
    }

    signInAs(UserRole::Comptroller);
    $this->get(route('integrity.index'))->assertOk();
});

test('verifying now leaves the chain and the controls on the record and flashes the result', function () {
    app(CreateLedgerGenesis::class)('M07A1985C0001', '2026-W40');

    $this->post(route('integrity.verify'))->assertRedirect();

    expect(ActivityLogEntry::query()->where('action', 'ledger.verified')->count())->toBe(1)
        ->and(ActivityLogEntry::query()->where('action', 'integrity.verified')->count())->toBe(1);

    $this->get(route('integrity.index'))->assertInertia(fn (Assert $page) => $page
        ->where('status.last_chain_check.intact', true)
        ->where('history', fn ($history) => collect($history)->pluck('action')->contains('integrity.verified')));
});

test('verifying now needs the permission to read the sealed ledger', function () {
    signInAs(UserRole::Treasurer);

    $this->post(route('integrity.verify'))->assertForbidden();
});

test('the certificate states the head of the chain and the result of a verification made now', function () {
    ChurchSetting::factory()->create();
    app(CreateLedgerGenesis::class)('M07A1985C0001', '2026-W40');
    $head = DB::table('ledger_blocks')->orderByDesc('height')->value('hash');

    $response = $this->get(route('integrity.certificate'))->assertOk();
    $text = pdfText($response->getContent());

    expect($response->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($text)->toContain('CERTIFICADO DE INTEGRIDAD DEL LIBRO SELLADO')
        ->and($text)->toContain('CADENA ÍNTEGRA Y CUADRADA CON LOS LIBROS')
        ->and($text)->toContain(substr(trim($head), 0, 32))
        ->and($text)->toContain('SE CUMPLE')
        ->and($text)->not->toContain('CON DIFERENCIAS')
        ->and(ActivityLogEntry::query()->where('action', 'ledger.verified')->count())->toBe(1)
        ->and(ActivityLogEntry::query()->where('action', 'integrity.certificate_downloaded')->count())->toBe(1);
});

test('a certificate with differences never reads as if all were well', function () {
    ChurchSetting::factory()->create();
    $category = OfferingCategory::factory()->create(['account_id' => AccountingAccount::factory()->create()->id]);
    Offering::factory()->create(['offering_date' => '2026-10-28', 'offering_category_id' => $category->id, 'amount_cop' => '100.00']);
    app(CreateLedgerGenesis::class)('M07A1985C0001', '2026-W40');
    app(CloseWeek::class)(IsoWeek::fromKey('2026-W44'));

    DB::statement('ALTER TABLE offerings DISABLE TRIGGER offerings_closed_week_guard');
    DB::statement("UPDATE offerings SET amount_cop = '999.00'");
    DB::statement('ALTER TABLE offerings ENABLE TRIGGER offerings_closed_week_guard');

    $text = pdfText($this->get(route('integrity.certificate'))->getContent());

    expect($text)->toContain('SE ENCONTRARON DIFERENCIAS')
        ->and($text)->toContain('CON DIFERENCIAS')
        ->and($text)->not->toContain('CADENA ÍNTEGRA Y CUADRADA');
});

test('the certificate needs the church settings and the permission', function () {
    $this->get(route('integrity.certificate'))->assertStatus(409);

    signInAs(UserRole::Secretary);
    $this->get(route('integrity.certificate'))->assertForbidden();
});

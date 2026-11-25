<?php

use App\Enums\UserRole;
use App\Modules\Integrity\Infrastructure\Models\ActivityLogEntry;
use App\Modules\Members\Infrastructure\Models\Member;
use App\Modules\Treasury\Application\Import\ImportTithes;
use App\Modules\Treasury\Application\Import\PreviewTitheImport;
use App\Modules\Treasury\Infrastructure\Models\Tithe;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Carbon::setTestNow('2026-11-16 14:00:00');
    $this->ana = Member::factory()->create(['full_name' => 'Ana Pérez']);
    $this->gonzalez = Member::factory()->create(['full_name' => 'María Gonzalez']);
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * @param  list<list<mixed>>  $rows
 */
function titheWorkbook(array $rows): string
{
    return offeringWorkbook($rows, PreviewTitheImport::HEADERS);
}

/**
 * @return array<string, mixed>
 */
function tithePreview(string $path): array
{
    return app(PreviewTitheImport::class)($path)->toArray();
}

test('a valid workbook previews one tithe per row, in the currency of each, with totals', function () {
    $preview = tithePreview(titheWorkbook([
        ['01/11/2026', 'ana perez', 'VES', '20.000,50'],
        [new DateTimeImmutable('2026-11-08'), 'ANA PÉREZ', '$', 15],
        ['08/11/2026', 'maria gonzalez', 'pesos', 50000.5],
    ]));

    expect($preview['can_import'])->toBeTrue()
        ->and($preview['rejected'])->toBe([])
        ->and($preview['rows'][0])->toMatchArray(['line' => 2, 'tithe_date' => '2026-11-01', 'member_id' => $this->ana->id, 'amount_ves' => '20000.50', 'amount_usd' => '0.00', 'amount_cop' => '0.00'])
        ->and($preview['rows'][1])->toMatchArray(['tithe_date' => '2026-11-08', 'amount_usd' => '15.00', 'amount_ves' => '0.00'])
        ->and($preview['rows'][2])->toMatchArray(['member_id' => $this->gonzalez->id, 'amount_cop' => '50000.50'])
        ->and($preview['totals'])->toBe(['ves' => '20000.50', 'usd' => '15.00', 'cop' => '50000.50']);
});

test('every bad row is rejected with its line and reason', function (array $row, string $reason) {
    Member::factory()->create(['full_name' => 'Luis Gómez']);
    Member::factory()->create(['full_name' => 'Luis Gomez']);

    $preview = tithePreview(titheWorkbook([$row]));

    expect($preview['can_import'])->toBeFalse()
        ->and($preview['rejected'][0]['line'])->toBe(2)
        ->and($preview['rejected'][0]['reason'])->toContain($reason);
})->with([
    'bad date' => [['31/02/2026', 'Ana Pérez', 'VES', '10'], 'no es válida'],
    'future date' => [['17/11/2026', 'Ana Pérez', 'VES', '10'], 'futura'],
    'no member' => [['01/11/2026', '', 'VES', '10'], 'necesita su miembro'],
    'unknown member' => [['01/11/2026', 'Nadie Nunca', 'VES', '10'], 'no está en el padrón'],
    'ambiguous member' => [['01/11/2026', 'luis gomez', 'VES', '10'], 'ambiguo'],
    'no currency' => [['01/11/2026', 'Ana Pérez', '', '10'], 'no es válida'],
    'unknown currency' => [['01/11/2026', 'Ana Pérez', 'euros', '10'], 'use VES, USD o COP'],
    'no amount' => [['01/11/2026', 'Ana Pérez', 'VES', ''], 'mayor que cero'],
    'zero amount' => [['01/11/2026', 'Ana Pérez', 'USD', '0,00'], 'mayor que cero'],
    'three decimals in a number cell' => [['01/11/2026', 'Ana Pérez', 'VES', 10.005], 'no es válido'],
    'thousands typed wrong' => [['01/11/2026', 'Ana Pérez', 'VES', '1.00.0'], 'no es válido'],
]);

test('a member who is not in the registry is refused, also when in the trash', function () {
    Member::factory()->create(['full_name' => 'Borrado Gómez'])->delete();

    $preview = tithePreview(titheWorkbook([['01/11/2026', 'Borrado Gómez', 'VES', '10']]));

    expect($preview['rejected'][0]['reason'])->toContain('no está en el padrón');
});

test('a name that is almost right comes back with a suggestion', function () {
    $preview = tithePreview(titheWorkbook([
        ['01/11/2026', 'Maria Gonzales', 'VES', '10'],
        ['01/11/2026', 'Zzzzz Qqqqq', 'VES', '10'],
    ]));

    expect($preview['rejected'][0]['reason'])->toContain('¿Quiso decir «María Gonzalez»?')
        ->and($preview['rejected'][1]['reason'])->not->toContain('Quiso decir');
});

test('exact repeated rows are flagged, not refused', function () {
    $preview = tithePreview(titheWorkbook([
        ['01/11/2026', 'Ana Pérez', 'VES', '10'],
        ['01/11/2026', 'ana perez', 'VES', '10,00'],
        ['01/11/2026', 'Ana Pérez', 'USD', '10'],
    ]));

    expect($preview['can_import'])->toBeTrue()
        ->and($preview['duplicate_lines'])->toBe([3]);
});

test('the file must be an Excel workbook with the template headers', function () {
    $notExcel = tempnam(sys_get_temp_dir(), 'diezmos').'.xlsx';
    file_put_contents($notExcel, 'fecha,miembro,divisa,monto');

    expect(tithePreview($notExcel)['rejected'][0]['reason'])->toContain('no es un libro de Excel')
        ->and(tithePreview(offeringWorkbook([['01/11/2026', 'Ana', 'VES', '1']], ['fecha', 'miembro', 'moneda', 'monto']))['rejected'])->not->toBe([]);
});

test('importing writes every row or nothing, and the log keeps the totals and the file hash', function () {
    $path = titheWorkbook([
        ['01/11/2026', 'Ana Pérez', 'VES', '1.000,00'],
        ['08/11/2026', 'María Gonzalez', 'USD', '5'],
    ]);

    expect(app(ImportTithes::class)($path, false))->toBe(2)
        ->and(Tithe::query()->count())->toBe(2);

    $log = ActivityLogEntry::query()->where('action', 'tithes.imported')->sole();

    expect($log->changes['totals'])->toEqual(['ves' => '1000.00', 'usd' => '5.00', 'cop' => '0.00'])
        ->and($log->changes['file_sha256'])->toBe(hash_file('sha256', $path));

    $bad = titheWorkbook([
        ['01/11/2026', 'Ana Pérez', 'VES', '10'],
        ['02/11/2026', 'Nadie Nunca', 'VES', '10'],
    ]);

    expect(fn () => app(ImportTithes::class)($bad, false))->toThrow(ValidationException::class, 'No se importó nada')
        ->and(Tithe::query()->count())->toBe(2);
});

test('repeated rows need to be confirmed', function () {
    $path = titheWorkbook([['01/11/2026', 'Ana Pérez', 'VES', '10'], ['01/11/2026', 'Ana Pérez', 'VES', '10']]);

    expect(fn () => app(ImportTithes::class)($path, false))->toThrow(ValidationException::class, 'confirme')
        ->and(Tithe::query()->count())->toBe(0)
        ->and(app(ImportTithes::class)($path, true))->toBe(2);
});

test('an empty workbook imports nothing', function () {
    expect(fn () => app(ImportTithes::class)(titheWorkbook([]), false))->toThrow(ValidationException::class, 'no tiene diezmos');
});

test('the pastor downloads the template, previews and imports through the screens', function () {
    signInAs(UserRole::Pastor);
    $upload = fn () => new UploadedFile(titheWorkbook([['01/11/2026', 'Ana Pérez', 'COP', '2.500,00']]), 'diezmos.xlsx', null, null, true);

    $this->get(route('treasury.tithes.import.template'))->assertOk()->assertDownload('plantilla-diezmos.xlsx');

    $this->post(route('treasury.tithes.import.preview'), ['file' => $upload()])
        ->assertOk()
        ->assertJsonPath('can_import', true)
        ->assertJsonPath('totals.cop', '2500.00');

    $this->post(route('treasury.tithes.import.store'), ['file' => $upload()])->assertSessionHasNoErrors();

    expect(Tithe::query()->sole()->amount_cop)->toBe('2500.00');

    $this->post(route('treasury.tithes.import.preview'), ['file' => UploadedFile::fake()->create('virus.exe', 10)])->assertSessionHasErrors('file');
});

test('only the pastor imports tithes', function (UserRole $role) {
    signInAs($role);
    $file = new UploadedFile(titheWorkbook([['01/11/2026', 'Ana Pérez', 'COP', '10']]), 'diezmos.xlsx', null, null, true);

    $this->get(route('treasury.tithes.import.template'))->assertForbidden();
    $this->post(route('treasury.tithes.import.preview'), ['file' => $file])->assertForbidden();
    $this->post(route('treasury.tithes.import.store'), ['file' => $file])->assertForbidden();
})->with([UserRole::Treasurer, UserRole::Comptroller, UserRole::Secretary]);

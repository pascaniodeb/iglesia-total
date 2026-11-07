<?php

use App\Enums\UserRole;
use App\Modules\Integrity\Infrastructure\Models\ActivityLogEntry;
use App\Modules\Members\Infrastructure\Models\Member;
use App\Modules\Shared\Spreadsheets\SpreadsheetReader;
use App\Modules\Treasury\Application\Import\ImportOfferings;
use App\Modules\Treasury\Application\Import\PreviewOfferingImport;
use App\Modules\Treasury\Infrastructure\Models\Offering;
use App\Modules\Treasury\Infrastructure\Models\OfferingCategory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Carbon::setTestNow('2026-11-06 14:00:00');
    $this->category = OfferingCategory::factory()->create(['name' => 'Ofrenda general']);
    $this->ana = Member::factory()->create(['full_name' => 'Ana Pérez']);
});

afterEach(function () {
    Carbon::setTestNow();
});

function previewOf(string $path): array
{
    return app(PreviewOfferingImport::class)($path)->toArray();
}

test('a valid workbook previews every row with its giver, category and totals', function () {
    $preview = previewOf(offeringWorkbook([
        ['01/11/2026', 'ana perez', 'OFRENDA GENERAL', 'Culto', '', '10', '141.000,50'],
        [new DateTimeImmutable('2026-11-02'), '', 'Ofrenda general', '', 500.25, 0, 0],
    ]));

    expect($preview['can_import'])->toBeTrue()
        ->and($preview['rejected'])->toBe([])
        ->and($preview['rows'][0])->toMatchArray([
            'line' => 2,
            'offering_date' => '2026-11-01',
            'member_id' => $this->ana->id,
            'offering_category_id' => $this->category->id,
            'amount_usd' => '10.00',
            'amount_cop' => '141000.50',
        ])
        ->and($preview['rows'][1])->toMatchArray(['offering_date' => '2026-11-02', 'member_id' => null, 'amount_ves' => '500.25'])
        ->and($preview['totals'])->toBe(['ves' => '500.25', 'usd' => '10.00', 'cop' => '141000.50']);
});

test('every bad row is rejected with its line and reason', function (array $row, string $reason) {
    Member::factory()->create(['full_name' => 'Luis Gómez']);
    Member::factory()->create(['full_name' => 'Luis Gomez']);
    OfferingCategory::factory()->inactive()->create(['name' => 'Vieja']);

    $preview = previewOf(offeringWorkbook([$row]));

    expect($preview['can_import'])->toBeFalse()
        ->and($preview['rejected'][0]['line'])->toBe(2)
        ->and($preview['rejected'][0]['reason'])->toContain($reason);
})->with([
    'bad date' => [['31/02/2026', '', 'Ofrenda general', '', '', '10', ''], 'no es válida'],
    'future date' => [['07/11/2026', '', 'Ofrenda general', '', '', '10', ''], 'futura'],
    'unknown member' => [['01/11/2026', 'Nadie Nunca', 'Ofrenda general', '', '', '10', ''], 'no está en el padrón'],
    'ambiguous member' => [['01/11/2026', 'luis gomez', 'Ofrenda general', '', '', '10', ''], 'ambiguo'],
    'inactive category' => [['01/11/2026', '', 'Vieja', '', '', '10', ''], 'no es una categoría activa'],
    'no amount' => [['01/11/2026', '', 'Ofrenda general', '', '', '', ''], 'ningún monto'],
    'three decimals in a number cell' => [['01/11/2026', '', 'Ofrenda general', '', 10.005, '', ''], 'no es válido'],
    'thousands typed wrong' => [['01/11/2026', '', 'Ofrenda general', '', '1.00.0', '', ''], 'no es válido'],
]);

test('a workbook that is not the template is refused at its header', function () {
    $preview = previewOf(offeringWorkbook([['01/11/2026']], ['fecha', 'monto']));

    expect($preview['can_import'])->toBeFalse()
        ->and($preview['rejected'][0]['line'])->toBe(1);
});

test('one bad row means nothing is imported', function () {
    $path = offeringWorkbook([
        ['01/11/2026', '', 'Ofrenda general', '', '', '10', ''],
        ['01/11/2026', 'Nadie Nunca', 'Ofrenda general', '', '', '10', ''],
    ]);

    expect(fn () => app(ImportOfferings::class)($path, false))->toThrow(ValidationException::class);
    expect(Offering::query()->count())->toBe(0);
});

test('exact duplicates must be confirmed before importing', function () {
    signInAs(UserRole::Treasurer);
    $path = offeringWorkbook([
        ['01/11/2026', '', 'Ofrenda general', '', '', '10', ''],
        ['01/11/2026', '', 'Ofrenda general', '', '', '10', ''],
    ]);

    expect(previewOf($path)['duplicate_lines'])->toBe([3])
        ->and(fn () => app(ImportOfferings::class)($path, false))->toThrow(ValidationException::class);

    expect(app(ImportOfferings::class)($path, true))->toBe(2)
        ->and(Offering::query()->count())->toBe(2);
});

test('importing writes every row at once and records the file fingerprint', function () {
    $user = signInAs(UserRole::Treasurer);
    $path = offeringWorkbook([
        ['01/11/2026', 'Ana Pérez', 'Ofrenda general', 'Culto', '', '10', '141000,50'],
        ['02/11/2026', '', 'Ofrenda general', '', '100', '', ''],
    ]);

    app(ImportOfferings::class)($path, false);

    $entry = ActivityLogEntry::query()->where('action', 'offerings.imported')->sole();

    expect(Offering::query()->pluck('created_by')->unique()->all())->toBe([$user->id])
        ->and($entry->changes['count'])->toBe(2)
        ->and($entry->changes['totals'])->toEqual(['ves' => '100.00', 'usd' => '10.00', 'cop' => '141000.50'])
        ->and($entry->changes['file_sha256'])->toBe(hash_file('sha256', $path));
});

test('the screens: template, preview and import, only for who manages offerings', function () {
    $path = offeringWorkbook([['01/11/2026', '', 'Ofrenda general', '', '', '10', '']]);
    $upload = fn () => new UploadedFile($path, 'ofrendas.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

    signInAs(UserRole::Comptroller);
    $this->get(route('treasury.offerings.import.template'))->assertForbidden();
    $this->post(route('treasury.offerings.import.store'), ['file' => $upload()])->assertForbidden();

    signInAs(UserRole::Treasurer);
    $this->postJson(route('treasury.offerings.import.preview'), ['file' => $upload()])
        ->assertOk()
        ->assertJsonPath('can_import', true)
        ->assertJsonPath('totals.usd', '10.00');

    $this->post(route('treasury.offerings.import.store'), ['file' => $upload()])->assertSessionHasNoErrors();
    expect(Offering::query()->count())->toBe(1);

    $this->postJson(route('treasury.offerings.import.preview'), ['file' => UploadedFile::fake()->create('ofrendas.csv', 1, 'text/csv')])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('file');
});

test('the template is an Excel workbook with the import header', function () {
    signInAs(UserRole::Treasurer);

    $response = $this->get(route('treasury.offerings.import.template'));
    $response->assertOk()->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

    $path = tempnam(sys_get_temp_dir(), 'plantilla').'.xlsx';
    file_put_contents($path, $response->streamedContent());

    expect(SpreadsheetReader::rows($path, PreviewOfferingImport::HEADERS, 10))->toBe([]);
});

test('a date stored as a bare Excel serial number is refused, never guessed', function () {
    $preview = previewOf(offeringWorkbook([[46328, '', 'Ofrenda general', '', '', '10', '']]));

    expect($preview['can_import'])->toBeFalse()
        ->and($preview['rejected'][0]['reason'])->toContain('no es válida');
});

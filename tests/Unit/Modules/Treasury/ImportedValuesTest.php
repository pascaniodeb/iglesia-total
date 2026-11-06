<?php

use App\Modules\Shared\Spreadsheets\SpreadsheetReader;
use App\Modules\Treasury\Domain\Import\ImportedValues;

test('reads amounts written in Venezuelan Spanish, strictly', function (string $raw, ?string $expected) {
    expect(ImportedValues::amount($raw))->toBe($expected);
})->with([
    ['20000', '20000.00'],
    ['20000,50', '20000.50'],
    ['20.000', '20000.00'],
    ['20.000,50', '20000.50'],
    ['12.345.678,90', '12345678.90'],
    ['20.5', '20.50'],
    ['', '0.00'],
    ['007,5', '7.50'],
    ['1.00.0', null],
    ['-5', null],
    ['1,005', null],
    ['1 000', null],
    ['abc', null],
]);

test('reads amounts from numeric cells with a dot and never as thousands', function (string $raw, ?string $expected) {
    expect(ImportedValues::amountFromNumber($raw))->toBe($expected);
})->with([
    ['141000', '141000.00'],
    ['10.05', '10.05'],
    ['10.5', '10.50'],
    ['10.005', null],
    ['-1', null],
]);

test('turns Excel numbers back into their decimal text, keeping a real third decimal visible', function () {
    expect(SpreadsheetReader::text(10.05))->toBe('10.05')
        ->and(SpreadsheetReader::text(0.1 + 0.2))->toBe('0.3')
        ->and(SpreadsheetReader::text(10.005))->toBe('10.005')
        ->and(SpreadsheetReader::text(141000.0))->toBe('141000')
        ->and(SpreadsheetReader::text(1.0E+15))->toBe('1000000000000000');
});

test('reads DD/MM/YYYY dates and refuses impossible ones', function (string $raw, ?string $expected) {
    expect(ImportedValues::date($raw))->toBe($expected);
})->with([
    ['1/11/2026', '2026-11-01'],
    ['01/11/2026', '2026-11-01'],
    ['31/02/2026', null],
    ['2026-11-01', null],
    ['11/2026', null],
]);

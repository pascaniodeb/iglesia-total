<?php

use App\Modules\Shared\Pdf\Format;

test('amounts are written with a dot for thousands and a comma for decimals', function (string $value, string $text) {
    expect(Format::amount($value))->toBe($text);
})->with([
    ['0', '0,00'],
    ['5', '5,00'],
    ['141000.5', '141.000,50'],
    ['1234567.89', '1.234.567,89'],
    ['-4391400.00', '-4.391.400,00'],
]);

test('a table cell is a dash for nothing and the number for the rest, negatives included', function (string $value, string $text) {
    expect(Format::cell($value))->toBe($text);
})->with([
    ['0', '-'],
    ['0.00', '-'],
    ['-0.00', '-'],
    ['10.00', '10,00'],
    ['-51300.00', '-51.300,00'],
]);

test('dates are written day, month, year', function () {
    expect(Format::date('2026-11-25'))->toBe('25/11/2026');
});

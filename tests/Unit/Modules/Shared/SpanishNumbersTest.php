<?php

use App\Modules\Shared\Text\SpanishNumbers;

test('whole numbers are written the way Spanish does', function (int $number, string $words) {
    expect(SpanishNumbers::words($number))->toBe($words);
})->with([
    [0, 'cero'],
    [1, 'un'],
    [15, 'quince'],
    [16, 'dieciséis'],
    [21, 'veintiún'],
    [22, 'veintidós'],
    [31, 'treinta y uno'],
    [100, 'cien'],
    [101, 'ciento uno'],
    [500, 'quinientos'],
    [700, 'setecientos'],
    [900, 'novecientos'],
    [1000, 'mil'],
    [1001, 'mil uno'],
    [21000, 'veintiún mil'],
    [31000, 'treinta y un mil'],
    [100000, 'cien mil'],
    [141000, 'ciento cuarenta y un mil'],
    [1000000, 'un millón'],
    [2000000, 'dos millones'],
    [101000000, 'ciento un millones'],
    [1234567890, 'mil doscientos treinta y cuatro millones quinientos sesenta y siete mil ochocientos noventa'],
    [1000000000000, 'un billón'],
]);

test('a negative number cannot be written', function () {
    expect(fn () => SpanishNumbers::words(-1))->toThrow(InvalidArgumentException::class);
});

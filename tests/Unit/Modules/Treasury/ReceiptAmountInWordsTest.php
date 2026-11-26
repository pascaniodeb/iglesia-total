<?php

use App\Modules\Treasury\Application\Receipts\ReceiptAmountInWords;

test('each currency with an amount is one sentence in capitals', function (array $amounts, string $words) {
    expect(ReceiptAmountInWords::of($amounts))->toBe($words);
})->with([
    'pesos' => [['ves' => '0.00', 'usd' => '0.00', 'cop' => '141000.00'], 'CIENTO CUARENTA Y UN MIL PESOS COLOMBIANOS CON 00/100'],
    'one dollar is singular' => [['ves' => '0.00', 'usd' => '1.00', 'cop' => '0.00'], 'UN DÓLAR CON 00/100'],
    'cents' => [['ves' => '0.00', 'usd' => '25.50', 'cop' => '0.00'], 'VEINTICINCO DÓLARES CON 50/100'],
    'apocope before the currency' => [['ves' => '0.00', 'usd' => '101.00', 'cop' => '0.00'], 'CIENTO UN DÓLARES CON 00/100'],
    'several currencies' => [['ves' => '500.50', 'usd' => '25.00', 'cop' => '0.00'], 'QUINIENTOS BOLÍVARES CON 50/100; VEINTICINCO DÓLARES CON 00/100'],
    'only cents' => [['ves' => '0.00', 'usd' => '0.00', 'cop' => '0.50'], 'CERO PESOS COLOMBIANOS CON 50/100'],
    'nothing' => [['ves' => '0.00', 'usd' => '0.00', 'cop' => '0.00'], ''],
]);

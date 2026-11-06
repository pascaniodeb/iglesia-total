<?php

use App\Modules\Shared\Money\Currency;
use App\Modules\Shared\Money\Money;

test('adds many two-decimal amounts exactly, where floats would drift', function () {
    $amounts = array_fill(0, 1000, Money::of('0.10', Currency::COP));

    expect(Money::sum(Currency::COP, ...$amounts)->amount())->toBe('100.00')
        ->and(array_sum(array_fill(0, 1000, 0.10)) === 100.0)->toBeFalse();
});

test('keeps two decimals and supports negative balances', function () {
    $balance = Money::of('591700', Currency::COP)
        ->minus(Money::of('643000.00', Currency::COP));

    expect($balance->amount())->toBe('-51300.00')
        ->and($balance->isNegative())->toBeTrue()
        ->and($balance->negated()->amount())->toBe('51300.00');
});

test('never rounds silently and rejects anything that is not a plain decimal', function (string $amount) {
    expect(fn () => Money::of($amount, Currency::USD))->toThrow(InvalidArgumentException::class);
})->with(['1.005', '1,50', '1e3', '', ' 10', 'abc', '1.000.000']);

test('refuses to mix currencies', function () {
    expect(fn () => Money::of('1.00', Currency::USD)->plus(Money::of('1.00', Currency::COP)))
        ->toThrow(InvalidArgumentException::class);
});

test('compares by amount and currency', function () {
    $ten = Money::of('10', Currency::VES);

    expect($ten->equals(Money::of('10.00', Currency::VES)))->toBeTrue()
        ->and($ten->equals(Money::of('10.00', Currency::USD)))->toBeFalse()
        ->and($ten->isGreaterThan(Money::of('9.99', Currency::VES)))->toBeTrue()
        ->and(Money::zero(Currency::USD)->isZero())->toBeTrue();
});

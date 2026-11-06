<?php

use App\Modules\Shared\Money\Currency;
use App\Modules\Shared\Money\Money;
use App\Modules\Treasury\Domain\Rates\DailyRates;
use App\Modules\Treasury\Domain\Rates\Rate;

test('pesos per bolívar is the TRM divided by the BCV, six decimals, half up', function () {
    $rates = DailyRates::fromMarket(
        new DateTimeImmutable('2026-11-06'),
        Rate::of('236.4567', DailyRates::USD_SCALE),
        Rate::of('275.1200', DailyRates::USD_SCALE),
        Rate::of('3870.5500', DailyRates::USD_SCALE),
    );

    // 3870.55 / 236.4567 = 16.3689594... (checked with exact decimals)
    expect($rates->copRate->value())->toBe('16.368959');
});

test('the bolívar equivalent multiplies dollars and divides pesos, two decimals', function () {
    $rates = new DailyRates(
        new DateTimeImmutable('2026-11-06'),
        Rate::of('236.4567', DailyRates::USD_SCALE),
        Rate::of('16.369156', DailyRates::COP_SCALE),
    );

    expect($rates->toBolivares(Money::of('10.00', Currency::USD))->amount())->toBe('2364.57')
        ->and($rates->toBolivares(Money::of('141000.00', Currency::COP))->amount())->toBe('8613.76')
        ->and($rates->toBolivares(Money::of('5.00', Currency::VES))->amount())->toBe('5.00');
});

test('a rate is positive and never rounded silently', function (string $value, int $scale) {
    expect(fn () => Rate::of($value, $scale))->toThrow(InvalidArgumentException::class);
})->with([
    'zero' => ['0', 4],
    'negative' => ['-1.5', 4],
    'too many decimals' => ['236.45671', 4],
    'comma' => ['236,45', 4],
]);

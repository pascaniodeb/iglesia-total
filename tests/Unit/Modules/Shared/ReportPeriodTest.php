<?php

use App\Modules\Shared\Time\Month;
use App\Modules\Shared\Time\ReportPeriod;

test('each kind of key spans its whole months', function (string $granularity, string $key, string $first, string $last, string $label) {
    $period = ReportPeriod::fromKey($granularity, $key);

    expect($period->firstDay()->format('Y-m-d'))->toBe($first)
        ->and($period->lastDay()->format('Y-m-d'))->toBe($last)
        ->and($period->key())->toBe($key)
        ->and($period->label())->toBe($label);
})->with([
    'month' => ['month', '2026-02', '2026-02-01', '2026-02-28', 'Febrero de 2026'],
    'quarter' => ['quarter', '2026-Q2', '2026-04-01', '2026-06-30', '2.º trimestre de 2026 (abril – junio)'],
    'semester' => ['semester', '2026-S2', '2026-07-01', '2026-12-31', '2.º semestre de 2026 (julio – diciembre)'],
    'year' => ['year', '2024', '2024-01-01', '2024-12-31', 'Año 2024'],
    'range in a year' => ['range', '2026-01..2026-05', '2026-01-01', '2026-05-31', 'Enero – mayo de 2026'],
    'range across years' => ['range', '2025-11..2026-02', '2025-11-01', '2026-02-28', 'Noviembre de 2025 – febrero de 2026'],
]);

test('the short label leaves the months out', function () {
    expect(ReportPeriod::fromKey('quarter', '2026-Q4')->shortLabel())->toBe('4.º trimestre de 2026')
        ->and(ReportPeriod::fromKey('semester', '2026-S1')->shortLabel())->toBe('1.º semestre de 2026')
        ->and(ReportPeriod::fromKey('month', '2026-03')->shortLabel())->toBe('Marzo de 2026');
});

test('a period lists its months in order', function () {
    $months = array_map(fn (Month $month) => $month->key(), ReportPeriod::fromKey('range', '2025-11..2026-02')->months());

    expect($months)->toBe(['2025-11', '2025-12', '2026-01', '2026-02']);
});

test('the period of a granularity that contains a month', function (string $granularity, string $key) {
    expect(ReportPeriod::containing($granularity, Month::fromKey('2026-08'))->key())->toBe($key);
})->with([
    ['month', '2026-08'],
    ['quarter', '2026-Q3'],
    ['semester', '2026-S2'],
    ['year', '2026'],
    ['range', '2026-08..2026-08'],
]);

test('malformed keys are refused', function (string $granularity, string $key) {
    expect(fn () => ReportPeriod::fromKey($granularity, $key))->toThrow(InvalidArgumentException::class);
})->with([
    ['month', '2026-13'],
    ['quarter', '2026-Q5'],
    ['semester', '2026-S3'],
    ['year', '26'],
    ['range', '2026-05..2026-01'],
    ['week', '2026-W01'],
]);

test('the Sundays of a period are what someone could tithe on, not the ISO weeks it touches', function (string $granularity, string $key, int $sundays) {
    expect(ReportPeriod::fromKey($granularity, $key)->sundays())->toBe($sundays);
})->with([
    // Touches six ISO weeks (the first brings 2 days, the last a lone Monday) but has five Sundays.
    'August 2026' => ['month', '2026-08', 5],
    'February 2026' => ['month', '2026-02', 4],
    'a month that starts on a Sunday' => ['month', '2026-11', 5],
    'a month that ends on a Sunday' => ['month', '2026-05', 5],
    'a quarter' => ['quarter', '2026-Q1', 13],
    'a leap year' => ['year', '2024', 52],
    'a year' => ['year', '2026', 52],
    'a range' => ['range', '2026-07..2026-09', 13],
]);

test('a query falls back to the month it is given', function () {
    $fallback = Month::fromKey('2026-11');

    expect(ReportPeriod::fromQuery('quarter', '2026-Q2', $fallback)->key())->toBe('2026-Q2')
        ->and(ReportPeriod::fromQuery('quarter', 'nonsense', $fallback)->key())->toBe('2026-11')
        ->and(ReportPeriod::fromQuery(null, null, $fallback)->key())->toBe('2026-11')
        ->and(ReportPeriod::fromQuery(['x'], 5, $fallback)->key())->toBe('2026-11');
});

test('the options list every period the months make up, newest first', function () {
    $options = ReportPeriod::options(Month::fromKey('2026-02'), Month::fromKey('2026-08'));

    expect(array_column($options['month'], 'key'))->toBe(['2026-08', '2026-07', '2026-06', '2026-05', '2026-04', '2026-03', '2026-02'])
        ->and(array_column($options['quarter'], 'key'))->toBe(['2026-Q3', '2026-Q2', '2026-Q1'])
        ->and(array_column($options['semester'], 'key'))->toBe(['2026-S2', '2026-S1'])
        ->and(array_column($options['year'], 'key'))->toBe(['2026']);
});

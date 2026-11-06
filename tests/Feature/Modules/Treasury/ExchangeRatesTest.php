<?php

use App\Enums\UserRole;
use App\Modules\Integrity\Infrastructure\Models\ActivityLogEntry;
use App\Modules\Treasury\Application\Actions\RecordExchangeRates;
use App\Modules\Treasury\Domain\Enums\RateOrigin;
use App\Modules\Treasury\Domain\Rates\DailyRates;
use App\Modules\Treasury\Domain\Rates\Rate;
use App\Modules\Treasury\Domain\Rates\RatesUnavailable;
use App\Modules\Treasury\Infrastructure\Models\ExchangeRate;
use App\Modules\Treasury\Infrastructure\Rates\OfficialMarketRateSource;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    // 10:00 in Caracas on Friday 6 November 2026.
    Carbon::setTestNow('2026-11-06 14:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * @param  array<string, mixed>  $overrides
 */
function fakeOfficialSources(array $overrides = []): void
{
    Http::fake([
        've.dolarapi.com/v1/dolares/oficial*' => Http::response(['promedio' => array_key_exists('usd', $overrides) ? $overrides['usd'] : 236.4567]),
        've.dolarapi.com/v1/euros/oficial*' => Http::response(['promedio' => array_key_exists('eur', $overrides) ? $overrides['eur'] : 275.12]),
        'www.datos.gov.co/*' => Http::response([['valor' => array_key_exists('trm', $overrides) ? $overrides['trm'] : '3870.55', 'vigenciadesde' => '2026-11-06T00:00:00.000']]),
    ]);
}

test('the official source reads BCV and TRM and derives pesos per bolívar', function () {
    fakeOfficialSources();

    $rates = app(OfficialMarketRateSource::class)->fetch(new DateTimeImmutable('2026-11-06'));

    expect($rates->usdRate->value())->toBe('236.4567')
        ->and($rates->eurRate?->value())->toBe('275.1200')
        ->and($rates->trmCopUsd?->value())->toBe('3870.5500')
        ->and($rates->copRate->value())->toBe('16.368959');

    Http::assertSent(fn ($request) => str_contains($request->url(), 'datos.gov.co') && str_contains(urldecode($request->url()), "vigenciadesde <= '2026-11-06T23:59:59.000'"));
});

test('a source that fails or answers out of range updates nothing', function (Closure $fake) {
    $fake();

    expect(fn () => app(RecordExchangeRates::class)->sync())->toThrow(RatesUnavailable::class);
    expect(ExchangeRate::query()->count())->toBe(0);
})->with([
    'BCV down' => [fn () => Http::fake(['*' => Http::response([], 503)])],
    'absurd BCV' => [fn () => fakeOfficialSources(['usd' => 0.5])],
    'absurd TRM' => [fn () => fakeOfficialSources(['trm' => '99'])],
    'no value' => [fn () => fakeOfficialSources(['usd' => null])],
]);

test('syncing stores the day, records it, and does nothing when it is already up to date', function () {
    fakeOfficialSources();

    expect(app(RecordExchangeRates::class)->sync())->toBe(RecordExchangeRates::SYNCED)
        ->and(app(RecordExchangeRates::class)->sync())->toBe(RecordExchangeRates::UNCHANGED);

    $rate = ExchangeRate::query()->sole();

    expect($rate->rate_date->toDateString())->toBe('2026-11-06')
        ->and($rate->origin)->toBe(RateOrigin::Market)
        ->and($rate->usd_rate)->toBe('236.4567')
        ->and($rate->cop_rate)->toBe('16.368959')
        ->and(ActivityLogEntry::query()->where('action', 'exchange_rate.synced')->count())->toBe(1);
});

test('a manual correction prevails and the sync never overwrites it', function () {
    signInAs(UserRole::Pastor);
    fakeOfficialSources();
    app(RecordExchangeRates::class)->sync();

    app(RecordExchangeRates::class)->record(
        new DateTimeImmutable('2026-11-06'),
        Rate::of('240', DailyRates::USD_SCALE),
        Rate::of('16.1', DailyRates::COP_SCALE),
    );

    expect(app(RecordExchangeRates::class)->sync())->toBe(RecordExchangeRates::KEPT_MANUAL);

    $rate = ExchangeRate::query()->sole();
    $correction = ActivityLogEntry::query()->where('action', 'exchange_rate.corrected')->sole();

    expect($rate->origin)->toBe(RateOrigin::Manual)
        ->and($rate->usd_rate)->toBe('240.0000')
        ->and($correction->changes['usd_rate'])->toEqual(['from' => '236.4567', 'to' => '240.0000']);
});

test('rates are not recorded for a day that has not come in Venezuela', function () {
    expect(fn () => app(RecordExchangeRates::class)->record(
        new DateTimeImmutable('2026-11-07'),
        Rate::of('240', DailyRates::USD_SCALE),
        Rate::of('16', DailyRates::COP_SCALE),
    ))->toThrow(ValidationException::class);
});

test('today follows the Venezuelan clock, not UTC', function () {
    Carbon::setTestNow('2026-11-07 02:30:00');

    expect(RecordExchangeRates::today()->format('Y-m-d'))->toBe('2026-11-06');
});

test('rates are never deleted', function () {
    fakeOfficialSources();
    app(RecordExchangeRates::class)->sync();

    expect(fn () => DB::statement('DELETE FROM exchange_rates'))->toThrow(QueryException::class, 'append-only');
});

test('the sync command reports a source failure as a failed run', function () {
    Http::fake(['*' => Http::response([], 500)]);

    $this->artisan('treasury:sync-rates')->assertFailed();
    expect(ExchangeRate::query()->count())->toBe(0);
});

test('the sync command stores the rates when the sources answer', function () {
    fakeOfficialSources();

    $this->artisan('treasury:sync-rates')->assertSuccessful();
    expect(ExchangeRate::query()->count())->toBe(1);
});

test('the screen shows the rates to who reads catalogs and lets only the pastor correct them', function () {
    fakeOfficialSources();
    app(RecordExchangeRates::class)->sync();

    signInAs(UserRole::Treasurer);
    $this->get(route('treasury.exchange-rates.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('treasury/exchange-rates/index')
            ->where('latest.usd_rate', '236.4567')
            ->where('today', '2026-11-06')
            ->where('can_manage', false));
    $this->post(route('treasury.exchange-rates.store'), ['rate_date' => '2026-11-06', 'usd_rate' => '1', 'cop_rate' => '1'])->assertForbidden();

    signInAs(UserRole::Secretary);
    $this->get(route('treasury.exchange-rates.index'))->assertForbidden();
});

test('the manual form accepts a decimal comma and validates every field', function () {
    signInAs(UserRole::Pastor);

    $this->post(route('treasury.exchange-rates.store'), ['rate_date' => '2026-11-05', 'usd_rate' => '235,5', 'cop_rate' => '16,25'])
        ->assertSessionHasNoErrors();

    expect(ExchangeRate::query()->sole())
        ->usd_rate->toBe('235.5000')
        ->cop_rate->toBe('16.250000');

    $this->post(route('treasury.exchange-rates.store'), ['rate_date' => 'ayer', 'usd_rate' => '0', 'cop_rate' => '1.1234567'])
        ->assertSessionHasErrors(['rate_date', 'usd_rate', 'cop_rate']);
});

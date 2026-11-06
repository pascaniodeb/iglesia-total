<?php

namespace App\Modules\Treasury\Application\Actions;

use App\Modules\Integrity\Application\ActivityLog;
use App\Modules\Integrity\Domain\ChangeSet;
use App\Modules\Treasury\Domain\Enums\RateOrigin;
use App\Modules\Treasury\Domain\Rates\DailyRates;
use App\Modules\Treasury\Domain\Rates\MarketRateSource;
use App\Modules\Treasury\Domain\Rates\Rate;
use App\Modules\Treasury\Infrastructure\Models\ExchangeRate;
use DateTimeImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The exchange rates of each day (ported from safipuv-v4). The sync brings the official ones
 * (BCV and TRM) and updates the day as long as it has no manual correction, which always
 * prevails. Every creation or change is recorded in the activity log with its previous value;
 * nothing is deleted.
 *
 * It does not authorize: the screen checks catalogs.manage and the scheduler runs as the system.
 */
final readonly class RecordExchangeRates
{
    public const string SYNCED = 'synced';

    public const string UNCHANGED = 'unchanged';

    public const string KEPT_MANUAL = 'kept_manual';

    private const array LOGGED = ['usd_rate', 'cop_rate', 'eur_rate', 'trm_cop_usd', 'origin'];

    public function __construct(private ActivityLog $activityLog, private MarketRateSource $source) {}

    /** Today in Venezuela (the application runs in UTC). */
    public static function today(): DateTimeImmutable
    {
        return new DateTimeImmutable(now((string) config('treasury.rates.timezone'))->toDateString());
    }

    /**
     * @return self::SYNCED|self::UNCHANGED|self::KEPT_MANUAL
     */
    public function sync(?DateTimeImmutable $date = null): string
    {
        // The sources are queried outside the transaction: no lock is held while waiting on the network.
        $rates = $this->source->fetch($date ?? self::today());

        return DB::transaction(function () use ($rates): string {
            $existing = $this->lockDay($rates->date);

            if ($existing?->origin === RateOrigin::Manual) {
                return self::KEPT_MANUAL;
            }

            return $this->write($existing ?? self::newDay($rates->date), [
                'usd_rate' => $rates->usdRate->value(),
                'cop_rate' => $rates->copRate->value(),
                'eur_rate' => $rates->eurRate?->value(),
                'trm_cop_usd' => $rates->trmCopUsd?->value(),
                'origin' => RateOrigin::Market,
            ], 'exchange_rate.synced') ? self::SYNCED : self::UNCHANGED;
        });
    }

    /**
     * Records or corrects by hand the rates of a day (today or earlier). From then on the sync
     * does not change them.
     *
     * @throws ValidationException
     */
    public function record(DateTimeImmutable $date, Rate $usdRate, Rate $copRate): ExchangeRate
    {
        if ($date > self::today()) {
            throw ValidationException::withMessages(['rate_date' => __('Rates are not recorded for a day that has not come yet.')]);
        }

        return DB::transaction(function () use ($date, $usdRate, $copRate): ExchangeRate {
            $rate = $this->lockDay($date) ?? self::newDay($date);

            $this->write($rate, [
                'usd_rate' => (string) $usdRate->value->toScale(DailyRates::USD_SCALE),
                'cop_rate' => (string) $copRate->value->toScale(DailyRates::COP_SCALE),
                'origin' => RateOrigin::Manual,
            ], $rate->exists ? 'exchange_rate.corrected' : 'exchange_rate.recorded');

            return $rate;
        });
    }

    private function lockDay(DateTimeImmutable $date): ?ExchangeRate
    {
        return ExchangeRate::query()->whereDate('rate_date', $date->format('Y-m-d'))->lockForUpdate()->first();
    }

    private static function newDay(DateTimeImmutable $date): ExchangeRate
    {
        return (new ExchangeRate)->forceFill(['rate_date' => $date->format('Y-m-d')]);
    }

    /**
     * Writes the attributes and records them with the previous value of each changed field.
     *
     * @param  array<string, mixed>  $attributes
     * @return bool whether anything changed
     */
    private function write(ExchangeRate $rate, array $attributes, string $action): bool
    {
        $isNew = ! $rate->exists;
        $before = $isNew ? [] : Arr::only($rate->getAttributes(), self::LOGGED);
        $rate->forceFill($attributes);

        if (! $isNew && ! $rate->isDirty()) {
            return false;
        }

        $rate->save();

        $this->activityLog->record(
            $action,
            match ($action) {
                'exchange_rate.synced' => __('Updated the rates of :date from the BCV and the TRM.', ['date' => $rate->rate_date->format('d/m/Y')]),
                'exchange_rate.corrected' => __('Corrected by hand the rates of :date.', ['date' => $rate->rate_date->format('d/m/Y')]),
                default => __('Recorded by hand the rates of :date.', ['date' => $rate->rate_date->format('d/m/Y')]),
            },
            $rate,
            ChangeSet::between($before, Arr::only($rate->getAttributes(), self::LOGGED)),
        );

        return true;
    }
}

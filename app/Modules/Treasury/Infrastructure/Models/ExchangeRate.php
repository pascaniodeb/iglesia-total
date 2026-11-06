<?php

namespace App\Modules\Treasury\Infrastructure\Models;

use App\Modules\Treasury\Domain\Enums\RateOrigin;
use App\Modules\Treasury\Domain\Rates\DailyRates;
use App\Modules\Treasury\Domain\Rates\Rate;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The exchange rates of one Venezuelan day. Never deleted; a manual correction prevails over
 * the sync.
 *
 * @property string $id
 * @property Carbon $rate_date
 * @property string $usd_rate bolívares per dollar (4 decimals)
 * @property string $cop_rate pesos per bolívar (6 decimals)
 * @property string|null $eur_rate bolívares per euro (4 decimals)
 * @property string|null $trm_cop_usd pesos per dollar (4 decimals)
 * @property RateOrigin $origin
 * @property string|null $legacy_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ExchangeRate extends Model
{
    use HasUuids;

    /**
     * The rate in force on a day: that day's or, when there is none (weekend, holiday), the
     * last one before it.
     */
    public static function latestOn(DateTimeImmutable $day): ?self
    {
        return self::query()->whereDate('rate_date', '<=', $day->format('Y-m-d'))->orderByDesc('rate_date')->first();
    }

    public function rates(): DailyRates
    {
        return new DailyRates(
            new DateTimeImmutable($this->rate_date->toDateString()),
            Rate::of($this->usd_rate, DailyRates::USD_SCALE),
            Rate::of($this->cop_rate, DailyRates::COP_SCALE),
            $this->eur_rate === null ? null : Rate::of($this->eur_rate, DailyRates::USD_SCALE),
            $this->trm_cop_usd === null ? null : Rate::of($this->trm_cop_usd, DailyRates::USD_SCALE),
        );
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rate_date' => 'date',
            'usd_rate' => 'decimal:4',
            'cop_rate' => 'decimal:6',
            'eur_rate' => 'decimal:4',
            'trm_cop_usd' => 'decimal:4',
            'origin' => RateOrigin::class,
        ];
    }
}

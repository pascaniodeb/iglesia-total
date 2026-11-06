<?php

namespace App\Modules\Treasury\Infrastructure\Rates;

use App\Modules\Treasury\Domain\Rates\DailyRates;
use App\Modules\Treasury\Domain\Rates\MarketRateSource;
use App\Modules\Treasury\Domain\Rates\Rate;
use App\Modules\Treasury\Domain\Rates\RatesUnavailable;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use DateTimeImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Official rates from public sources (ported from safipuv-v4): BCV through DolarAPI (bolívares
 * per dollar and per euro) and Colombia's TRM through datos.gov.co (the one in force on that
 * date). Each value is read as decimal text and checked against a sane range; if any source
 * fails, the day is not updated.
 */
final class OfficialMarketRateSource implements MarketRateSource
{
    public function fetch(DateTimeImmutable $date): DailyRates
    {
        /** @var array{timeout: int, bcv_usd_url: string, bcv_eur_url: string, trm_url: string, ranges: array{bcv: array{0: int, 1: int}, trm: array{0: int, 1: int}}} $config */
        $config = config('treasury.rates');

        $usd = $this->rate($this->get($config['bcv_usd_url'], [], $config['timeout'])['promedio'] ?? null, 'BCV (dollar)', $config['ranges']['bcv']);
        $eur = $this->rate($this->get($config['bcv_eur_url'], [], $config['timeout'])['promedio'] ?? null, 'BCV (euro)', $config['ranges']['bcv']);

        $trm = $this->get($config['trm_url'], [
            '$where' => "vigenciadesde <= '{$date->format('Y-m-d')}T23:59:59.000'",
            '$order' => 'vigenciadesde DESC',
            '$limit' => 1,
        ], $config['timeout']);
        $trm = $this->rate($trm[0]['valor'] ?? null, 'Colombia TRM', $config['ranges']['trm']);

        return DailyRates::fromMarket($date, $usd, $eur, $trm);
    }

    /**
     * @param  array<string, string|int>  $query
     * @return array<array-key, mixed>
     */
    private function get(string $url, array $query, int $timeout): array
    {
        try {
            $response = Http::timeout($timeout)->acceptJson()->get($url, $query);
        } catch (ConnectionException $e) {
            throw new RatesUnavailable("{$url} did not answer: {$e->getMessage()}", previous: $e);
        }

        if (! $response->successful() || ! is_array($response->json())) {
            throw new RatesUnavailable("{$url} answered {$response->status()}.");
        }

        return $response->json();
    }

    /**
     * @param  array{0: int, 1: int}  $range
     */
    private function rate(mixed $value, string $source, array $range): Rate
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            throw new RatesUnavailable("{$source}: the answer does not carry the rate.");
        }

        try {
            $decimal = BigDecimal::of((string) $value)->toScale(DailyRates::USD_SCALE, RoundingMode::HalfUp);
        } catch (MathException) {
            throw new RatesUnavailable("{$source}: the rate \"{$value}\" is not a number.");
        }

        if ($decimal->isLessThan($range[0]) || $decimal->isGreaterThan($range[1])) {
            throw new RatesUnavailable("{$source}: the rate {$decimal} is outside the expected range ({$range[0]} to {$range[1]}).");
        }

        return Rate::of((string) $decimal, DailyRates::USD_SCALE);
    }
}

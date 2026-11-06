<?php

namespace App\Modules\Treasury;

use App\Modules\Treasury\Domain\Rates\MarketRateSource;
use App\Modules\Treasury\Infrastructure\Rates\OfficialMarketRateSource;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the Treasury module: the official exchange-rate source.
 */
class TreasuryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(MarketRateSource::class, OfficialMarketRateSource::class);
    }
}

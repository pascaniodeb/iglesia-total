<?php

namespace App\Modules\Treasury;

use App\Modules\Integrity\Application\Ledger\SealableSources;
use App\Modules\Treasury\Domain\Rates\MarketRateSource;
use App\Modules\Treasury\Infrastructure\Rates\OfficialMarketRateSource;
use App\Modules\Treasury\Infrastructure\Sealing\WeeklySealableSource;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the Treasury module: the official exchange-rate source and the weekly closures it
 * seals in the sealed ledger.
 */
class TreasuryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(MarketRateSource::class, OfficialMarketRateSource::class);

        $this->callAfterResolving(SealableSources::class, function (SealableSources $sources): void {
            $sources->register(new WeeklySealableSource);
        });
    }
}

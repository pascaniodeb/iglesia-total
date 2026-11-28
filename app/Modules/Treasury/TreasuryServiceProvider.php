<?php

namespace App\Modules\Treasury;

use App\Modules\Accounting\Application\ClosedOfferingCash;
use App\Modules\Accounting\Application\OfferingClosures;
use App\Modules\Accounting\Application\ReceiptHashes;
use App\Modules\Integrity\Application\Audit\AuditChecks;
use App\Modules\Integrity\Application\Ledger\SealableSources;
use App\Modules\Treasury\Domain\Rates\MarketRateSource;
use App\Modules\Treasury\Infrastructure\Audit\ClosureTotalsAudit;
use App\Modules\Treasury\Infrastructure\Audit\MonthStraddlingWeeksAudit;
use App\Modules\Treasury\Infrastructure\Audit\WeeksWithoutClosureAudit;
use App\Modules\Treasury\Infrastructure\Rates\OfficialMarketRateSource;
use App\Modules\Treasury\Infrastructure\Sealing\WeeklySealableSource;
use App\Modules\Treasury\Infrastructure\TreasuryClosedOfferingCash;
use App\Modules\Treasury\Infrastructure\TreasuryOfferingClosures;
use App\Modules\Treasury\Infrastructure\TreasuryReceiptHashes;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the Treasury module: the official exchange-rate source, the weekly closures it seals in
 * the sealed ledger, and the ports Accounting reads it through (offering closures, closed-week
 * cash, receipt hashes).
 */
class TreasuryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(MarketRateSource::class, OfficialMarketRateSource::class);
        $this->app->bind(OfferingClosures::class, TreasuryOfferingClosures::class);
        $this->app->bind(ClosedOfferingCash::class, TreasuryClosedOfferingCash::class);
        $this->app->bind(ReceiptHashes::class, TreasuryReceiptHashes::class);

        $this->callAfterResolving(SealableSources::class, function (SealableSources $sources): void {
            $sources->register(new WeeklySealableSource);
        });

        $this->callAfterResolving(AuditChecks::class, function (AuditChecks $checks): void {
            $checks->register(new ClosureTotalsAudit('weekly'));
            $checks->register(new ClosureTotalsAudit('monthly'));
            $checks->register(new WeeksWithoutClosureAudit);
            $checks->register(new MonthStraddlingWeeksAudit);
        });
    }
}

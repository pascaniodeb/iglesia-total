<?php

namespace App\Modules\Accounting;

use App\Modules\Accounting\Application\ChartOfAccounts;
use App\Modules\Accounting\Application\Ledger;
use App\Modules\Accounting\Infrastructure\DatabaseChartOfAccounts;
use App\Modules\Accounting\Infrastructure\DatabaseLedger;
use App\Modules\Accounting\Infrastructure\Sealing\MonthlySealableSource;
use App\Modules\Integrity\Application\Ledger\SealableSources;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the Accounting module: the contracts other modules use (post to the journal, read the
 * chart of accounts).
 */
class AccountingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(Ledger::class, DatabaseLedger::class);
        $this->app->bind(ChartOfAccounts::class, DatabaseChartOfAccounts::class);

        // Closed accounting months are sealed as the monthly blocks of the sealed ledger.
        $this->callAfterResolving(SealableSources::class, function (SealableSources $sources): void {
            $sources->register($this->app->make(MonthlySealableSource::class));
        });
    }
}

<?php

namespace App\Modules\Accounting;

use App\Modules\Accounting\Application\ChartOfAccounts;
use App\Modules\Accounting\Application\Ledger;
use App\Modules\Accounting\Infrastructure\DatabaseChartOfAccounts;
use App\Modules\Accounting\Infrastructure\DatabaseLedger;
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
    }
}

<?php

namespace App\Modules\Accounting;

use App\Modules\Accounting\Application\Ledger;
use App\Modules\Accounting\Infrastructure\DatabaseLedger;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the Accounting module: the Ledger contract other modules post through.
 */
class AccountingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(Ledger::class, DatabaseLedger::class);
    }
}

<?php

namespace App\Modules\Accounting;

use App\Modules\Accounting\Application\CashMovements;
use App\Modules\Accounting\Application\ChartOfAccounts;
use App\Modules\Accounting\Application\Ledger;
use App\Modules\Accounting\Infrastructure\Audit\CarryForwardAudit;
use App\Modules\Accounting\Infrastructure\DatabaseChartOfAccounts;
use App\Modules\Accounting\Infrastructure\DatabaseLedger;
use App\Modules\Accounting\Infrastructure\GuardedCashMovements;
use App\Modules\Accounting\Infrastructure\JournalBalanceCheck;
use App\Modules\Accounting\Infrastructure\Models\JournalEntry;
use App\Modules\Accounting\Infrastructure\Models\JournalEntryLine;
use App\Modules\Accounting\Infrastructure\Sealing\MonthlySealableSource;
use App\Modules\Integrity\Application\Audit\AuditChecks;
use App\Modules\Integrity\Application\IntegrityChecks;
use App\Modules\Integrity\Application\Ledger\SealableSources;
use App\Modules\Integrity\Application\Seals\SealedModels;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the Accounting module: the contracts other modules use (post to the journal, move cash
 * under its rules, read the chart of accounts).
 */
class AccountingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(Ledger::class, DatabaseLedger::class);
        $this->app->bind(ChartOfAccounts::class, DatabaseChartOfAccounts::class);
        $this->app->bind(CashMovements::class, GuardedCashMovements::class);

        // Closed accounting months are sealed as the monthly blocks of the sealed ledger.
        $this->callAfterResolving(IntegrityChecks::class, function (IntegrityChecks $checks): void {
            $checks->register($this->app->make(JournalBalanceCheck::class));
        });

        $this->callAfterResolving(SealedModels::class, function (SealedModels $models): void {
            $models->register(JournalEntry::class, JournalEntryLine::class);
        });

        $this->callAfterResolving(AuditChecks::class, function (AuditChecks $checks): void {
            $checks->register(new CarryForwardAudit);
        });

        $this->callAfterResolving(SealableSources::class, function (SealableSources $sources): void {
            $sources->register($this->app->make(MonthlySealableSource::class));
        });
    }
}

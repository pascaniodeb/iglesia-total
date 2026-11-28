<?php

namespace App\Modules\Integrity;

use App\Modules\Integrity\Application\ActivityLog;
use App\Modules\Integrity\Application\Audit\AuditChecks;
use App\Modules\Integrity\Application\IntegrityChecks;
use App\Modules\Integrity\Application\Ledger\SealableSources;
use App\Modules\Integrity\Application\SealedPeriods;
use App\Modules\Integrity\Infrastructure\ActivityContext;
use App\Modules\Integrity\Infrastructure\Audit\SealedLedgerAudit;
use App\Modules\Integrity\Infrastructure\Audit\SealedLedgerFindings;
use App\Modules\Integrity\Infrastructure\DatabaseActivityLog;
use App\Modules\Integrity\Infrastructure\DatabaseSealedPeriods;
use App\Modules\Integrity\Infrastructure\Listeners\RecordSecurityEvents;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the Integrity module: the activity log contract other modules depend on, the
 * per-request (or per-command) context it records, the security events it listens to and the
 * registry of sources the sealed ledger rebuilds periods from.
 */
class IntegrityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(ActivityContext::class);
        $this->app->scoped(ActivityLog::class, DatabaseActivityLog::class);

        // Treasury and Accounting register their SealableSource here from their own providers.
        $this->app->singleton(SealableSources::class);

        // The modules (and the application, for checks that read several) register their IntegrityCheck here.
        $this->app->singleton(IntegrityChecks::class);

        // The checks of the comptroller's audit: the modules register theirs from their own providers.
        $this->app->singleton(AuditChecks::class);
        $this->app->scoped(SealedLedgerFindings::class);

        $this->callAfterResolving(AuditChecks::class, function (AuditChecks $checks): void {
            foreach (SealedLedgerAudit::MODES as $mode) {
                $checks->register(new SealedLedgerAudit($this->app->make(SealedLedgerFindings::class), $mode));
            }
        });
        $this->app->bind(SealedPeriods::class, DatabaseSealedPeriods::class);
    }

    public function boot(): void
    {
        Event::subscribe(RecordSecurityEvents::class);

        Event::listen(function (CommandStarting $event): void {
            // The kernel reports an empty name when Symfony could not resolve the command.
            if ($event->command !== '') {
                $this->app->make(ActivityContext::class)->fromCommand($event->command);
            }
        });
    }
}

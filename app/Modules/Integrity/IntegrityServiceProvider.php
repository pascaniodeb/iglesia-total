<?php

namespace App\Modules\Integrity;

use App\Modules\Integrity\Application\ActivityLog;
use App\Modules\Integrity\Application\Ledger\SealableSources;
use App\Modules\Integrity\Application\SealedPeriods;
use App\Modules\Integrity\Infrastructure\ActivityContext;
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

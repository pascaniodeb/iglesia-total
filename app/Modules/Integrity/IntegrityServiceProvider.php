<?php

namespace App\Modules\Integrity;

use App\Modules\Integrity\Application\ActivityLog;
use App\Modules\Integrity\Infrastructure\ActivityContext;
use App\Modules\Integrity\Infrastructure\DatabaseActivityLog;
use App\Modules\Integrity\Infrastructure\Listeners\RecordSecurityEvents;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the Integrity module: the activity log contract other modules depend on, the
 * per-request (or per-command) context it records and the security events it listens to.
 */
class IntegrityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(ActivityContext::class);
        $this->app->scoped(ActivityLog::class, DatabaseActivityLog::class);
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

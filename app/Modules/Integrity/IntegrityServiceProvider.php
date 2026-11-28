<?php

namespace App\Modules\Integrity;

use App\Modules\Integrity\Application\ActivityLog;
use App\Modules\Integrity\Application\Anchoring\ChainAnchoring;
use App\Modules\Integrity\Application\Audit\AuditChecks;
use App\Modules\Integrity\Application\IntegrityChecks;
use App\Modules\Integrity\Application\Ledger\SealableSources;
use App\Modules\Integrity\Application\SealedPeriods;
use App\Modules\Integrity\Application\Seals\RecordSeal;
use App\Modules\Integrity\Application\Seals\SealedModels;
use App\Modules\Integrity\Domain\Anchoring\ChainAnchor;
use App\Modules\Integrity\Infrastructure\ActivityContext;
use App\Modules\Integrity\Infrastructure\Anchoring\EsploraBitcoinHeaders;
use App\Modules\Integrity\Infrastructure\Anchoring\OpenTimestampsAnchor;
use App\Modules\Integrity\Infrastructure\Anchoring\Rfc3161Anchor;
use App\Modules\Integrity\Infrastructure\AnchorsCheck;
use App\Modules\Integrity\Infrastructure\Audit\SealedLedgerAudit;
use App\Modules\Integrity\Infrastructure\Audit\SealedLedgerFindings;
use App\Modules\Integrity\Infrastructure\DatabaseActivityLog;
use App\Modules\Integrity\Infrastructure\DatabaseSealedPeriods;
use App\Modules\Integrity\Infrastructure\LegacyActivityArchiveCheck;
use App\Modules\Integrity\Infrastructure\Listeners\RecordSecurityEvents;
use App\Modules\Integrity\Infrastructure\RowSealsCheck;
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
        $this->app->bind(ChainAnchoring::class, fn (): ChainAnchoring => new ChainAnchoring(array_values(array_map(
            fn (string $provider): ChainAnchor => match ($provider) {
                'rfc3161' => new Rfc3161Anchor(config('integrity.anchor.rfc3161'), (string) config('integrity.anchor.openssl'), (int) config('integrity.anchor.timeout')),
                'opentimestamps' => new OpenTimestampsAnchor(
                    config('integrity.anchor.opentimestamps.aggregators'),
                    config('integrity.anchor.opentimestamps.whitelist'),
                    new EsploraBitcoinHeaders((string) config('integrity.anchor.opentimestamps.esplora_url'), (int) config('integrity.anchor.timeout')),
                    (int) config('integrity.anchor.timeout'),
                ),
                default => throw new \InvalidArgumentException("Unknown anchoring provider: {$provider}."),
            },
            config('integrity.anchor.providers'),
        ))));

        // The modules register the models whose rows are sealed (see Seals\Sealed) from their own providers.
        $this->app->singleton(SealedModels::class);
        $this->app->bind(RecordSeal::class, fn (): RecordSeal => RecordSeal::fromConfig());

        $this->app->singleton(IntegrityChecks::class);

        $this->callAfterResolving(IntegrityChecks::class, function (IntegrityChecks $checks): void {
            $checks->register($this->app->make(LegacyActivityArchiveCheck::class));
            $checks->register($this->app->make(AnchorsCheck::class));
            $checks->register($this->app->make(RowSealsCheck::class));
        });

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

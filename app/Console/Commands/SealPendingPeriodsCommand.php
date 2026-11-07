<?php

namespace App\Console\Commands;

use App\Modules\Integrity\Application\Actions\SealPendingPeriods;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Seals the closed periods still pending a seal (a close never waits for the seal: a failure
 * leaves the period pending and this command, or the nightly run, catches up).
 */
#[Signature('ledger:seal-pending')]
#[Description('Seal every closed week and month that has no block in the sealed ledger yet')]
class SealPendingPeriodsCommand extends Command
{
    public function handle(SealPendingPeriods $sealPending): int
    {
        $result = $sealPending();

        foreach ($result['sealed'] as $period) {
            $this->components->info("Sealed {$period}.");
        }

        foreach ($result['failed'] as $period => $message) {
            $this->components->error("{$period}: {$message}");
        }

        if ($result['sealed'] === [] && $result['failed'] === []) {
            $this->components->info('Nothing pending.');
        }

        return $result['failed'] === [] ? self::SUCCESS : self::FAILURE;
    }
}

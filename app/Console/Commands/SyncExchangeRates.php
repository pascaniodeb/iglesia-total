<?php

namespace App\Console\Commands;

use App\Modules\Treasury\Application\Actions\RecordExchangeRates;
use App\Modules\Treasury\Domain\Rates\RatesUnavailable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('treasury:sync-rates')]
#[Description('Fetch today\'s official rates (BCV and Colombia TRM) and store them, unless the day has a manual correction')]
class SyncExchangeRates extends Command
{
    public function handle(RecordExchangeRates $rates): int
    {
        try {
            $result = $rates->sync();
        } catch (RatesUnavailable $e) {
            $this->components->error("The rates were not updated: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->components->info(match ($result) {
            RecordExchangeRates::SYNCED => 'Today\'s rates updated.',
            RecordExchangeRates::UNCHANGED => 'Today\'s rates were already up to date.',
            RecordExchangeRates::KEPT_MANUAL => 'The day has a manual correction: it is kept.',
        });

        return self::SUCCESS;
    }
}

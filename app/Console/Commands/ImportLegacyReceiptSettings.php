<?php

namespace App\Console\Commands;

use App\Modules\Treasury\Application\Actions\SaveReceiptSettings;
use App\Modules\Treasury\Infrastructure\Models\ReceiptSetting;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Brings the number the receipt book starts at from the old system (it had no receipts yet).
 * Idempotent: it goes through the same action as the screen, which logs a change only when there
 * is one.
 */
#[Signature('treasury:import-legacy-receipt-settings {--connection=legacy : Database connection of the old system} {--dry-run : Report what would change without writing}')]
#[Description('Import the starting number of the receipt book from the old system')]
class ImportLegacyReceiptSettings extends Command
{
    public function handle(SaveReceiptSettings $save): int
    {
        $legacy = DB::connection((string) $this->option('connection'))->table('receipt_settings')->where('id', 1)->first();

        if ($legacy === null) {
            $this->components->error('The old system has no receipt settings (row 1).');

            return self::FAILURE;
        }

        $start = (int) $legacy->start_number;
        $current = ReceiptSetting::current()->start_number;

        $this->components->twoColumnDetail('Start number: old system | new system', "{$start} | {$current}");

        if ($this->option('dry-run')) {
            $this->components->warn('Dry run: nothing was written.');

            return self::SUCCESS;
        }

        $save($start);
        $this->components->info($start === $current ? 'The receipt book already starts at that number.' : 'Receipt settings imported.');

        return self::SUCCESS;
    }
}

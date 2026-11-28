<?php

namespace App\Console\Commands;

use App\Modules\Integrity\Application\ActivityLog;
use App\Modules\Integrity\Application\Seals\SealUnsealedRows;
use App\Modules\Integrity\Application\Seals\VerifyRowSeals;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Seals the rows that have no seal yet (what was there before sealing was switched on, and what an
 * import wrote behind the Actions). Rows that have a seal are never touched: if one changed, that is
 * a finding for `ledger:verify`, not something to seal over. Idempotent.
 */
#[Signature('integrity:seal-rows')]
#[Description('Seal the rows of the sealed models that have no seal yet')]
class SealRows extends Command
{
    public function handle(SealUnsealedRows $seal, VerifyRowSeals $verify, ActivityLog $activityLog): int
    {
        if (! $verify()['enabled']) {
            $this->components->error('INTEGRITY_SEAL_KEY is not set: rows cannot be sealed.');

            return self::FAILURE;
        }

        $result = $seal();

        foreach ($result['by_table'] as $table => $count) {
            $this->components->twoColumnDetail($table, (string) $count);
        }

        if ($result['sealed'] > 0) {
            $activityLog->system('integrity.rows_sealed', __('Sealed :count rows that had no seal.', ['count' => $result['sealed']]), null, $result['by_table']);
        }

        $this->components->info("Rows sealed now: {$result['sealed']}.");

        return self::SUCCESS;
    }
}

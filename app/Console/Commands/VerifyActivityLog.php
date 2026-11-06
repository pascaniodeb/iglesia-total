<?php

namespace App\Console\Commands;

use App\Modules\Integrity\Application\Actions\VerifyActivityChain;
use App\Modules\Integrity\Application\ActivityLog;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Verifies the activity log hash chain and records that it was verified, with its result:
 * the question a controller has to answer is not only "is anything wrong" but also "how
 * long has nobody looked". Prints the head (seq + hash) so it can be kept outside the
 * database. Exits with failure when a link is broken, so the scheduler reports it.
 */
#[Signature('activity:verify')]
#[Description('Verify the hash chain of the activity log')]
class VerifyActivityLog extends Command
{
    public function handle(VerifyActivityChain $verify, ActivityLog $activityLog): int
    {
        $result = $verify();
        $intact = $result['broken'] === [];

        $activityLog->system(
            'activity_log.verified',
            $intact
                ? __('Verified the activity log: :rows entries, chain intact.', ['rows' => $result['rows']])
                : __('Verified the activity log: :count broken links.', ['count' => count($result['broken'])]),
            details: [
                'rows' => $result['rows'],
                'broken' => $result['broken'],
                'head_seq' => $result['head_seq'],
                'head_hash' => $result['head_hash'],
            ],
        );

        if (! $intact) {
            $this->components->error('The activity log chain is broken at seq: '.implode(', ', $result['broken']).'.');

            return self::FAILURE;
        }

        $this->components->info("Activity log intact: {$result['rows']} entries.");
        $this->components->twoColumnDetail('Head seq', (string) $result['head_seq']);
        $this->components->twoColumnDetail('Head hash', (string) $result['head_hash']);

        return self::SUCCESS;
    }
}

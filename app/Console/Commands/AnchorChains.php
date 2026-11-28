<?php

namespace App\Console\Commands;

use App\Modules\Integrity\Application\ActivityLog;
use App\Modules\Integrity\Application\Anchoring\ChainAnchoring;
use App\Modules\Integrity\Infrastructure\Anchoring\OtsTimestamp;
use App\Modules\Integrity\Infrastructure\Models\ChainAnchor;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Pins the head of the sealed ledger and of the activity log outside the church (an RFC 3161
 * timestamp and Bitcoin through OpenTimestamps). Publishing is an action towards the outside, so it
 * only runs when INTEGRITY_ANCHOR_ENABLED is on; --export only reads what is already stored.
 */
#[Signature('integrity:anchor {--upgrade : Complete the pending proofs (OpenTimestamps takes hours)} {--export= : Folder to export the proofs to, to check them with the official tools}')]
#[Description('Anchor the heads of the chains outside the church (RFC 3161 and Bitcoin)')]
class AnchorChains extends Command
{
    public function handle(ChainAnchoring $anchoring, ActivityLog $activityLog): int
    {
        if ($this->option('export') !== null) {
            return $this->export((string) $this->option('export'));
        }

        if (! config('integrity.anchor.enabled')) {
            $this->components->error('External anchoring is switched off (INTEGRITY_ANCHOR_ENABLED).');

            return self::FAILURE;
        }

        return $this->option('upgrade') ? $this->upgrade($anchoring, $activityLog) : $this->anchor($anchoring, $activityLog);
    }

    private function anchor(ChainAnchoring $anchoring, ActivityLog $activityLog): int
    {
        ['anchored' => $anchored, 'failed' => $failed] = $anchoring->anchorHeads();

        foreach ($anchored as $anchor) {
            $activityLog->system(
                'integrity.anchored',
                __('Anchored the :chain at :position with :provider.', ['chain' => $this->chainLabel($anchor->chain), 'position' => $anchor->position, 'provider' => $anchor->provider]),
                $anchor,
                ['chain' => $anchor->chain, 'provider' => $anchor->provider, 'position' => $anchor->position, 'status' => $anchor->status],
            );
            $this->components->info("Anchor {$anchor->provider} of the {$anchor->chain} head {$anchor->position}: {$anchor->status}.");
        }

        if ($anchored === [] && $failed === []) {
            $this->components->info('The chains are already anchored up to their heads.');
        }

        foreach ($failed as $label) {
            $activityLog->system('integrity.anchor_failed', __('Could not anchor with :provider.', ['provider' => $label]), null, ['target' => $label]);
            $this->components->error("Could not anchor {$label}: it is retried on the next run.");
        }

        return $failed === [] ? self::SUCCESS : self::FAILURE;
    }

    private function upgrade(ChainAnchoring $anchoring, ActivityLog $activityLog): int
    {
        $confirmed = $anchoring->upgradePending();

        foreach ($confirmed as $anchor) {
            $activityLog->system(
                'integrity.anchor_confirmed',
                __('Confirmed the anchor of the :chain at :position with :provider.', ['chain' => $this->chainLabel($anchor->chain), 'position' => $anchor->position, 'provider' => $anchor->provider]),
                $anchor,
                ['chain' => $anchor->chain, 'provider' => $anchor->provider, 'position' => $anchor->position, 'height' => $anchor->proof['height'] ?? null],
            );
            $this->components->info("Anchor {$anchor->provider} of the {$anchor->chain} head {$anchor->position} confirmed.");
        }

        $pending = ChainAnchor::query()->where('status', 'pending')->count();
        $this->components->info('Confirmed now: '.count($confirmed).". Pending: {$pending}.");

        return self::SUCCESS;
    }

    /**
     * Exports each anchor as standard files: the statement (.txt) and its proof (.ots for
     * `ots verify`; .tsq/.tsr for `openssl ts -verify`). Nothing here asks to trust this system.
     */
    private function export(string $directory): int
    {
        if (! is_dir($directory) && ! mkdir($directory, 0755, true)) {
            $this->components->error("Could not create the folder {$directory}.");

            return self::FAILURE;
        }

        $count = 0;

        foreach (ChainAnchor::query()->orderBy('chain')->orderBy('position')->cursor() as $anchor) {
            $base = rtrim($directory, '/')."/anchor-{$anchor->chain}-{$anchor->position}-{$anchor->provider}";
            file_put_contents("{$base}.txt", $anchor->statement);

            if ($anchor->provider === 'opentimestamps') {
                $timestamp = (string) base64_decode((string) ($anchor->proof['timestamp'] ?? $anchor->proof['receipts'][0]['timestamp'] ?? ''), true);
                file_put_contents("{$base}.txt.ots", OtsTimestamp::parse($timestamp, $anchor->digestBytes())->detachedFile());
            } else {
                file_put_contents("{$base}.tsq", (string) base64_decode((string) $anchor->proof['query'], true));
                file_put_contents("{$base}.tsr", (string) base64_decode((string) $anchor->proof['reply'], true));
            }

            $count++;
        }

        $this->components->info("Anchors exported: {$count}, in {$directory}.");

        return self::SUCCESS;
    }

    private function chainLabel(string $chain): string
    {
        return $chain === 'ledger' ? __('sealed ledger') : __('activity log');
    }
}

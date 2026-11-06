<?php

namespace App\Modules\Accounting\Application\Actions;

use App\Modules\Accounting\Domain\Journal\JournalEntryDraft;
use App\Modules\Accounting\Domain\Journal\JournalLine;
use App\Modules\Accounting\Domain\Journal\LedgerConflict;
use App\Modules\Accounting\Infrastructure\Models\JournalEntry;
use App\Modules\Integrity\Application\ActivityLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Cancels an entry the only way the books allow: a reversal that inverts every line, with a
 * reason, keeping the original, the reversal, who and when.
 *
 * An entry is reversed at most once (a retry returns the same reversal) and a reversal is never
 * reversed (post the corrected entry instead). The database checks that the reversal mirrors
 * its original exactly.
 */
final readonly class ReverseJournalEntry
{
    public function __construct(private PostJournalEntry $post, private ActivityLog $activityLog) {}

    /**
     * @throws LedgerConflict
     */
    public function __invoke(JournalEntry $original, string $reason, ?string $entryDate = null): JournalEntry
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new LedgerConflict('A reversal needs a reason.');
        }

        if ($original->reversal_of !== null) {
            throw new LedgerConflict('A reversal cannot be reversed: post the corrected entry instead.');
        }

        return DB::transaction(function () use ($original, $reason, $entryDate): JournalEntry {
            JournalEntry::query()->whereKey($original->id)->lockForUpdate()->first();

            $existing = JournalEntry::query()->where('reversal_of', $original->id)->first();

            if ($existing !== null) {
                return $existing->load('lines.account');
            }

            $original->load('lines.account');

            $lines = [];

            foreach ($original->lines as $line) {
                $lines[] = (new JournalLine($line->account->code, $line->debitMoney(), $line->creditMoney(), $line->description))->inverted();
            }

            $draft = new JournalEntryDraft(
                entryDate: $entryDate ?? $original->entry_date->toDateString(),
                description: Str::limit(__('REVERSAL: :description', ['description' => $original->description]), 252),
                originType: 'reversal',
                originId: $original->id,
                originPurpose: 'reversal',
                lines: $lines,
            );

            $reversal = $this->post->insert(
                $draft,
                $original->lines->map->account->keyBy('code'),
                ['reversal_of' => $original->id, 'reversal_reason' => Str::limit($reason, 252)],
            );

            $this->activityLog->record(
                'journal_entry.reversed',
                __('Reversed the entry ":description": :reason', ['description' => $original->description, 'reason' => $reason]),
                $reversal,
                ['reversal_of' => $original->id],
            );

            return $reversal->load('lines.account');
        });
    }
}

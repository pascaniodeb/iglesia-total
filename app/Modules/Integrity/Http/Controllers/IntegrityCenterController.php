<?php

namespace App\Modules\Integrity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Integrity\Application\IntegrityChecks;
use App\Modules\Integrity\Application\LegacyLedgerArchive;
use App\Modules\Integrity\Http\Tables\LedgerBlockListing;
use App\Modules\Integrity\Infrastructure\Models\ActivityLogEntry;
use App\Modules\Integrity\Infrastructure\Models\LedgerBlock;
use App\Modules\Shared\Tables\TableQuery;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The integrity center (sealed_ledger.view, enforced by the route): where the sealed ledger, the
 * old chain's archive and the checks of every module are read in one place. The page is quick on
 * purpose: the checks are set-based queries run live, and the chain verification (which rebuilds
 * every sealed period) is the last one on record, run again by whoever asks.
 */
class IntegrityCenterController extends Controller
{
    /** Verifications listed in the history. */
    private const int HISTORY = 12;

    private const array VERIFICATIONS = ['ledger.verified', 'journal.verified', 'books.verified', 'integrity.verified'];

    public function index(Request $request, IntegrityChecks $checks, LegacyLedgerArchive $legacy): Response
    {
        $table = TableQuery::fromRequest(
            $request,
            LedgerBlockListing::sortable(),
            LedgerBlockListing::DEFAULT_SORT,
            LedgerBlockListing::facets(),
            defaultDirection: 'desc',
            withStatus: false,
        );

        $head = LedgerBlock::query()->orderByDesc('height')->first();
        $genesis = LedgerBlock::query()->where('kind', 'genesis')->first();
        $lastChain = ActivityLogEntry::query()->where('action', 'ledger.verified')->orderByDesc('seq')->first();

        return Inertia::render('integrity/index', [
            'status' => [
                'blocks' => LedgerBlock::query()->count(),
                'height' => $head?->height,
                'head_hash' => $head === null ? null : trim($head->hash),
                'started_at' => $genesis?->sealed_at->toIso8601String(),
                'legacy' => $legacy->head(),
                'last_chain_check' => $lastChain === null ? null : $this->chainCheck($lastChain),
            ],
            'checks' => $checks->run(),
            'blocks' => $table
                ->paginate(LedgerBlockListing::base($table), LedgerBlockListing::searchable())
                ->through(LedgerBlockListing::row(...)),
            'filters' => $table->filters(),
            'kinds' => LedgerBlockListing::KINDS,
            'history' => $this->history(),
        ]);
    }

    /**
     * The chain verification the page shows, as the log recorded it.
     *
     * @return array{at: string, intact: bool, matches_books: bool, issues: list<array<string, mixed>>, unsealed: list<array<string, mixed>>}
     */
    private function chainCheck(ActivityLogEntry $entry): array
    {
        $details = $entry->changes;

        return [
            'at' => $entry->occurred_at->toIso8601String(),
            'intact' => (bool) ($details['intact'] ?? false),
            'matches_books' => (bool) ($details['matches_books'] ?? false),
            'issues' => array_values(is_array($details['issues'] ?? null) ? $details['issues'] : []),
            'unsealed' => array_values(is_array($details['unsealed'] ?? null) ? $details['unsealed'] : []),
        ];
    }

    /**
     * The latest verifications of any kind, newest first, each with whether it passed.
     *
     * @return list<array{id: string, at: string, action: string, summary: string, passed: bool}>
     */
    private function history(): array
    {
        $entries = ActivityLogEntry::query()
            ->whereIn('action', self::VERIFICATIONS)
            ->orderByDesc('seq')
            ->limit(self::HISTORY)
            ->get()
            ->map(fn (ActivityLogEntry $entry): array => [
                'id' => $entry->id,
                'at' => $entry->occurred_at->toIso8601String(),
                'action' => $entry->action,
                'summary' => $entry->summary,
                'passed' => $this->passed($entry),
            ])
            ->values()
            ->all();

        return array_values($entries);
    }

    private function passed(ActivityLogEntry $entry): bool
    {
        $details = $entry->changes;

        return match ($entry->action) {
            'ledger.verified' => (bool) ($details['intact'] ?? false) && (bool) ($details['matches_books'] ?? false),
            'journal.verified' => (bool) ($details['passed'] ?? false),
            'books.verified' => collect($details)->every(fn (mixed $check): bool => is_array($check) && ($check['findings'] ?? []) === []),
            default => (bool) ($details['passed'] ?? false),
        };
    }
}

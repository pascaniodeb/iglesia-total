<?php

namespace App\Console\Commands;

use App\Console\Reconciliation\LegacyReconciliation;
use App\Console\Reconciliation\ReconciliationRow;
use App\Modules\Integrity\Application\ActivityLog;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Compares the new books with the old system, figure by figure, to the cent: offerings and manual
 * entries one by one, every week, every month by account code, the equation and the carry-forward
 * of every month, the cash, and the sealed payloads of the old chain. Each figure is computed by
 * several paths (the old rows, PostgreSQL, a fold in PHP, the seals) and all must agree.
 *
 * Read-only and repeatable. It fails unless every difference is zero: it is the gate of the cut-over.
 */
#[Signature('reconcile:legacy {--connection=legacy : Database connection of the old system} {--rows : Print every figure checked, not only the differences}')]
#[Description('Compare the new books with the old system to the cent')]
class ReconcileLegacy extends Command
{
    public function handle(LegacyReconciliation $reconcile, ActivityLog $activityLog): int
    {
        $report = $reconcile((string) $this->option('connection'));
        $summary = $report->summary();

        foreach ($summary as $check) {
            $line = "{$check['rows']} figures";
            $note = $check['findings'] === 0 ? '' : ", <fg=yellow>{$check['findings']} findings about the old system</>";
            $this->components->twoColumnDetail($check['title'], $check['differences'] === 0 ? "{$line}, 0 differences{$note}" : "<fg=red>{$line}, {$check['differences']} DIFFERENCES</>{$note}");
        }

        $rows = $this->option('rows') ? $report->rows() : $report->differences();

        foreach ($rows as $row) {
            $this->components->twoColumnDetail("{$row->check} · {$row->period} · {$row->figure}", $this->values($row));
        }

        $total = count($report->rows());
        $differences = count($report->differences());
        $findings = $report->findings();

        if ($findings !== []) {
            $this->components->warn(count($findings).' figures where the OLD system disagrees with itself: its sealed copy does not match its own books ('.$this->periods($findings).'). The new books agree with the old books. Not a difference of the new books; worth knowing.');

            foreach ($this->option('rows') ? [] : $findings as $row) {
                $this->components->twoColumnDetail("finding · {$row->check} · {$row->period} · {$row->figure}", $this->values($row));
            }
        }

        $activityLog->system(
            'reconciliation.run',
            $differences === 0
                ? __('Reconciled the new books with the old system: :count figures, no difference.', ['count' => $total])
                : __('Reconciled the new books with the old system: :count differences in :total figures.', ['count' => $differences, 'total' => $total]),
            details: ['figures' => $total, 'differences' => $differences, 'findings_about_the_old_system' => count($findings), 'stale_old_seals' => $this->periods($findings), 'checks' => $summary],
        );

        if (! $report->passes()) {
            $this->components->error($total === 0 ? 'Nothing was checked.' : "{$differences} differences out of {$total} figures: the books do NOT match the old system.");

            return self::FAILURE;
        }

        $this->components->info($findings === []
            ? "Every one of the {$total} figures is the same on every path, to the cent."
            : "The new books equal the old books on every one of the {$total} figures, to the cent (the findings above are about the old system's own seals).");

        return self::SUCCESS;
    }

    /**
     * The months of a list of findings ("2026-08, 2026-09").
     *
     * @param  list<ReconciliationRow>  $rows
     */
    private function periods(array $rows): string
    {
        return implode(', ', array_values(array_unique(array_map(fn (ReconciliationRow $row): string => substr($row->period, 0, 7), $rows))));
    }

    private function values(ReconciliationRow $row): string
    {
        $parts = [];

        foreach ($row->values as $path => $value) {
            if ($value !== null) {
                $parts[] = "{$path}: ".($value === '' ? '—' : $value);
            }
        }

        return implode(' | ', $parts);
    }
}

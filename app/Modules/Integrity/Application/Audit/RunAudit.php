<?php

namespace App\Modules\Integrity\Application\Audit;

use App\Modules\Integrity\Application\ActivityLog;
use App\Modules\Integrity\Infrastructure\Models\Audit;
use Illuminate\Contracts\Auth\Factory as Auth;
use Illuminate\Support\Facades\DB;

/**
 * Runs the audit and keeps the ACT: who ran it, when, the notes and the findings as they stood
 * (with which of them were already acknowledged), so a later reader can tell a known and accepted
 * problem from one that appeared afterwards. The checks themselves are never stored as a cache.
 * The audit is "clean" when no pending finding that counts (critical or warning) is left.
 */
final readonly class RunAudit
{
    public function __construct(private EvaluateAudit $evaluate, private ActivityLog $activityLog, private Auth $auth) {}

    public function __invoke(?string $notes = null): Audit
    {
        return DB::transaction(function () use ($notes): Audit {
            $evaluation = ($this->evaluate)();
            $actorId = $this->auth->guard()->id();
            $notes = $notes === null || trim($notes) === '' ? null : trim($notes);

            $audit = Audit::query()->create([
                'ran_by' => $actorId === null ? null : (string) $actorId,
                'status' => $evaluation['pending_count'] === 0 ? 'clean' : 'findings',
                'findings_count' => $evaluation['pending_count'],
                'critical_count' => $evaluation['critical_count'],
                'acknowledged_count' => $evaluation['acknowledged_count'],
                'snapshot' => ['checks' => $evaluation['checks']],
                'notes' => $notes,
            ])->refresh();

            $this->activityLog->record(
                'audit.ran',
                $audit->status === 'clean'
                    ? __('Ran an audit: no pending findings.')
                    : __('Ran an audit: :count pending findings, :critical critical.', ['count' => $audit->findings_count, 'critical' => $audit->critical_count]),
                $audit,
                ['findings' => $audit->findings_count, 'critical' => $audit->critical_count, 'acknowledged' => $audit->acknowledged_count],
            );

            return $audit;
        });
    }
}

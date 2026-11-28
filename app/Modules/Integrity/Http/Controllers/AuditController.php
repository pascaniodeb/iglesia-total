<?php

namespace App\Modules\Integrity\Http\Controllers;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Modules\Church\Application\ChurchIdentity;
use App\Modules\Integrity\Application\ActivityLog;
use App\Modules\Integrity\Application\Audit\AcknowledgeFinding;
use App\Modules\Integrity\Application\Audit\EvaluateAudit;
use App\Modules\Integrity\Application\Audit\RevokeAcknowledgement;
use App\Modules\Integrity\Application\Audit\RunAudit;
use App\Modules\Integrity\Infrastructure\Models\Audit;
use App\Modules\Integrity\Infrastructure\Models\AuditAcknowledgement;
use App\Modules\Integrity\Infrastructure\Pdf\AuditReportPdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The comptroller's audits (audits.view to read; audits.record to run one, accept a finding and
 * lift an acceptance). The findings on screen are recomputed from the live books on every load:
 * what is stored is the act, never a cache of the answer.
 */
class AuditController extends Controller
{
    /** Audits and acknowledgements listed on the page. */
    private const int RECENT = 15;

    public function index(Request $request, EvaluateAudit $evaluate): Response
    {
        $last = Audit::query()->orderByDesc('ran_at')->first();

        return Inertia::render('audits/index', [
            'evaluation' => $evaluate(),
            'last_run_at' => $last?->ran_at->toIso8601String(),
            'days_since_last_run' => $last === null ? null : (int) $last->ran_at->diffInDays(now()),
            'audits' => Audit::query()->with('runner')->orderByDesc('ran_at')->limit(self::RECENT)->get()->map(fn (Audit $audit): array => $this->summary($audit))->values()->all(),
            'acknowledgements' => AuditAcknowledgement::query()->with(['acknowledger', 'revocation.revoker'])->orderByDesc('acknowledged_at')->limit(self::RECENT * 3)->get()->map(fn (AuditAcknowledgement $ack): array => [
                'id' => $ack->id,
                'check_id' => $ack->check_id,
                'subject' => $ack->subject,
                'detail' => $ack->detail,
                'reason' => $ack->reason,
                'by' => $ack->acknowledger?->name,
                'at' => $ack->acknowledged_at->toIso8601String(),
                'revoked' => $ack->revocation === null ? null : [
                    'reason' => $ack->revocation->reason,
                    'by' => $ack->revocation->revoker?->name,
                    'at' => $ack->revocation->revoked_at->toIso8601String(),
                ],
            ])->values()->all(),
            'can_record' => $request->user()?->can(Permission::AuditsRecord->value) ?? false,
        ]);
    }

    public function show(Audit $audit): Response
    {
        $audit->load('runner');

        return Inertia::render('audits/show', [
            'audit' => [
                ...$this->summary($audit),
                'checks' => $audit->snapshot['checks'] ?? [],
            ],
        ]);
    }

    /**
     * The report of an audit, drawn from what was recorded when it ran.
     */
    public function pdf(Audit $audit, ChurchIdentity $church, AuditReportPdf $document, ActivityLog $activityLog): HttpResponse
    {
        if ($church->profile() === null) {
            abort(409, __('Fill in the church settings first.'));
        }

        $bytes = $document->render($audit);
        $activityLog->access('audit.pdf_downloaded', __('Opened the PDF of the audit of :date.', ['date' => $audit->ran_at->format('d/m/Y H:i')]), $audit);

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="auditoria-'.$audit->ran_at->format('Y-m-d-His').'.pdf"',
            'Cache-Control' => 'private, no-cache, must-revalidate',
        ]);
    }

    public function store(Request $request, RunAudit $run): RedirectResponse
    {
        $data = $request->validate(['notes' => ['nullable', 'string', 'max:1000']]);
        $audit = $run($data['notes'] ?? null);

        Inertia::flash('toast', $audit->status === 'clean'
            ? ['type' => 'success', 'message' => __('The audit was recorded: no pending findings.')]
            : ['type' => 'warning', 'message' => __('The audit was recorded with :count pending findings.', ['count' => $audit->findings_count])]);

        return redirect()->route('audits.show', $audit);
    }

    public function acknowledge(Request $request, AcknowledgeFinding $acknowledge): RedirectResponse
    {
        $data = $request->validate([
            'fingerprint' => ['required', 'string', 'size:64'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $acknowledge($data['fingerprint'], $data['reason']);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('The finding was accepted and the reason recorded.')]);

        return back();
    }

    public function revoke(Request $request, AuditAcknowledgement $acknowledgement, RevokeAcknowledgement $revoke): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        $revoke($acknowledgement, $data['reason']);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('The acceptance was lifted: the finding is pending again.')]);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Audit $audit): array
    {
        return [
            'id' => $audit->id,
            'ran_at' => $audit->ran_at->toIso8601String(),
            'ran_by' => $audit->runner?->name,
            'status' => $audit->status,
            'findings_count' => $audit->findings_count,
            'critical_count' => $audit->critical_count,
            'acknowledged_count' => $audit->acknowledged_count,
            'notes' => $audit->notes,
            'is_imported' => $audit->legacy_id !== null,
        ];
    }
}

<?php

namespace App\Modules\Integrity\Application\Audit;

use App\Modules\Integrity\Domain\Audit\AuditFinding;
use App\Modules\Integrity\Infrastructure\Models\AuditAcknowledgement;
use Throwable;

/**
 * Runs every audit check against the live books and says, for each finding, whether somebody
 * already reviewed that very finding (its fingerprint has an acknowledgement that was not revoked).
 * Nothing is stored here: an audit that reports yesterday's answer is not an audit.
 *
 * A check that cannot run is reported as a critical finding of its own: the audit must never say
 * "all clear" about something it could not look at.
 */
final readonly class EvaluateAudit
{
    public function __construct(private AuditChecks $checks) {}

    /**
     * @return array{
     *     checks: list<array{
     *         id: string, label: string, description: string, severity: string,
     *         pending: int, acknowledged: int,
     *         findings: list<array{subject: string, detail: string, fingerprint: string, acknowledgement: array{id: string, reason: string, by: string|null, at: string}|null}>
     *     }>,
     *     pending_count: int,
     *     critical_count: int,
     *     acknowledged_count: int
     * }
     */
    public function __invoke(): array
    {
        $evaluated = [];

        foreach ($this->checks->all() as $check) {
            try {
                $findings = $check->findings();
            } catch (Throwable $exception) {
                $findings = [new AuditFinding($check->id(), __('The check could not run: :reason', ['reason' => $exception->getMessage()]))];
            }

            $evaluated[] = [$check, array_map(fn ($finding): array => ['subject' => $finding->subject, 'detail' => $finding->detail, 'fingerprint' => $finding->fingerprint($check->id())], $findings)];
        }

        $fingerprints = array_merge(...array_map(fn (array $pair): array => array_column($pair[1], 'fingerprint'), $evaluated ?: [[null, []]]));
        $active = $fingerprints === [] ? collect() : AuditAcknowledgement::query()
            ->whereIn('fingerprint', $fingerprints)
            ->whereDoesntHave('revocation')
            ->with('acknowledger')
            ->orderBy('acknowledged_at')
            ->get()
            ->keyBy(fn (AuditAcknowledgement $ack): string => trim($ack->fingerprint));

        $checks = [];
        $pendingCount = 0;
        $criticalCount = 0;
        $acknowledgedCount = 0;

        foreach ($evaluated as [$check, $findings]) {
            $pending = 0;
            $acknowledged = 0;

            foreach ($findings as $index => $finding) {
                $ack = $active->get($finding['fingerprint']);
                $findings[$index]['acknowledgement'] = $ack === null ? null : [
                    'id' => $ack->id,
                    'reason' => $ack->reason,
                    'by' => $ack->acknowledger?->name,
                    'at' => $ack->acknowledged_at->toIso8601String(),
                ];
                $ack === null ? $pending++ : $acknowledged++;
            }

            if ($check->severity()->counts()) {
                $pendingCount += $pending;
                $criticalCount += $check->severity()->value === 'critical' ? $pending : 0;
            }

            $acknowledgedCount += $acknowledged;
            $checks[] = [
                'id' => $check->id(),
                'label' => $check->label(),
                'description' => $check->description(),
                'severity' => $check->severity()->value,
                'pending' => $pending,
                'acknowledged' => $acknowledged,
                'findings' => $findings,
            ];
        }

        return ['checks' => $checks, 'pending_count' => $pendingCount, 'critical_count' => $criticalCount, 'acknowledged_count' => $acknowledgedCount];
    }
}

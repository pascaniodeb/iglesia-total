<?php

namespace App\Modules\Integrity\Application\Audit;

use App\Modules\Integrity\Application\ActivityLog;
use App\Modules\Integrity\Infrastructure\Models\AuditAcknowledgement;
use Illuminate\Contracts\Auth\Factory as Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Accepts ONE finding, as it is right now, with a mandatory reason: an acknowledgement without a
 * reason is hiding something. The finding must exist at this moment (the audit is recomputed to
 * check it), and the subject and detail are copied as they were, so the record can be read in two
 * years without running the checks again. It stops applying by itself if the data change.
 */
final readonly class AcknowledgeFinding
{
    public const int MIN_REASON = 10;

    public function __construct(private EvaluateAudit $evaluate, private ActivityLog $activityLog, private Auth $auth) {}

    public function __invoke(string $fingerprint, string $reason): AuditAcknowledgement
    {
        $reason = trim($reason);

        if (mb_strlen($reason) < self::MIN_REASON) {
            throw ValidationException::withMessages(['reason' => __('Explain why this finding is accepted (at least :min characters).', ['min' => self::MIN_REASON])]);
        }

        return DB::transaction(function () use ($fingerprint, $reason): AuditAcknowledgement {
            foreach (($this->evaluate)()['checks'] as $check) {
                foreach ($check['findings'] as $finding) {
                    if ($finding['fingerprint'] !== $fingerprint) {
                        continue;
                    }

                    if ($finding['acknowledgement'] !== null) {
                        throw ValidationException::withMessages(['fingerprint' => __('That finding is already acknowledged.')]);
                    }

                    $actorId = $this->auth->guard()->id();
                    $ack = AuditAcknowledgement::query()->create([
                        'fingerprint' => $fingerprint,
                        'check_id' => $check['id'],
                        'subject' => $finding['subject'],
                        'detail' => $finding['detail'],
                        'reason' => $reason,
                        'acknowledged_by' => $actorId === null ? null : (string) $actorId,
                    ])->refresh();

                    $this->activityLog->record(
                        'audit.acknowledged',
                        __('Acknowledged a finding of the audit (:check, :subject).', ['check' => $check['label'], 'subject' => $finding['subject']]),
                        $ack,
                        ['check' => $check['id'], 'subject' => $finding['subject']],
                    );

                    return $ack;
                }
            }

            throw ValidationException::withMessages(['fingerprint' => __('That finding no longer exists: the data changed.')]);
        });
    }
}

<?php

namespace App\Modules\Integrity\Application\Audit;

use App\Modules\Integrity\Application\ActivityLog;
use App\Modules\Integrity\Infrastructure\Models\AuditAcknowledgement;
use App\Modules\Integrity\Infrastructure\Models\AuditAcknowledgementRevocation;
use Illuminate\Contracts\Auth\Factory as Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Lifts an acknowledgement, with a reason, as a new row: the acknowledgement stays in the history.
 * The finding it covered goes back to pending.
 */
final readonly class RevokeAcknowledgement
{
    public function __construct(private ActivityLog $activityLog, private Auth $auth) {}

    public function __invoke(AuditAcknowledgement $ack, string $reason): AuditAcknowledgementRevocation
    {
        $reason = trim($reason);

        if (mb_strlen($reason) < AcknowledgeFinding::MIN_REASON) {
            throw ValidationException::withMessages(['reason' => __('Explain why the acceptance is lifted (at least :min characters).', ['min' => AcknowledgeFinding::MIN_REASON])]);
        }

        return DB::transaction(function () use ($ack, $reason): AuditAcknowledgementRevocation {
            if ($ack->revocation()->exists()) {
                throw ValidationException::withMessages(['acknowledgement' => __('That acceptance was already lifted.')]);
            }

            $actorId = $this->auth->guard()->id();
            $revocation = AuditAcknowledgementRevocation::query()->create([
                'acknowledgement_id' => $ack->id,
                'reason' => $reason,
                'revoked_by' => $actorId === null ? null : (string) $actorId,
            ])->refresh();

            $this->activityLog->record(
                'audit.acknowledgement_revoked',
                __('Lifted the acceptance of a finding (:check, :subject).', ['check' => $ack->check_id, 'subject' => $ack->subject]),
                $ack,
                ['check' => $ack->check_id, 'subject' => $ack->subject],
            );

            return $revocation;
        });
    }
}

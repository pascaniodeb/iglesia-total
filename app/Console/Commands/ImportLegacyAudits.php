<?php

namespace App\Console\Commands;

use App\Modules\Integrity\Application\ActivityLog;
use App\Modules\Integrity\Application\Audit\EvaluateAudit;
use App\Modules\Integrity\Domain\Audit\AuditFinding;
use App\Modules\Integrity\Infrastructure\Models\Audit;
use App\Modules\Integrity\Infrastructure\Models\AuditAcknowledgement;
use App\Modules\Integrity\Infrastructure\Models\AuditAcknowledgementRevocation;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Copies the comptroller's work from the old system: its audits and its acknowledgements (with
 * their revocations). The fingerprint of an acknowledgement is the old system's formula, so one
 * that points to a finding the new checks still raise (same check, same subject, same words) applies
 * again by itself; the rest is kept as history and listed, never dropped: an acknowledgement of a
 * check that no longer exists (an offering touched after a close, now impossible) still says who
 * reviewed what, and why.
 *
 * Idempotent by legacy id. What is already imported must equal the old system's (history is never
 * rewritten). --dry-run reports without writing.
 */
#[Signature('audits:import-legacy {--connection=legacy : Database connection of the old system} {--dry-run : Report what would be imported without writing}')]
#[Description('Import the audits and acknowledgements of the old system')]
class ImportLegacyAudits extends Command
{
    public function handle(EvaluateAudit $evaluate, ActivityLog $activityLog): int
    {
        $legacy = DB::connection((string) $this->option('connection'));
        $audits = $legacy->table('audits')->whereNull('deleted_at')->orderBy('id')->get()->map(fn (object $row): array => (array) $row);
        $trashed = $legacy->table('audits')->whereNotNull('deleted_at')->count();
        $acks = $legacy->table('audit_acknowledgements')->orderBy('id')->get()->map(fn (object $row): array => (array) $row);
        $this->components->info("Legacy source: {$audits->count()} audits ({$trashed} in the trash, not imported) and {$acks->count()} acknowledgements.");

        /** @var array<int, string> $users legacy user id => user id */
        $users = DB::table('users')->whereNotNull('legacy_id')->pluck('id', 'legacy_id')->all();

        foreach ($acks as $ack) {
            if (preg_match('/^[0-9a-f]{64}$/', (string) $ack['fingerprint']) !== 1 || trim((string) $ack['reason']) === '') {
                $this->components->error("Acknowledgement {$ack['id']} has no valid fingerprint or no reason: nothing was written.");

                return self::FAILURE;
            }
        }

        $current = collect($evaluate()['checks'])->flatMap(fn (array $check) => array_column($check['findings'], 'fingerprint'))->flip();
        $applying = $acks->filter(fn (array $ack): bool => $ack['revoked_at'] === null && $current->has($ack['fingerprint']));
        $historical = $acks->reject(fn (array $ack): bool => $applying->contains('id', $ack['id']));

        $this->components->twoColumnDetail('Acknowledgements that apply again to a current finding', (string) $applying->count());
        $this->components->twoColumnDetail('Acknowledgements kept as history', (string) $historical->count());

        foreach ($historical->groupBy('check_id') as $check => $rows) {
            $this->components->twoColumnDetail("  {$check}", $rows->count().' (no current finding with the same words)');
        }

        if ($this->option('dry-run')) {
            $this->components->warn('Dry run: nothing was written.');

            return self::SUCCESS;
        }

        $imported = ['acks' => 0, 'revocations' => 0, 'audits' => 0];

        try {
            DB::transaction(function () use ($acks, $audits, $users, &$imported): void {
                foreach ($acks as $row) {
                    $this->importAcknowledgement($row, $users, $imported);
                }

                foreach ($audits as $row) {
                    $this->importAudit($row, $users, $imported);
                }
            });
        } catch (ImportDifference $difference) {
            foreach ($difference->report as $field => [$old, $new]) {
                $this->components->error("{$field}: the old system says {$old}, the new one has {$new}. Nothing was written.");
            }

            return self::FAILURE;
        }

        if (array_sum($imported) > 0) {
            $activityLog->system(
                'audits.imported_legacy',
                __('Imported the audits of the old system: :audits audits, :acks acknowledgements and :revocations revocations.', ['audits' => $imported['audits'], 'acks' => $imported['acks'], 'revocations' => $imported['revocations']]),
                details: [...$imported, 'applying' => $applying->count(), 'history' => $historical->count()],
            );
        }

        $this->components->info("Audits imported now: {$imported['audits']}; acknowledgements: {$imported['acks']}; revocations: {$imported['revocations']}.");

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<int, string>  $users
     * @param  array<string, int>  $imported
     */
    private function importAcknowledgement(array $row, array $users, array &$imported): void
    {
        $ack = AuditAcknowledgement::query()->where('legacy_id', $row['id'])->first();
        $wanted = [
            'fingerprint' => (string) $row['fingerprint'], 'check_id' => (string) $row['check_id'], 'subject' => (string) $row['subject'],
            'detail' => (string) $row['detail'], 'reason' => (string) $row['reason'],
        ];

        if ($ack === null) {
            $ack = AuditAcknowledgement::query()->create([
                'id' => (string) Str::uuid7(Carbon::parse($row['acknowledged_at'])),
                'legacy_id' => $row['id'],
                ...$wanted,
                'acknowledged_by' => isset($users[(int) $row['acknowledged_by']]) ? $users[(int) $row['acknowledged_by']] : null,
                'acknowledged_at' => $row['acknowledged_at'],
            ]);
            $imported['acks']++;
        } else {
            foreach ($wanted as $field => $value) {
                if (trim((string) $ack->getAttribute($field)) !== $value) {
                    throw new ImportDifference(["acknowledgement {$row['id']} ({$field})" => [$value, (string) $ack->getAttribute($field)]]);
                }
            }
        }

        if ($row['revoked_at'] !== null && ! $ack->revocation()->exists()) {
            AuditAcknowledgementRevocation::query()->create([
                'id' => (string) Str::uuid7(Carbon::parse($row['revoked_at'])),
                'acknowledgement_id' => $ack->id,
                'reason' => trim((string) $row['revoke_reason']) === '' ? __('Lifted in the old system (no reason was recorded).') : (string) $row['revoke_reason'],
                'revoked_by' => isset($users[(int) $row['revoked_by']]) ? $users[(int) $row['revoked_by']] : null,
                'revoked_at' => $row['revoked_at'],
            ]);
            $imported['revocations']++;
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<int, string>  $users
     * @param  array<string, int>  $imported
     */
    private function importAudit(array $row, array $users, array &$imported): void
    {
        $audit = Audit::query()->where('legacy_id', $row['id'])->first();
        $ranAt = Carbon::parse($row['ran_at']);

        if ($audit !== null) {
            if ($audit->status !== $row['status'] || $audit->findings_count !== (int) $row['findings_count'] || ! $audit->ran_at->equalTo($ranAt)) {
                throw new ImportDifference(["audit {$row['id']}" => ["{$row['status']} ({$row['findings_count']} findings)", "{$audit->status} ({$audit->findings_count} findings)"]]);
            }

            return;
        }

        $snapshot = json_decode((string) $row['snapshot'], true) ?: [];
        $checks = $this->checks($snapshot['checks'] ?? [], $ranAt);
        $acknowledged = (int) ($snapshot['acknowledgedCount'] ?? array_sum(array_column($checks, 'acknowledged')));

        Audit::query()->create([
            'id' => (string) Str::uuid7($ranAt),
            'legacy_id' => $row['id'],
            'ran_at' => $ranAt,
            'ran_by' => isset($users[(int) $row['ran_by']]) ? $users[(int) $row['ran_by']] : null,
            'status' => $row['status'],
            'findings_count' => (int) $row['findings_count'],
            'critical_count' => (int) $row['critical_count'],
            'acknowledged_count' => $acknowledged,
            'snapshot' => ['checks' => $checks, 'imported' => true],
            'notes' => $row['notes'],
        ]);
        $imported['audits']++;
    }

    /**
     * The old checks in the shape of the new ones, with each finding's fingerprint and, where an
     * acknowledgement of that very finding existed by then, who accepted it and why.
     *
     * @param  list<array<string, mixed>>  $legacyChecks
     * @return list<array<string, mixed>>
     */
    private function checks(array $legacyChecks, Carbon $ranAt): array
    {
        $checks = [];

        foreach ($legacyChecks as $check) {
            $findings = [];
            $acknowledged = 0;

            foreach ($check['findings'] ?? [] as $finding) {
                $fingerprint = (new AuditFinding((string) $finding['subject'], (string) $finding['detail']))->fingerprint((string) $check['id']);
                $ack = AuditAcknowledgement::query()->where('fingerprint', $fingerprint)->where('acknowledged_at', '<=', $ranAt)->orderBy('acknowledged_at')->with('acknowledger')->first();
                $ack !== null && $acknowledged++;
                $findings[] = [
                    'subject' => (string) $finding['subject'],
                    'detail' => (string) $finding['detail'],
                    'fingerprint' => $fingerprint,
                    'acknowledgement' => $ack === null ? null : ['id' => $ack->id, 'reason' => $ack->reason, 'by' => $ack->acknowledger?->name, 'at' => $ack->acknowledged_at->toIso8601String()],
                ];
            }

            $checks[] = [
                'id' => (string) $check['id'],
                'label' => (string) ($check['label'] ?? $check['id']),
                'description' => (string) ($check['description'] ?? ''),
                'severity' => (string) ($check['severity'] ?? 'info'),
                'pending' => count($findings) - $acknowledged,
                'acknowledged' => $acknowledged,
                'findings' => $findings,
            ];
        }

        return $checks;
    }
}

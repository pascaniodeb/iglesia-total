<?php

namespace App\Modules\Integrity\Infrastructure\Audit;

use App\Modules\Integrity\Domain\Audit\AuditCheck;
use App\Modules\Integrity\Domain\Audit\AuditFinding;
use App\Modules\Integrity\Domain\Audit\AuditSeverity;
use App\Modules\Integrity\Domain\Ledger\BlockIssue;
use InvalidArgumentException;

/**
 * The audit's view of the sealed ledger, in three checks: a block that does not match its hash (the
 * chain was rewritten), a period whose books moved after it was sealed (what "an offering after the
 * close" is now: the databases refuse the change, so if it happens behind their back this is where it
 * shows) and a period closed but not sealed yet.
 */
final class SealedLedgerAudit implements AuditCheck
{
    public const array MODES = ['chain', 'drift', 'pending'];

    public function __construct(private readonly SealedLedgerFindings $ledger, private readonly string $mode)
    {
        if (! in_array($mode, self::MODES, true)) {
            throw new InvalidArgumentException("Unknown sealed ledger audit: {$mode}.");
        }
    }

    public function id(): string
    {
        return "sealed-ledger-{$this->mode}";
    }

    public function label(): string
    {
        return match ($this->mode) {
            'chain' => __('Sealed ledger chain'),
            'drift' => __('Books changed after the seal'),
            default => __('Closed periods without a seal'),
        };
    }

    public function description(): string
    {
        return match ($this->mode) {
            'chain' => __('Every block of the sealed ledger is recomputed from the genesis and every link is checked. A difference means a block was rewritten: a security incident.'),
            'drift' => __('The books of every sealed period are rebuilt and compared with what was sealed. A difference means data moved after the close, behind the guards of the database.'),
            default => __('Weeks and months that are closed but do not have their block in the chain yet. Seal them from their closure screen.'),
        };
    }

    public function severity(): AuditSeverity
    {
        return match ($this->mode) {
            'pending' => AuditSeverity::Warning,
            default => AuditSeverity::Critical,
        };
    }

    public function findings(): array
    {
        $result = $this->ledger->result();

        if ($this->mode === 'pending') {
            /** @var list<array{kind: string, period_key: string}> $unsealed */
            $unsealed = $result['unsealed'];

            return array_map(fn (array $period): AuditFinding => new AuditFinding(
                $this->kindLabel($period['kind']).' '.$period['period_key'],
                __('It is closed but its seal is pending.'),
            ), $unsealed);
        }

        /** @var list<BlockIssue> $issues */
        $issues = [...$result['issues'], ...$result['legacy']['issues']];
        $wantsDrift = $this->mode === 'drift';

        return array_values(array_map(
            fn (BlockIssue $issue): AuditFinding => new AuditFinding(
                $issue->periodKey === null ? "#{$issue->height}" : $this->kindLabel($issue->kind).' '.$issue->periodKey,
                $issue->detail(),
            ),
            array_filter($issues, fn (BlockIssue $issue): bool => ($issue->type === BlockIssue::DRIFT) === $wantsDrift),
        ));
    }

    private function kindLabel(string $kind): string
    {
        return match ($kind) {
            'weekly' => __('Week'),
            'monthly' => __('Month'),
            default => __('Block'),
        };
    }
}

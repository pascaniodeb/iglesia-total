<?php

namespace App\Modules\Integrity\Domain\Audit;

/**
 * How much a failed check matters. Critical and warning findings count against the audit; info
 * findings are shown (they teach how the books work) but never make an audit "with findings".
 */
enum AuditSeverity: string
{
    case Critical = 'critical';
    case Warning = 'warning';
    case Info = 'info';

    /** Whether a pending finding of this severity makes the audit "with findings". */
    public function counts(): bool
    {
        return $this !== self::Info;
    }
}

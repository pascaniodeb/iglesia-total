<?php

namespace App\Modules\Integrity\Domain\Audit;

/**
 * One thing a check found: what it is about (a week, a month, an entry) and what is wrong, in the
 * reader's words. Its fingerprint ties an acknowledgement to THIS finding with THESE data.
 */
final readonly class AuditFinding
{
    public function __construct(public string $subject, public string $detail) {}

    /**
     * SHA-256 of the check, the subject and the detail (separated by NUL, as the old system did, so
     * an acknowledgement given there still applies to the same finding here). Change the data and the
     * fingerprint changes: the acknowledgement stops applying by itself.
     */
    public function fingerprint(string $checkId): string
    {
        return hash('sha256', "{$checkId}\0{$this->subject}\0{$this->detail}");
    }
}

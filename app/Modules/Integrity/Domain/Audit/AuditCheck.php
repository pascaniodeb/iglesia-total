<?php

namespace App\Modules\Integrity\Domain\Audit;

/**
 * Port: a module tells the comptroller's audit one thing worth checking in its books. Every check
 * is falsifiable: it recomputes a figure from its source rows and compares it with what the system
 * stored, instead of asking the system whether it agrees with itself. Nothing is cached: an audit
 * that reports yesterday's answer is not an audit.
 */
interface AuditCheck
{
    /** A stable key ("weekly-closure-total"): acknowledgements are tied to it. */
    public function id(): string;

    /** The short name a person reads. */
    public function label(): string;

    /** What the check verifies, in the reader's terms. */
    public function description(): string;

    public function severity(): AuditSeverity;

    /**
     * What breaks it now; empty when it holds.
     *
     * @return list<AuditFinding>
     */
    public function findings(): array;
}

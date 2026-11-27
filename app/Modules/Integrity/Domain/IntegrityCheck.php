<?php

namespace App\Modules\Integrity\Domain;

/**
 * Port: a module tells the integrity center one thing that must always hold in its books (the
 * journal balances, a closure holds exactly its offerings...). The center runs every registered
 * check on demand and lists what breaks; it never knows what a check reads.
 *
 * A check recomputes from the stored rows, independently of the triggers and of the screens: a
 * trigger can be disabled by the table owner, and this cannot be skipped by whoever reads its
 * result.
 */
interface IntegrityCheck
{
    /** A stable key ("journal.balance"): what the history and the tests name the check by. */
    public function id(): string;

    /** The sentence a person reads: what must hold. */
    public function title(): string;

    /**
     * What breaks it, one sentence per finding; an empty list means it holds.
     *
     * @return list<string>
     */
    public function findings(): array;
}

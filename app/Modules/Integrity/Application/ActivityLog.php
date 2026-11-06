<?php

namespace App\Modules\Integrity\Application;

use Illuminate\Database\Eloquent\Model;

/**
 * Public contract of the activity log: the only way any module records a fact.
 *
 * One entry per fact. `$summary` is the sentence a person reads in the log ("Registró al
 * miembro Ana Pérez."), already translated. `$changes` comes from ChangeSet and never holds
 * personal data. Call it inside the same transaction as the write it describes, so the fact
 * and its record are committed or rolled back together.
 */
interface ActivityLog
{
    /**
     * A write to the church's data. Requires a signed-in actor or the console.
     *
     * @param  array<string, mixed>  $changes
     */
    public function record(string $action, string $summary, ?Model $subject = null, array $changes = []): void;

    /**
     * Sign-in, sign-out, failed attempts, password and second-factor events.
     *
     * @param  array<string, mixed>  $details
     */
    public function security(string $action, string $summary, ?Model $subject = null, array $details = []): void;

    /**
     * A read of sensitive material that must be traceable (who opened what).
     *
     * @param  array<string, mixed>  $details
     */
    public function access(string $action, string $summary, ?Model $subject = null, array $details = []): void;

    /**
     * Imports, verifications and commands.
     *
     * @param  array<string, mixed>  $details
     */
    public function system(string $action, string $summary, ?Model $subject = null, array $details = []): void;
}

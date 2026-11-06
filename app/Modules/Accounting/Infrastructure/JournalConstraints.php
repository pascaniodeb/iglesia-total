<?php

namespace App\Modules\Accounting\Infrastructure;

use Illuminate\Support\Facades\DB;

/**
 * The journal's balance rules are deferred constraint triggers, checked at commit. A use case
 * that posts asks for them right away, so an unbalanced entry fails inside the operation that
 * wrote it (with its own error), not later at some outer commit.
 */
final class JournalConstraints
{
    public static function checkNow(): void
    {
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
    }
}

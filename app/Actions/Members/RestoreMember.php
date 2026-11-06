<?php

namespace App\Actions\Members;

use App\Models\Member;

/**
 * Brings a member back from the trash. The marriage link released when they were deleted is
 * not restored: it has to be tied again on purpose.
 */
class RestoreMember
{
    public function __invoke(Member $member): void
    {
        $member->restore();
    }
}

<?php

namespace App\Modules\Members\Application\Actions;

use App\Modules\Members\Application\MemberActivity;
use App\Modules\Members\Infrastructure\Models\Member;
use Illuminate\Support\Facades\DB;

/**
 * Brings a member back from the trash. The marriage link released when they were deleted is
 * not restored: it has to be tied again on purpose.
 */
class RestoreMember
{
    public function __construct(private readonly MemberActivity $activity) {}

    public function __invoke(Member $member): void
    {
        DB::transaction(function () use ($member): void {
            $member->restore();
            $this->activity->restored($member);
        });
    }
}

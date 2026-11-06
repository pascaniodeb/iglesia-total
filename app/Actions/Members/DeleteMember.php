<?php

namespace App\Actions\Members;

use App\Models\Member;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sends a member to the trash.
 *
 * It releases the marriage link on both sides: otherwise the partner would keep showing as
 * spouse someone who is no longer in the registry, and the UNIQUE constraint would keep that
 * person reserved for a deleted record.
 *
 * A member who holds a user account cannot be deleted: a user is a member, so the account
 * would be left belonging to nobody.
 */
class DeleteMember
{
    public function __construct(private readonly LinkSpouse $linkSpouse) {}

    /**
     * @throws ValidationException
     */
    public function __invoke(Member $member): void
    {
        if ($member->user()->exists()) {
            throw ValidationException::withMessages([
                'member' => __('This member has a user account and cannot be deleted.'),
            ]);
        }

        DB::transaction(function () use ($member): void {
            ($this->linkSpouse)($member, null);
            $member->delete();
        });
    }
}

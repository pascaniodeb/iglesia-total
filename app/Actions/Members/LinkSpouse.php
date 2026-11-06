<?php

namespace App\Actions\Members;

use App\Models\Member;
use Illuminate\Validation\ValidationException;

/**
 * Ties (or releases) the marriage link on BOTH sides.
 *
 * A marriage is not a datum of one record but a relation between two. If only the side being
 * edited were written, the spouse's record would keep saying she is single and the family
 * groups built on top of this would come out crooked depending on where you look from.
 *
 * It releases first and ties afterwards, always: spouse_id is UNIQUE, so writing the new link
 * before clearing the old one would collide with the constraint halfway through.
 *
 * If the chosen member is already married to a third person this FAILS instead of silently
 * stealing the partner: unmarrying someone is a decision of whoever runs the registry, not a
 * side effect of editing another record.
 *
 * Must run inside a transaction: it locks the records involved, so two people editing at the
 * same time cannot both claim the same spouse.
 */
class LinkSpouse
{
    /**
     * @throws ValidationException
     */
    public function __invoke(Member $member, ?string $spouseId): void
    {
        $this->lock($member, $spouseId);

        $member->refresh();
        $previous = $member->spouse_id;

        if ($previous === $spouseId) {
            return;
        }

        if ($previous !== null) {
            Member::withTrashed()->whereKey($previous)->update(['spouse_id' => null]);
        }

        $member->forceFill(['spouse_id' => null])->save();

        if ($spouseId === null) {
            return;
        }

        $spouse = $this->eligibleSpouse($member, $spouseId);

        $member->forceFill(['spouse_id' => $spouse->id])->save();
        $spouse->forceFill(['spouse_id' => $member->id])->save();
    }

    /**
     * Locks the member and both spouses in a fixed order, so concurrent edits queue up
     * instead of deadlocking.
     */
    private function lock(Member $member, ?string $spouseId): void
    {
        $ids = array_values(array_unique(array_filter([$member->id, $member->spouse_id, $spouseId])));

        Member::withTrashed()->whereKey($ids)->orderBy('id')->lockForUpdate()->get();
    }

    /**
     * @throws ValidationException
     */
    private function eligibleSpouse(Member $member, string $spouseId): Member
    {
        if ($spouseId === $member->id) {
            $this->fail(__('A member cannot be married to themselves.'));
        }

        $spouse = Member::withTrashed()->find($spouseId);

        if ($spouse === null) {
            $this->fail(__('That member no longer exists.'));
        }

        if ($spouse->trashed()) {
            $this->fail(__('That member is in the trash.'));
        }

        if ($spouse->spouse_id !== null && $spouse->spouse_id !== $member->id) {
            $this->fail(__(':name is already married to someone else. Fix that record first.', ['name' => "«{$spouse->full_name}»"]));
        }

        return $spouse;
    }

    /**
     * @throws ValidationException
     */
    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['spouse_id' => $message]);
    }
}

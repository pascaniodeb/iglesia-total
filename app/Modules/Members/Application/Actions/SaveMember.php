<?php

namespace App\Modules\Members\Application\Actions;

use App\Modules\Members\Infrastructure\Models\Member;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates or updates a member with their children, roles and spouse, all or nothing.
 */
class SaveMember
{
    public function __construct(private readonly LinkSpouse $linkSpouse) {}

    /**
     * @param  array<string, mixed>  $data  validated input of MemberRequest
     *
     * @throws ValidationException
     */
    public function __invoke(?Member $member, array $data): Member
    {
        try {
            return DB::transaction(fn (): Member => $this->save($member ?? new Member, $data));
        } catch (UniqueConstraintViolationException) {
            // Backs up the form's unique rule against two people saving the same document at once.
            throw ValidationException::withMessages([
                'document_id' => __('A member with that document already exists.'),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function save(Member $member, array $data): Member
    {
        // The spouse is tied afterwards by LinkSpouse, which writes both sides of the link.
        $member->fill(Arr::except($data, ['children', 'roles', 'spouse_id']))->save();

        // Children and roles are replaced as a whole: the form sends the complete list.
        $member->children()->delete();
        $member->children()->createMany(Arr::get($data, 'children', []));

        $member->roles()->delete();
        $member->roles()->createMany(Arr::get($data, 'roles', []));

        ($this->linkSpouse)($member, Arr::get($data, 'spouse_id'));

        return $member->refresh();
    }
}

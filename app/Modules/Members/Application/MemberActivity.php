<?php

namespace App\Modules\Members\Application;

use App\Modules\Integrity\Application\ActivityLog;
use App\Modules\Integrity\Domain\ChangeSet;
use App\Modules\Members\Infrastructure\Models\Member;
use Illuminate\Support\Arr;

/**
 * What the members registry writes to the activity log, in one place.
 *
 * Personal data never reaches the log (document, phones, email, birth date, address, the
 * pastor's notes): the entry says that the field changed. Children are minors, so only how
 * many there are is recorded, never their names.
 */
final readonly class MemberActivity
{
    /** @var list<string> */
    public const array SENSITIVE = [
        'document_id', 'phone', 'landline_phone', 'email', 'birth_date', 'address',
        'address_parish', 'address_municipality', 'pastor_notes', 'photo_key',
    ];

    /** @var list<string> */
    public const array IGNORED = ['id', 'legacy_id', 'search_name', 'created_at', 'updated_at', 'deleted_at'];

    public function __construct(private ActivityLog $activityLog) {}

    /**
     * The state of a member as the log compares it: its own columns plus how many children
     * and which roles it has. Empty for a member not saved yet.
     *
     * @return array<string, mixed>
     */
    public function snapshot(Member $member): array
    {
        if (! $member->exists) {
            return [];
        }

        $roles = $member->roles()->orderBy('role')->pluck('role')->all();

        return [
            ...Arr::except($member->getAttributes(), self::IGNORED),
            'children' => $member->children()->count(),
            'roles' => $roles === [] ? null : implode(', ', $roles),
        ];
    }

    /**
     * @param  array<string, mixed>  $before  snapshot taken before the change ([] when new)
     */
    public function saved(Member $member, array $before): void
    {
        $changes = ChangeSet::between($before, $this->snapshot($member), self::SENSITIVE, self::IGNORED);

        if ($before === []) {
            $this->activityLog->record('member.created', __('Registered member :name.', ['name' => $member->full_name]), $member, $changes);

            return;
        }

        if ($changes !== []) {
            $this->activityLog->record('member.updated', __('Updated member :name.', ['name' => $member->full_name]), $member, $changes);
        }
    }

    public function trashed(Member $member): void
    {
        $this->activityLog->record('member.trashed', __('Moved member :name to the trash.', ['name' => $member->full_name]), $member);
    }

    public function restored(Member $member): void
    {
        $this->activityLog->record('member.restored', __('Restored member :name.', ['name' => $member->full_name]), $member);
    }

    /**
     * The other side of a marriage link also changed: it gets its own entry, so every row
     * that was written has a record that explains it.
     */
    public function spouseLinked(Member $spouse, Member $member): void
    {
        $this->activityLog->record(
            'member.spouse_linked',
            __('Recorded the marriage of :name with :spouse.', ['name' => $spouse->full_name, 'spouse' => $member->full_name]),
            $spouse,
            ['spouse_id' => ['from' => null, 'to' => $member->id]],
        );
    }

    public function spouseReleased(Member $partner, Member $member): void
    {
        $this->activityLog->record(
            'member.spouse_released',
            __('Released the marriage of :name with :spouse.', ['name' => $partner->full_name, 'spouse' => $member->full_name]),
            $partner,
            ['spouse_id' => ['from' => $member->id, 'to' => null]],
        );
    }
}

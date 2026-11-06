<?php

namespace App\Modules\Members\Http\Resources;

use App\Modules\Members\Infrastructure\Models\Member;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The full registry card of a member, as the form needs it. Dates travel as YYYY-MM-DD and
 * enums as their stored value.
 *
 * @mixin Member
 */
class MemberResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'full_name' => $this->full_name,
            'document_type' => $this->document_type?->value,
            'document_id' => $this->document_id,
            'sex' => $this->sex?->value,
            'birth_date' => $this->birth_date?->toDateString(),
            'birth_place_city' => $this->birth_place_city,
            'birth_place_state' => $this->birth_place_state,
            'phone' => $this->phone,
            'landline_phone' => $this->landline_phone,
            'email' => $this->email,
            'address_state' => $this->address_state,
            'address_municipality' => $this->address_municipality,
            'address_parish' => $this->address_parish,
            'address' => $this->address,
            'education_level' => $this->education_level?->value,
            'profession' => $this->profession,
            'occupation' => $this->occupation,
            'marital_status' => $this->marital_status?->value,
            'spouse' => $this->spouse === null ? null : [
                'id' => $this->spouse->id,
                'name' => $this->spouse->full_name,
            ],
            'baptism_date' => $this->baptism_date?->toDateString(),
            'baptism_place' => $this->baptism_place,
            'baptism_pastor' => $this->baptism_pastor,
            'holy_spirit_date' => $this->holy_spirit_date?->toDateString(),
            'joined_date' => $this->joined_date?->toDateString(),
            'status' => $this->status?->value,
            'pastor_notes' => $this->pastor_notes,
            'children' => $this->children->map(fn ($child): array => [
                'name' => $child->name,
                'birth_date' => $child->birth_date?->toDateString(),
            ])->all(),
            'roles' => $this->roles->map(fn ($role): array => [
                'role' => $role->role,
                'since' => $role->since?->toDateString(),
                'until' => $role->until?->toDateString(),
            ])->all(),
            'is_deleted' => $this->trashed(),
        ];
    }
}

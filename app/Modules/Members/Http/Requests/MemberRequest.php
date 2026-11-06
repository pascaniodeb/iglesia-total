<?php

namespace App\Modules\Members\Http\Requests;

use App\Modules\Members\Domain\Enums\MemberDocumentType;
use App\Modules\Members\Domain\Enums\MemberEducationLevel;
use App\Modules\Members\Domain\Enums\MemberMaritalStatus;
use App\Modules\Members\Domain\Enums\MemberSex;
use App\Modules\Members\Domain\Enums\MemberStatus;
use App\Enums\Permission;
use App\Modules\Members\Infrastructure\Models\Member;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The full registry card of a member, to create or to update.
 *
 * Only the name is required: the migrated registry is mostly bare names, and a form that demands
 * the document could not save a phone correction on most of the records.
 */
class MemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permission::MembersManage->value) ?? false;
    }

    /**
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        /** @var Member|null $member */
        $member = $this->route('member');

        return [
            'full_name' => ['required', 'string', 'max:150'],
            'document_type' => ['nullable', Rule::enum(MemberDocumentType::class)],
            'document_id' => ['nullable', 'string', 'max:30', Rule::unique('members', 'document_id')->ignore($member?->id)],
            'sex' => ['nullable', Rule::enum(MemberSex::class)],
            'birth_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'birth_place_city' => ['nullable', 'string', 'max:120'],
            'birth_place_state' => ['nullable', 'string', 'max:120'],

            'phone' => ['nullable', 'string', 'max:30'],
            'landline_phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:200'],
            'address_state' => ['nullable', 'string', 'max:120'],
            'address_municipality' => ['nullable', 'string', 'max:120'],
            'address_parish' => ['nullable', 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:400'],

            'education_level' => ['nullable', Rule::enum(MemberEducationLevel::class)],
            'profession' => ['nullable', 'string', 'max:120'],
            'occupation' => ['nullable', 'string', 'max:120'],

            'marital_status' => ['nullable', Rule::enum(MemberMaritalStatus::class)],
            'spouse_id' => ['nullable', 'uuid'],

            'baptism_date' => ['nullable', 'date_format:Y-m-d'],
            'baptism_place' => ['nullable', 'string', 'max:200'],
            'baptism_pastor' => ['nullable', 'string', 'max:120'],
            'holy_spirit_date' => ['nullable', 'date_format:Y-m-d'],
            'joined_date' => ['nullable', 'date_format:Y-m-d'],
            'status' => ['nullable', Rule::enum(MemberStatus::class)],
            'pastor_notes' => ['nullable', 'string', 'max:2000'],

            'children' => ['array', 'max:30'],
            'children.*.name' => ['required', 'string', 'max:150'],
            'children.*.birth_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],

            'roles' => ['array', 'max:30'],
            'roles.*.role' => ['required', 'string', 'max:120'],
            'roles.*.since' => ['nullable', 'date_format:Y-m-d'],
            'roles.*.until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:roles.*.since'],
        ];
    }
}

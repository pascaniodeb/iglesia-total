<?php

namespace App\Modules\Treasury\Http\Requests;

use App\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The shape of an offering form; the business rules (no future dates, active category,
 * registry member, at least one amount) live in SaveOffering.
 */
class OfferingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permission::OfferingsManage->value) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'offering_date' => ['required', 'date_format:Y-m-d'],
            'offering_category_id' => ['required', 'uuid'],
            'member_id' => ['nullable', 'uuid'],
            'concept' => ['nullable', 'string', 'max:200'],
            'amount_ves' => ['nullable', 'string', 'max:20'],
            'amount_usd' => ['nullable', 'string', 'max:20'],
            'amount_cop' => ['nullable', 'string', 'max:20'],
        ];
    }
}

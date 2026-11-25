<?php

namespace App\Modules\Treasury\Http\Requests;

use App\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The shape of a tithe form; the business rules (no future dates, member of the registry, at
 * least one amount) live in SaveTithe.
 */
class TitheRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permission::TithesManage->value) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'tithe_date' => ['required', 'date_format:Y-m-d'],
            'member_id' => ['required', 'uuid'],
            'notes' => ['nullable', 'string', 'max:500'],
            'amount_ves' => ['nullable', 'string', 'max:20'],
            'amount_usd' => ['nullable', 'string', 'max:20'],
            'amount_cop' => ['nullable', 'string', 'max:20'],
        ];
    }
}

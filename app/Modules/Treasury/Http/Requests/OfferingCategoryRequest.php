<?php

namespace App\Modules\Treasury\Http\Requests;

use App\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;

class OfferingCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permission::CatalogsManage->value) ?? false;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'account_id' => ['required', 'uuid'],
        ];
    }
}

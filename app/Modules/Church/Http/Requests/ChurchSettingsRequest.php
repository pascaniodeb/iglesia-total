<?php

namespace App\Modules\Church\Http\Requests;

use App\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;

class ChurchSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permission::ChurchSettingsManage->value) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(collect([
            'name', 'pastor', 'code', 'rif', 'denomination', 'headquarters', 'registration_note', 'denomination_rif',
        ])->filter(fn (string $field): bool => is_string($this->input($field)))
            ->map(fn (string $field): string => trim((string) $this->input($field)))
            ->all());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'pastor' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:100'],
            'rif' => ['required', 'string', 'max:100'],
            'denomination' => ['required', 'string', 'max:255'],
            'headquarters' => ['required', 'string', 'max:255'],
            'registration_note' => ['required', 'string', 'max:2000'],
            'denomination_rif' => ['required', 'string', 'max:100'],
            // SVG is deliberately excluded: active SVG content needs an approved sanitizer before serving.
            'logo_light' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'extensions:jpg,jpeg,png,webp', 'max:4096'],
            'logo_dark' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'extensions:jpg,jpeg,png,webp', 'max:4096'],
            'favicon' => ['nullable', 'file', 'mimes:png,ico', 'extensions:png,ico', 'max:1024'],
        ];
    }
}

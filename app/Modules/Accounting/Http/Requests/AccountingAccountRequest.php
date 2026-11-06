<?php

namespace App\Modules\Accounting\Http\Requests;

use App\Enums\Permission;
use App\Modules\Accounting\Infrastructure\Models\AccountingAccount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Creating an account takes its code and name; editing one only its name (the code is fixed).
 */
class AccountingAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permission::CatalogsManage->value) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'code' => is_string($this->input('code')) ? mb_strtoupper(trim($this->input('code'))) : $this->input('code'),
            'name' => is_string($this->input('name')) ? mb_strtoupper(trim($this->input('name'))) : $this->input('name'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $account = $this->route('account');
        $isNew = ! $account instanceof AccountingAccount;

        return [
            'code' => $isNew
                ? ['required', 'string', 'regex:/^[IE]-[0-9]{3}$/', Rule::unique('accounting_accounts', 'code')]
                : ['prohibited'],
            'name' => ['required', 'string', 'max:160'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.regex' => __('The code must start with I- (income) or E- (expense) followed by three digits.'),
        ];
    }
}

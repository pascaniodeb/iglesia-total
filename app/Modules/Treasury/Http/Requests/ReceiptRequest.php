<?php

namespace App\Modules\Treasury\Http\Requests;

use App\Enums\Permission;
use App\Modules\Treasury\Domain\Receipts\PaymentMethod;
use App\Modules\Treasury\Domain\Receipts\ReceiptType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The shape of a receipt form; the business rules (open month, no overdraft, account of the
 * receipt's type, at least one amount) live in IssueReceipt. The number is never part of the form:
 * the database assigns it.
 */
class ReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permission::AccountingManage->value) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(ReceiptType::class)],
            'issued_on' => ['required', 'date_format:Y-m-d'],
            'account_id' => ['required', 'uuid'],
            'holder' => ['nullable', 'string', 'max:160'],
            'counterparty' => ['nullable', 'string', 'max:160'],
            'amount_ves' => ['nullable', 'string', 'max:20'],
            'amount_usd' => ['nullable', 'string', 'max:20'],
            'amount_cop' => ['nullable', 'string', 'max:20'],
            'concept' => ['nullable', 'string', 'max:1000'],
            'payment_method' => ['required', Rule::enum(PaymentMethod::class)],
            'bank' => ['nullable', 'string', 'max:120'],
            'payment_reference' => ['nullable', 'string', 'max:120'],
            'payment_date' => ['nullable', 'date_format:Y-m-d'],
            'received_by_name' => ['nullable', 'string', 'max:160'],
            'received_by_id_number' => ['nullable', 'string', 'max:40'],
            'delivered_by_name' => ['nullable', 'string', 'max:160'],
            'delivered_by_id_number' => ['nullable', 'string', 'max:40'],
            'token' => ['required', 'uuid'],
        ];
    }
}

<?php

namespace App\Modules\Treasury\Infrastructure\Models;

use App\Modules\Treasury\Domain\Receipts\PaymentMethod;
use App\Modules\Treasury\Domain\Receipts\ReceiptContent;
use App\Modules\Treasury\Domain\Receipts\ReceiptType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * A receipt of the IPUV book. Insert-only: what was issued never changes; voiding is a
 * ReceiptVoid. The number is assigned by the database when the row is inserted, so read it after
 * saving (refresh). Its account belongs to Accounting and is read through the ChartOfAccounts
 * contract. Rows are born only through IssueReceipt (they need their posted entry), so there is
 * no factory.
 *
 * @property string $id UUIDv7
 * @property int $number
 * @property ReceiptType $type
 * @property Carbon $issued_on
 * @property string $account_id
 * @property string|null $holder
 * @property string|null $counterparty
 * @property string $amount_ves
 * @property string $amount_usd
 * @property string $amount_cop
 * @property string|null $concept
 * @property PaymentMethod $payment_method
 * @property string|null $bank
 * @property string|null $payment_reference
 * @property Carbon|null $payment_date
 * @property string|null $received_by_name
 * @property string|null $received_by_id_number
 * @property string|null $delivered_by_name
 * @property string|null $delivered_by_id_number
 * @property string $journal_entry_id
 * @property string|null $created_by
 * @property Carbon $created_at
 * @property-read ReceiptVoid|null $void
 */
class Receipt extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    /**
     * What the receipt says on paper, as the monthly seal binds it.
     */
    public function content(): ReceiptContent
    {
        return new ReceiptContent(
            number: $this->number,
            type: $this->type,
            issuedOn: $this->issued_on->toDateString(),
            accountId: $this->account_id,
            holder: $this->holder,
            counterparty: $this->counterparty,
            amountVes: $this->amount_ves,
            amountUsd: $this->amount_usd,
            amountCop: $this->amount_cop,
            concept: $this->concept,
            paymentMethod: $this->payment_method,
            bank: $this->bank,
            paymentReference: $this->payment_reference,
            paymentDate: $this->payment_date?->toDateString(),
            receivedByName: $this->received_by_name,
            receivedByIdNumber: $this->received_by_id_number,
            deliveredByName: $this->delivered_by_name,
            deliveredByIdNumber: $this->delivered_by_id_number,
        );
    }

    /**
     * @return HasOne<ReceiptVoid, $this>
     */
    public function void(): HasOne
    {
        return $this->hasOne(ReceiptVoid::class);
    }

    public function isVoid(): bool
    {
        return $this->void !== null;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'type' => ReceiptType::class,
            'payment_method' => PaymentMethod::class,
            'issued_on' => 'date',
            'payment_date' => 'date',
        ];
    }
}

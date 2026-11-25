<?php

namespace App\Modules\Treasury\Http\Tables;

use App\Modules\Accounting\Domain\AccountRef;
use App\Modules\Shared\Tables\TableQuery;
use App\Modules\Treasury\Domain\Receipts\ReceiptNumber;
use App\Modules\Treasury\Domain\Receipts\ReceiptType;
use App\Modules\Treasury\Infrastructure\Models\Receipt;
use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * The receipt book: newest number first, filtered by type, state (issued or void) and month,
 * searchable by number, holder, counterparty or concept.
 */
final class ReceiptListing
{
    public const string DEFAULT_SORT = 'number';

    /**
     * @return array<string, string>
     */
    public static function sortable(): array
    {
        return [
            'number' => 'receipts.number',
            'date' => 'receipts.issued_on',
            'ves' => 'receipts.amount_ves',
            'usd' => 'receipts.amount_usd',
            'cop' => 'receipts.amount_cop',
        ];
    }

    /**
     * @return list<literal-string|Closure(Builder<Receipt>, string): mixed>
     */
    public static function searchable(): array
    {
        return [
            'receipts.holder',
            'receipts.counterparty',
            'receipts.concept',
            fn (Builder $query, string $pattern) => $query->whereRaw('receipts.number::text like ?', [$pattern]),
        ];
    }

    /**
     * @return array<string, Closure(string, array<string, string|null>): bool>
     */
    public static function facets(): array
    {
        return [
            'type' => fn (string $value) => ReceiptType::tryFrom($value) !== null,
            'state' => fn (string $value) => in_array($value, ['issued', 'void'], true),
            'month' => fn (string $value) => preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value) === 1,
        ];
    }

    /**
     * @return Builder<Receipt>
     */
    public static function base(TableQuery $table): Builder
    {
        return Receipt::query()
            ->when($table->facet('type'), fn (Builder $query, string $type) => $query->where('receipts.type', $type))
            ->when($table->facet('state'), fn (Builder $query, string $state) => $state === 'void'
                ? $query->whereExists(fn ($void) => $void->selectRaw('1')->from('receipt_voids')->whereColumn('receipt_voids.receipt_id', 'receipts.id'))
                : $query->whereNotExists(fn ($void) => $void->selectRaw('1')->from('receipt_voids')->whereColumn('receipt_voids.receipt_id', 'receipts.id')))
            ->when($table->facet('month'), fn (Builder $query, string $month) => $query->whereRaw("to_char(receipts.issued_on, 'YYYY-MM') = ?", [$month]));
    }

    /**
     * @param  array<string, AccountRef>  $accounts  by id
     * @return array<string, mixed>
     */
    public static function row(Receipt $receipt, array $accounts): array
    {
        $account = $accounts[$receipt->account_id] ?? null;

        return [
            'number' => $receipt->number,
            'number_label' => ReceiptNumber::format($receipt->number),
            'issued_on' => $receipt->issued_on->toDateString(),
            'type' => $receipt->type->value,
            'type_label' => $receipt->type->label(),
            'account_code' => $account?->code,
            'account_name' => $account?->name,
            'holder' => $receipt->holder,
            'counterparty' => $receipt->counterparty,
            'concept' => $receipt->concept,
            'amount_ves' => $receipt->amount_ves,
            'amount_usd' => $receipt->amount_usd,
            'amount_cop' => $receipt->amount_cop,
            'is_void' => $receipt->void !== null,
        ];
    }
}

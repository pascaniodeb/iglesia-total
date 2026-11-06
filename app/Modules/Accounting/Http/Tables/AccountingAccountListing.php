<?php

namespace App\Modules\Accounting\Http\Tables;

use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Infrastructure\Models\AccountingAccount;
use App\Modules\Shared\Tables\TableQuery;
use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * The chart of accounts listing: by code, searchable by code or name, filtered by type and by
 * active/inactive.
 */
final class AccountingAccountListing
{
    public const string DEFAULT_SORT = 'code';

    /**
     * @return array<string, string>
     */
    public static function sortable(): array
    {
        return [
            'code' => 'accounting_accounts.code',
            'name' => 'accounting_accounts.name',
            'type' => 'accounting_accounts.type',
        ];
    }

    /**
     * @return list<Closure(Builder<AccountingAccount>, string): mixed>
     */
    public static function searchable(): array
    {
        return [
            fn (Builder $query, string $pattern) => $query->where('accounting_accounts.search_text', 'like', $pattern),
        ];
    }

    /**
     * @return array<string, Closure(string, array<string, string|null>): bool>
     */
    public static function facets(): array
    {
        return [
            'type' => fn (string $value) => AccountType::tryFrom($value) !== null,
        ];
    }

    /**
     * @return Builder<AccountingAccount>
     */
    public static function base(TableQuery $table): Builder
    {
        return AccountingAccount::query()
            ->when($table->facet('type'), fn (Builder $query, string $type) => $query->where('accounting_accounts.type', $type));
    }

    /**
     * @return array<string, mixed>
     */
    public static function row(AccountingAccount $account): array
    {
        return [
            'id' => $account->id,
            'code' => $account->code,
            'name' => $account->name,
            'type' => $account->type->value,
            'type_label' => $account->type->label(),
            'is_active' => $account->is_active,
            'is_system' => $account->isSystem(),
        ];
    }
}

<?php

namespace App\Modules\Accounting\Infrastructure;

use App\Modules\Accounting\Application\ChartOfAccounts;
use App\Modules\Accounting\Domain\AccountRef;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Infrastructure\Models\AccountingAccount;
use Illuminate\Support\Str;

/**
 * The ChartOfAccounts contract backed by the accounting_accounts table.
 */
final class DatabaseChartOfAccounts implements ChartOfAccounts
{
    public function find(string $id): ?AccountRef
    {
        if (! Str::isUuid($id)) {
            return null;
        }

        $account = AccountingAccount::query()->find($id);

        return $account === null ? null : self::ref($account);
    }

    public function findMany(array $ids): array
    {
        $ids = array_values(array_filter(array_unique($ids), Str::isUuid(...)));

        if ($ids === []) {
            return [];
        }

        return AccountingAccount::query()
            ->whereKey($ids)
            ->get()
            ->mapWithKeys(fn (AccountingAccount $account) => [$account->id => self::ref($account)])
            ->all();
    }

    public function activeIncomeAccounts(): array
    {
        return array_values(AccountingAccount::query()
            ->where('type', AccountType::Income->value)
            ->where('is_active', true)
            ->orderedByCode()
            ->get()
            ->map(self::ref(...))
            ->all());
    }

    private static function ref(AccountingAccount $account): AccountRef
    {
        return new AccountRef($account->id, $account->code, $account->name, $account->type, $account->is_active);
    }
}

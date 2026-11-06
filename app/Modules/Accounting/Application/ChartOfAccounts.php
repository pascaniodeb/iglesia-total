<?php

namespace App\Modules\Accounting\Application;

use App\Modules\Accounting\Domain\AccountRef;

/**
 * Public contract of the chart of accounts for other modules (Treasury maps each offering
 * category to an income account through it). Read-only.
 */
interface ChartOfAccounts
{
    public function find(string $id): ?AccountRef;

    /**
     * Several accounts in one read, keyed by id (unknown ids are left out).
     *
     * @param  list<string>  $ids
     * @return array<string, AccountRef>
     */
    public function findMany(array $ids): array;

    /**
     * Active income accounts that people can choose (the opening balance account is excluded),
     * ordered by code.
     *
     * @return list<AccountRef>
     */
    public function activeIncomeAccounts(): array;
}

<?php

namespace App\Modules\Accounting\Application\Actions;

use App\Modules\Accounting\Infrastructure\Models\AccountingAccount;
use App\Modules\Integrity\Application\ActivityLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Deactivates or reactivates an account. An inactive account keeps its history (it is never
 * deleted) but no new movement can be posted to it. System accounts are always active.
 */
final readonly class SetAccountingAccountActive
{
    public function __construct(private ActivityLog $activityLog) {}

    /**
     * @throws ValidationException
     */
    public function __invoke(AccountingAccount $account, bool $isActive): AccountingAccount
    {
        if ($account->isSystem()) {
            throw ValidationException::withMessages(['account' => __('System accounts cannot be deactivated.')]);
        }

        if ($account->is_active === $isActive) {
            return $account;
        }

        return DB::transaction(function () use ($account, $isActive): AccountingAccount {
            $account->forceFill(['is_active' => $isActive])->save();

            $this->activityLog->record(
                $isActive ? 'accounting_account.activated' : 'accounting_account.deactivated',
                $isActive
                    ? __('Reactivated account :code :name.', ['code' => $account->code, 'name' => $account->name])
                    : __('Deactivated account :code :name.', ['code' => $account->code, 'name' => $account->name]),
                $account,
                ['is_active' => ['from' => ! $isActive, 'to' => $isActive]],
            );

            return $account;
        });
    }
}

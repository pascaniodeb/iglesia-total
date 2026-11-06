<?php

namespace App\Modules\Accounting\Application\Actions;

use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Infrastructure\Models\AccountingAccount;
use App\Modules\Integrity\Application\ActivityLog;
use App\Modules\Integrity\Domain\ChangeSet;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates an income or expense account, or renames an existing one.
 *
 * The code decides the type (I- income, E- expense) and is fixed at creation: a code that
 * changed meaning would change what every past report says. System accounts (CAJA, SALDO
 * ANTERIOR, PUENTE CAMBIARIO) are not edited from here.
 */
final readonly class SaveAccountingAccount
{
    private const array LOGGED = ['code', 'name', 'type', 'is_active'];

    public function __construct(private ActivityLog $activityLog) {}

    /**
     * @param  array{code?: string, name: string}  $data  validated input
     *
     * @throws ValidationException
     */
    public function __invoke(?AccountingAccount $account, array $data): AccountingAccount
    {
        if ($account?->isSystem()) {
            throw ValidationException::withMessages(['name' => __('System accounts cannot be edited.')]);
        }

        try {
            return DB::transaction(fn (): AccountingAccount => $account === null
                ? $this->create($data)
                : $this->rename($account, $data['name']));
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['code' => __('An account with that code already exists.')]);
        }
    }

    /**
     * @param  array{code?: string, name: string}  $data
     */
    private function create(array $data): AccountingAccount
    {
        $code = (string) ($data['code'] ?? '');
        $type = AccountType::forCode($code)
            ?? throw ValidationException::withMessages(['code' => __('The code must start with I- (income) or E- (expense).')]);

        $account = new AccountingAccount;
        $account->forceFill(['code' => $code, 'name' => $data['name'], 'type' => $type, 'is_active' => true])->save();

        $this->activityLog->record(
            'accounting_account.created',
            __('Created account :code :name.', ['code' => $account->code, 'name' => $account->name]),
            $account,
            ChangeSet::between([], Arr::only($account->getAttributes(), self::LOGGED)),
        );

        return $account;
    }

    private function rename(AccountingAccount $account, string $name): AccountingAccount
    {
        $before = Arr::only($account->getAttributes(), self::LOGGED);
        $account->fill(['name' => $name])->save();
        $changes = ChangeSet::between($before, Arr::only($account->getAttributes(), self::LOGGED));

        if ($changes !== []) {
            $this->activityLog->record(
                'accounting_account.updated',
                __('Updated account :code :name.', ['code' => $account->code, 'name' => $account->name]),
                $account,
                $changes,
            );
        }

        return $account;
    }
}

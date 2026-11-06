<?php

namespace App\Modules\Treasury\Application\Actions;

use App\Modules\Accounting\Application\ChartOfAccounts;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Integrity\Application\ActivityLog;
use App\Modules\Integrity\Domain\ChangeSet;
use App\Modules\Treasury\Infrastructure\Models\OfferingCategory;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates or edits an offering category: its name and the income account its offerings go to.
 *
 * Changing the account only affects offerings posted from now on: what was already posted to
 * the journal never moves.
 */
final readonly class SaveOfferingCategory
{
    private const array LOGGED = ['name', 'account_id', 'is_active'];

    public function __construct(private ChartOfAccounts $chart, private ActivityLog $activityLog) {}

    /**
     * @param  array{name: string, account_id: string}  $data  validated input
     *
     * @throws ValidationException
     */
    public function __invoke(?OfferingCategory $category, array $data): OfferingCategory
    {
        $account = $this->chart->find($data['account_id']);

        if ($account === null || $account->type !== AccountType::Income || ! $account->isActive) {
            throw ValidationException::withMessages(['account_id' => __('Choose an active income account.')]);
        }

        try {
            return DB::transaction(function () use ($category, $data, $account): OfferingCategory {
                $isNew = $category === null;
                $category ??= new OfferingCategory;
                $before = $isNew ? [] : Arr::only($category->getAttributes(), self::LOGGED);

                $category->fill(['name' => $data['name'], 'account_id' => $account->id]);
                $category->is_active = $isNew ? true : $category->is_active;
                $category->save();

                $changes = ChangeSet::between($before, Arr::only($category->getAttributes(), self::LOGGED));

                if ($isNew || $changes !== []) {
                    $this->activityLog->record(
                        $isNew ? 'offering_category.created' : 'offering_category.updated',
                        $isNew
                            ? __('Created the offering category :name (:code).', ['name' => $category->name, 'code' => $account->code])
                            : __('Updated the offering category :name (:code).', ['name' => $category->name, 'code' => $account->code]),
                        $category,
                        $changes,
                    );
                }

                return $category;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => __('A category with that name already exists.')]);
        }
    }
}

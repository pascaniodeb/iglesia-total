<?php

namespace App\Modules\Treasury\Application\Actions;

use App\Modules\Integrity\Application\ActivityLog;
use App\Modules\Treasury\Infrastructure\Models\OfferingCategory;
use Illuminate\Support\Facades\DB;

/**
 * Deactivates or reactivates a category. An inactive category keeps its offerings but is not
 * offered for new ones.
 */
final readonly class SetOfferingCategoryActive
{
    public function __construct(private ActivityLog $activityLog) {}

    public function __invoke(OfferingCategory $category, bool $isActive): OfferingCategory
    {
        if ($category->is_active === $isActive) {
            return $category;
        }

        return DB::transaction(function () use ($category, $isActive): OfferingCategory {
            $category->forceFill(['is_active' => $isActive])->save();

            $this->activityLog->record(
                $isActive ? 'offering_category.activated' : 'offering_category.deactivated',
                $isActive
                    ? __('Reactivated the offering category :name.', ['name' => $category->name])
                    : __('Deactivated the offering category :name.', ['name' => $category->name]),
                $category,
                ['is_active' => ['from' => ! $isActive, 'to' => $isActive]],
            );

            return $category;
        });
    }
}

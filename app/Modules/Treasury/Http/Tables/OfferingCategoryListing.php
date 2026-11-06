<?php

namespace App\Modules\Treasury\Http\Tables;

use App\Modules\Accounting\Domain\AccountRef;
use App\Modules\Treasury\Infrastructure\Models\OfferingCategory;
use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * The offering categories listing. The account names come from Accounting through its
 * contract, for the rows of the page only.
 */
final class OfferingCategoryListing
{
    public const string DEFAULT_SORT = 'name';

    /**
     * @return array<string, string>
     */
    public static function sortable(): array
    {
        return ['name' => 'offering_categories.name'];
    }

    /**
     * @return list<Closure(Builder<OfferingCategory>, string): mixed>
     */
    public static function searchable(): array
    {
        return [
            fn (Builder $query, string $pattern) => $query->whereRaw('lower(immutable_unaccent(offering_categories.name)) like ?', [$pattern]),
        ];
    }

    /**
     * @return Builder<OfferingCategory>
     */
    public static function base(): Builder
    {
        return OfferingCategory::query();
    }

    /**
     * @param  array<string, AccountRef>  $accounts  by id
     * @return array<string, mixed>
     */
    public static function row(OfferingCategory $category, array $accounts): array
    {
        $account = $accounts[$category->account_id] ?? null;

        return [
            'id' => $category->id,
            'name' => $category->name,
            'account_id' => $category->account_id,
            'account_code' => $account?->code,
            'account_name' => $account?->name,
            'is_active' => $category->is_active,
        ];
    }
}

<?php

namespace App\Modules\Treasury\Http\Tables;

use App\Modules\Members\Domain\MemberRef;
use App\Modules\Shared\Tables\TableQuery;
use App\Modules\Treasury\Infrastructure\Models\Offering;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * The offerings registry: newest first, filtered by month and category, searchable by the
 * giver's name (through the Members directory) or the concept.
 */
final class OfferingListing
{
    public const string DEFAULT_SORT = 'date';

    /**
     * @return array<string, string>
     */
    public static function sortable(): array
    {
        return [
            'date' => 'offerings.offering_date',
            'ves' => 'offerings.amount_ves',
            'usd' => 'offerings.amount_usd',
            'cop' => 'offerings.amount_cop',
        ];
    }

    /**
     * @param  list<string>  $memberIds  ids of the members whose name matches the search
     * @return list<Closure(Builder<Offering>, string): mixed>
     */
    public static function searchable(array $memberIds): array
    {
        return [
            fn (Builder $query, string $pattern) => $query->whereRaw('lower(immutable_unaccent(offerings.concept)) like ?', [$pattern]),
            fn (Builder $query) => $query->whereIn('offerings.member_id', $memberIds === [] ? ['00000000-0000-0000-0000-000000000000'] : $memberIds),
        ];
    }

    /**
     * @return array<string, Closure(string, array<string, string|null>): bool>
     */
    public static function facets(): array
    {
        return [
            'month' => fn (string $value) => preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value) === 1,
            'category' => fn (string $value) => Str::isUuid($value),
        ];
    }

    /**
     * @return Builder<Offering>
     */
    public static function base(TableQuery $table): Builder
    {
        return Offering::query()
            ->when($table->facet('month'), fn (Builder $query, string $month) => $query->where('offerings.month_key', $month))
            ->when($table->facet('category'), fn (Builder $query, string $category) => $query->where('offerings.offering_category_id', $category));
    }

    /**
     * Count and totals per currency of what the filters select (all pages).
     *
     * @param  Builder<Offering>  $filtered
     * @return array{count: int, ves: string, usd: string, cop: string}
     */
    public static function totals(Builder $filtered): array
    {
        $row = $filtered->toBase()
            ->selectRaw('count(*) AS n, coalesce(sum(offerings.amount_ves), 0)::numeric(18,2)::text AS ves, coalesce(sum(offerings.amount_usd), 0)::numeric(18,2)::text AS usd, coalesce(sum(offerings.amount_cop), 0)::numeric(18,2)::text AS cop')
            ->first();

        return [
            'count' => (int) ($row->n ?? 0),
            'ves' => (string) ($row->ves ?? '0.00'),
            'usd' => (string) ($row->usd ?? '0.00'),
            'cop' => (string) ($row->cop ?? '0.00'),
        ];
    }

    /**
     * @param  array<string, MemberRef>  $members  by id
     * @param  array<string, int>  $closedWeeks  week keys of the page that are closed (as keys)
     * @return array<string, mixed>
     */
    public static function row(Offering $offering, array $members, array $closedWeeks = []): array
    {
        $member = $offering->member_id === null ? null : ($members[$offering->member_id] ?? null);

        return [
            'id' => $offering->id,
            'offering_date' => $offering->offering_date->toDateString(),
            'week_key' => $offering->week_key,
            'member' => $member?->toOption(),
            'category_id' => $offering->offering_category_id,
            'category' => $offering->category->name,
            'concept' => $offering->concept,
            'amount_ves' => $offering->amount_ves,
            'amount_usd' => $offering->amount_usd,
            'amount_cop' => $offering->amount_cop,
            'is_deleted' => $offering->trashed(),
            'is_locked' => isset($closedWeeks[$offering->week_key]),
        ];
    }
}

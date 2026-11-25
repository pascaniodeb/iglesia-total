<?php

namespace App\Modules\Treasury\Http\Tables;

use App\Modules\Members\Domain\MemberRef;
use App\Modules\Shared\Tables\TableQuery;
use App\Modules\Treasury\Infrastructure\Models\Tithe;
use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * The tithes book: newest first, filtered by month, searchable by the member's name (through the
 * Members directory) or the notes.
 */
final class TitheListing
{
    public const string DEFAULT_SORT = 'date';

    /**
     * @return array<string, string>
     */
    public static function sortable(): array
    {
        return [
            'date' => 'tithes.tithe_date',
            'ves' => 'tithes.amount_ves',
            'usd' => 'tithes.amount_usd',
            'cop' => 'tithes.amount_cop',
        ];
    }

    /**
     * @param  list<string>  $memberIds  ids of the members whose name matches the search
     * @return list<Closure(Builder<Tithe>, string): mixed>
     */
    public static function searchable(array $memberIds): array
    {
        return [
            fn (Builder $query, string $pattern) => $query->whereRaw('lower(immutable_unaccent(tithes.notes)) like ?', [$pattern]),
            fn (Builder $query) => $query->whereIn('tithes.member_id', $memberIds === [] ? ['00000000-0000-0000-0000-000000000000'] : $memberIds),
        ];
    }

    /**
     * @return array<string, Closure(string, array<string, string|null>): bool>
     */
    public static function facets(): array
    {
        return [
            'month' => fn (string $value) => preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value) === 1,
        ];
    }

    /**
     * @return Builder<Tithe>
     */
    public static function base(TableQuery $table): Builder
    {
        return Tithe::query()
            ->when($table->facet('month'), fn (Builder $query, string $month) => $query->where('tithes.month_key', $month));
    }

    /**
     * Count and totals per currency of what the filters select (all pages).
     *
     * @param  Builder<Tithe>  $filtered
     * @return array{count: int, ves: string, usd: string, cop: string}
     */
    public static function totals(Builder $filtered): array
    {
        $row = $filtered->toBase()
            ->selectRaw('count(*) AS n, coalesce(sum(tithes.amount_ves), 0)::numeric(18,2)::text AS ves, coalesce(sum(tithes.amount_usd), 0)::numeric(18,2)::text AS usd, coalesce(sum(tithes.amount_cop), 0)::numeric(18,2)::text AS cop')
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
     * @return array<string, mixed>
     */
    public static function row(Tithe $tithe, array $members): array
    {
        return [
            'id' => $tithe->id,
            'tithe_date' => $tithe->tithe_date->toDateString(),
            'member' => ($members[$tithe->member_id] ?? null)?->toOption(),
            'notes' => $tithe->notes,
            'amount_ves' => $tithe->amount_ves,
            'amount_usd' => $tithe->amount_usd,
            'amount_cop' => $tithe->amount_cop,
            'is_deleted' => $tithe->trashed(),
        ];
    }
}

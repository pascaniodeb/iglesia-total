<?php

namespace App\Http\Tables;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Database\Query\Expression;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Str;

/**
 * Parameters of a server-paginated listing: search, status, order and page size.
 *
 * Everything comes from whitelists: an unknown value in the URL falls back to the default
 * instead of failing, so a tampered link never reaches the SQL. Each listing's own filters
 * (facets) are validated with a function.
 *
 * The status filter understands soft deletes: "active" are the records not in the trash,
 * "inactive" are the ones in the trash and "all" is both. Models without soft deletes use
 * their `is_active` column instead.
 *
 * Performance: the base query only filters (no joins) so the count and the page of ids come
 * from the indexes; presentation data (joins, counts) is added afterwards, only for the rows of
 * the page.
 */
final readonly class TableQuery
{
    public const array PER_PAGE_OPTIONS = [15, 25, 50];

    public const array STATUSES = ['all', 'active', 'inactive'];

    private const int SEARCH_MAX_LENGTH = 100;

    /**
     * @param  string|Expression<covariant literal-string>  $sortColumn
     * @param  'asc'|'desc'  $direction
     * @param  array<string, string|null>  $facets
     */
    private function __construct(
        public string $search,
        public string $status,
        public string $sort,
        private string|Expression $sortColumn,
        public string $direction,
        public int $perPage,
        private array $facets,
    ) {}

    /**
     * @param  array<string, string|Expression<covariant literal-string>>  $sortable  public key => qualified column or literal expression
     * @param  array<string, Closure(string, array<string, string|null>): bool>  $facets  key => validator; receives the facets already accepted
     * @param  'asc'|'desc'  $defaultDirection
     * @param  bool  $withStatus  false for tables with no status: it stays on "all"
     * @param  'all'|'active'  $defaultStatus  status when the URL has none
     */
    public static function fromRequest(
        Request $request,
        array $sortable,
        string $defaultSort,
        array $facets = [],
        string $defaultDirection = 'asc',
        bool $withStatus = true,
        string $defaultStatus = 'all',
    ): self {
        $pick = fn (string $key, array $allowed, mixed $default): mixed => in_array($request->query($key), $allowed, true)
            ? $request->query($key)
            : $default;

        $sort = $pick('sort', array_keys($sortable), $defaultSort);

        $accepted = [];
        foreach ($facets as $key => $isValid) {
            $value = $request->query($key);
            $accepted[$key] = is_string($value) && $value !== 'all' && $isValid($value, $accepted) ? $value : null;
        }

        return new self(
            search: mb_substr(trim($request->string('search')->toString()), 0, self::SEARCH_MAX_LENGTH),
            status: $withStatus ? $pick('status', self::STATUSES, $defaultStatus) : 'all',
            sort: $sort,
            sortColumn: $sortable[$sort],
            direction: in_array($request->query('direction'), ['asc', 'desc'], true) ? $request->query('direction') : $defaultDirection,
            perPage: (int) $pick('per_page', array_map('strval', self::PER_PAGE_OPTIONS), self::PER_PAGE_OPTIONS[0]),
            facets: $accepted,
        );
    }

    /**
     * Accepted value of a facet, or null when it is not filtered.
     */
    public function facet(string $key): ?string
    {
        return $this->facets[$key] ?? null;
    }

    /**
     * Applies the search and the status (what paginate() does): to export exactly what the
     * listing shows, without paging.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  list<literal-string|Closure(Builder<TModel>, string): mixed>  $searchable  accent-insensitive
     *                                                                                    columns, or a condition that receives the ready pattern
     * @return Builder<TModel>
     */
    public function filter(Builder $query, array $searchable): Builder
    {
        $model = $query->getModel();
        $table = $model->getTable();

        if ($this->search !== '') {
            // ASCII and lowercase on the pattern: the generated search columns hold the same.
            $pattern = '%'.addcslashes(Str::lower(Str::ascii($this->search)), '\\%_').'%';

            $query->where(function (Builder $query) use ($searchable, $pattern) {
                foreach ($searchable as $column) {
                    $column instanceof Closure
                        ? $query->orWhere(fn (Builder $query) => $column($query, $pattern))
                        : $query->orWhere(new Expression('immutable_unaccent('.$column.')'), 'ilike', $pattern);
                }
            });
        }

        $this->applyStatus($query, $table);

        return $query;
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     */
    private function applyStatus(Builder $query, string $table): void
    {
        $softDeletes = in_array(SoftDeletes::class, class_uses_recursive($query->getModel()), true);

        if ($softDeletes) {
            // The listing decides what to show of the trash, not the global scope.
            $query->withoutGlobalScope(SoftDeletingScope::class);

            match ($this->status) {
                'active' => $query->whereNull("{$table}.deleted_at"),
                'inactive' => $query->whereNotNull("{$table}.deleted_at"),
                default => null,
            };

            return;
        }

        if ($this->status !== 'all') {
            $query->where("{$table}.is_active", $this->status === 'active');
        }
    }

    /**
     * Pages in two bounded queries: a count over the base query (filters, no joins) and the
     * ordered page. $decorate, when given, adds presentation data (joins, selects, counts) to
     * the page query only, never to the count.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query  base query: filters over its own table
     * @param  list<literal-string|Closure(Builder<TModel>, string): mixed>  $searchable
     * @param  Closure|null  $decorate  receives the query and adds joins, selects and counts
     * @return LengthAwarePaginator<int, TModel>
     */
    public function paginate(Builder $query, array $searchable, ?Closure $decorate = null): LengthAwarePaginator
    {
        $model = $query->getModel();
        $key = $model->qualifyColumn($model->getKeyName());
        $this->filter($query, $searchable);

        $total = (clone $query)->toBase()->getCountForPagination();
        $page = Paginator::resolveCurrentPage();
        $items = $total === 0 ? $model->newCollection() : $this->rows($query, $decorate, $key, $page);

        return (new LengthAwarePaginator($items, $total, $this->perPage, $page, [
            'path' => Paginator::resolveCurrentPath(),
        ]))->withQueryString();
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $filtered
     * @return Collection<int, TModel>
     */
    private function rows(Builder $filtered, ?Closure $decorate, string $key, int $page): Collection
    {
        $query = clone $filtered;

        if ($decorate !== null) {
            $decorate($query);
        }

        return $this->ordered($query, $key, $page)->get();
    }

    /**
     * Ordered by the requested column and, always, by the key: a stable order across pages.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function ordered(Builder $query, string $key, int $page): Builder
    {
        return $query
            ->orderBy($this->sortColumn, $this->direction)
            ->orderBy($key)
            ->forPage($page, $this->perPage);
    }

    /**
     * The normalized filters the frontend echoes back.
     *
     * @return array<string, string|int>
     */
    public function filters(): array
    {
        return [
            'search' => $this->search,
            'status' => $this->status,
            'sort' => $this->sort,
            'direction' => $this->direction,
            'per_page' => $this->perPage,
            ...array_map(fn (?string $value) => $value ?? 'all', $this->facets),
        ];
    }
}

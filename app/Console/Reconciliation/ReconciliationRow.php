<?php

namespace App\Console\Reconciliation;

/**
 * One figure of the reconciliation, as every path that can compute it says it: the old system's
 * rows, the new books through PostgreSQL and through a fold of the individual rows in PHP (exact
 * decimals), plus any sealed copy of it. It holds when every path that has a value agrees, with
 * no tolerance.
 *
 * A path can be advisory: a sealed copy in the OLD chain. If an advisory path disagrees while every
 * other path agrees (the old books and the new books alike), the row holds and the disagreement is
 * a finding about the old system (its seal is stale), not a difference of the new books.
 */
final readonly class ReconciliationRow
{
    /**
     * @param  array<string, string|null>  $values  by path name; null when the path does not apply
     * @param  list<string>  $advisory  paths whose disagreement is a finding, not a difference
     */
    public function __construct(
        public string $check,
        public string $period,
        public string $figure,
        public array $values,
        public array $advisory = [],
    ) {}

    public function holds(): bool
    {
        return count(array_unique($this->binding())) <= 1;
    }

    /**
     * The advisory paths that disagree with the rest, when the rest agree.
     *
     * @return list<string>
     */
    public function findings(): array
    {
        if (! $this->holds() || $this->binding() === []) {
            return [];
        }

        $agreed = array_values($this->binding())[0];

        return array_keys(array_filter(
            $this->values,
            fn (?string $value, string $path): bool => in_array($path, $this->advisory, true) && $value !== null && $value !== $agreed,
            ARRAY_FILTER_USE_BOTH,
        ));
    }

    /**
     * The values of the paths that must agree.
     *
     * @return array<string, string>
     */
    private function binding(): array
    {
        return array_filter(
            $this->values,
            fn (?string $value, string $path): bool => $value !== null && ! in_array($path, $this->advisory, true),
            ARRAY_FILTER_USE_BOTH,
        );
    }
}

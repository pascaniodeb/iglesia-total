<?php

namespace App\Console\Reconciliation;

/**
 * Every row of a reconciliation, by check.
 */
final class ReconciliationReport
{
    /** @var list<ReconciliationRow> */
    private array $rows = [];

    /** @var array<string, string> */
    private array $titles = [];

    public function title(string $check, string $title): void
    {
        $this->titles[$check] = $title;
    }

    /**
     * @param  array<string, string|null>  $values
     * @param  list<string>  $advisory  paths whose disagreement is a finding about the old system
     */
    public function add(string $check, string $period, string $figure, array $values, array $advisory = []): void
    {
        $this->rows[] = new ReconciliationRow($check, $period, $figure, $values, $advisory);
    }

    /**
     * @return list<ReconciliationRow>
     */
    public function rows(): array
    {
        return $this->rows;
    }

    /**
     * @return list<ReconciliationRow>
     */
    public function differences(): array
    {
        return array_values(array_filter($this->rows, fn (ReconciliationRow $row): bool => ! $row->holds()));
    }

    /**
     * Figures where the old system disagrees with itself (a stale seal) while the old books and the
     * new books agree: worth knowing, not a difference of the new books.
     *
     * @return list<ReconciliationRow>
     */
    public function findings(): array
    {
        return array_values(array_filter($this->rows, fn (ReconciliationRow $row): bool => $row->findings() !== []));
    }

    public function passes(): bool
    {
        return $this->rows !== [] && $this->differences() === [];
    }

    /**
     * @return array<string, array{title: string, rows: int, differences: int, findings: int}>
     */
    public function summary(): array
    {
        $summary = [];

        foreach ($this->titles as $check => $title) {
            $rows = array_filter($this->rows, fn (ReconciliationRow $row): bool => $row->check === $check);
            $summary[$check] = [
                'title' => $title,
                'rows' => count($rows),
                'differences' => count(array_filter($rows, fn (ReconciliationRow $row): bool => ! $row->holds())),
                'findings' => count(array_filter($rows, fn (ReconciliationRow $row): bool => $row->findings() !== [])),
            ];
        }

        return $summary;
    }
}

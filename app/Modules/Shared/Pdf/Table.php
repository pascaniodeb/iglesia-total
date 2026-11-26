<?php

namespace App\Modules\Shared\Pdf;

/**
 * A ruled table for printed reports: a shaded header, hairlines between rows, an optional shaded
 * total row, and the header repeated when it continues on another page. Amount cells are right
 * aligned and negative ones are red: a negative balance is never hidden.
 */
final class Table
{
    /**
     * @param  list<array{label: string, width: float, align?: 'L'|'C'|'R', money?: bool}>  $columns
     */
    public function __construct(
        private readonly Document $pdf,
        private readonly float $x,
        private readonly array $columns,
        private readonly float $rowHeight = 14,
    ) {}

    /**
     * Draws the table from $y and returns the y below it.
     *
     * @param  list<list<string>>  $rows
     * @param  list<string>|null  $total
     * @param  callable(): float  $newPage  adds a page and returns the y where the table continues
     */
    public function draw(float $y, array $rows, ?array $total, callable $newPage, float $bottom, string $empty = 'SIN MOVIMIENTOS'): float
    {
        $width = array_sum(array_column($this->columns, 'width'));
        $start = $y;
        $y = $this->header($y);

        if ($rows === []) {
            $this->pdf->SetFont('Helvetica', '', 7.5);
            $this->pdf->put($this->x, $y, $width, $this->rowHeight, $empty, 'C');
            $y += $this->rowHeight;
        }

        foreach ($rows as $row) {
            if ($y + $this->rowHeight > $bottom) {
                $this->pdf->box($this->x, $start, $width, $y - $start, 0.75);
                $start = $newPage();
                $y = $this->header($start);
            }

            $this->row($y, $row, false);
            $y += $this->rowHeight;
        }

        if ($total !== null) {
            if ($y + $this->rowHeight > $bottom) {
                $this->pdf->box($this->x, $start, $width, $y - $start, 0.75);
                $start = $newPage();
                $y = $this->header($start);
            }

            $this->row($y, $total, true);
            $y += $this->rowHeight;
        }

        $this->pdf->box($this->x, $start, $width, $y - $start, 0.75);

        return $y;
    }

    private function header(float $y): float
    {
        $height = $this->rowHeight + 3;
        $this->pdf->box($this->x, $y, array_sum(array_column($this->columns, 'width')), $height, 0.75, 0, 238);
        $x = $this->x;
        $this->pdf->SetFont('Times', 'B', 7.5);
        $this->pdf->SetTextColor(0);

        foreach ($this->columns as $index => $column) {
            $this->pdf->put($x, $y, $column['width'], $height, $column['label'], 'C');
            $x += $column['width'];

            if ($index < count($this->columns) - 1) {
                $this->pdf->rule($x, $y, $x, $y + $height, 0.5);
            }
        }

        return $y + $height;
    }

    /**
     * @param  list<string>  $cells
     */
    private function row(float $y, array $cells, bool $total): void
    {
        $width = array_sum(array_column($this->columns, 'width'));

        if ($total) {
            $this->pdf->box($this->x, $y, $width, $this->rowHeight, 0.5, 0, 238);
        } else {
            $this->pdf->rule($this->x, $y + $this->rowHeight, $this->x + $width, $this->rowHeight + $y, 0.3, 160);
        }

        $x = $this->x;

        foreach ($this->columns as $index => $column) {
            $text = $cells[$index] ?? '';
            $money = $column['money'] ?? false;
            $this->pdf->SetFont('Helvetica', $total ? 'B' : '', 7.5);
            $negative = $money && str_starts_with($text, '-') && $text !== '-';
            $negative ? $this->pdf->SetTextColor(179, 38, 30) : $this->pdf->SetTextColor(17);
            $this->pdf->put($x + 4, $y, $column['width'] - 8, $this->rowHeight, $text, $column['align'] ?? ($money ? 'R' : 'L'));
            $x += $column['width'];

            if ($index < count($this->columns) - 1) {
                $this->pdf->rule($x, $y, $x, $y + $this->rowHeight, 0.3, 160);
            }
        }

        $this->pdf->SetTextColor(0);
    }
}

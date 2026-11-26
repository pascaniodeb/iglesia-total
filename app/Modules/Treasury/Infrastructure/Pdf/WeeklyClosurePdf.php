<?php

namespace App\Modules\Treasury\Infrastructure\Pdf;

use App\Modules\Church\Application\ChurchIdentity;
use App\Modules\Church\Application\ChurchLetterhead;
use App\Modules\Church\Application\LetterheadVariant;
use App\Modules\Integrity\Application\SealedPeriods;
use App\Modules\Integrity\Domain\Ledger\Canonical;
use App\Modules\Shared\Money\Currency;
use App\Modules\Shared\Money\Money;
use App\Modules\Shared\Pdf\Document;
use App\Modules\Shared\Pdf\Format;
use App\Modules\Treasury\Infrastructure\Models\WeeklyClosure;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * The weekly income report of the IPUV, on a landscape letter page: eight numbered lines (one per
 * offering category, the surplus gathered into "OTROS INGRESOS"), three currency columns, a
 * signature slot beside each line, the general totals and the three signatures of the church.
 * Empty lines print empty: on this form the blank row is part of the paper.
 *
 * The totals are the closure's own, which PostgreSQL checked against the offerings when the week
 * was closed. The page cites the sealed block of the week when there is one.
 */
final class WeeklyClosurePdf
{
    private const float LEFT = 30;

    private const float WIDTH = 732;

    private const int ROWS = 8;

    private const float ROW = 33;

    private const float HEAD = 44;

    /** Column widths: no., date, code, concept, VES, USD, COP, signature block. */
    private const array COLUMNS = [29, 66, 55, 212, 72, 72, 72, 154];

    private const array SIGNATORIES = ['PASTOR', 'TESORERO(A) LOCAL', 'TESTIGO LOCAL'];

    public function __construct(
        private readonly ChurchLetterhead $letterhead,
        private readonly ChurchIdentity $identity,
        private readonly SealedPeriods $sealed,
    ) {}

    /**
     * @param  list<array{code: string, concept: string, ves: string, usd: string, cop: string}>  $lines
     */
    public function render(WeeklyClosure $closure, array $lines): string
    {
        $church = $this->identity->profile() ?? throw new LogicException('The church settings have not been filled in.');
        $weekEnd = $closure->week_end->toDateString();
        $rows = $this->rows($lines);

        $pdf = new Document('L', 'Letter');
        $pdf->AliasNbPages();
        $pdf->SetTitle(Document::encode("Reporte de ingresos semanal {$closure->week_key}"));
        $pdf->SetAuthor(Document::encode($church->name));
        $pdf->SetSubject(Document::encode("Semana {$closure->week_key}"));
        $pdf->footerLine = $this->footerLine($closure, $lines);
        $pdf->AddPage();

        $y = $this->letterhead->draw($pdf, LetterheadVariant::Framed, 'REPORTE DE INGRESOS SEMANAL');

        $pdf->SetFont('Helvetica', 'B', 7.5);
        $pdf->put(self::LEFT, $y, 40, 12, 'IGLESIA:');
        $pdf->rule(self::LEFT + 42, $y + 12, self::LEFT + 360, $y + 12, 0.75);
        $pdf->put(self::LEFT + 380, $y, 40, 12, 'PASTOR:');
        $pdf->rule(self::LEFT + 424, $y + 12, self::LEFT + self::WIDTH, $y + 12, 0.75);
        $pdf->SetFont('Helvetica', '', 9);
        $pdf->put(self::LEFT + 46, $y, 310, 12, $church->name);
        $pdf->put(self::LEFT + 428, $y, 300, 12, $church->pastor);
        $y += 20;

        $fields = [['CÓDIGO:', $church->code, 130, 'L'], ['R.I.F.:', $church->rif, 100, 'L'], ['DESDE:', Format::date($closure->week_start->toDateString()), 100, 'C'], ['HASTA:', Format::date($weekEnd), 100, 'C']];
        $x = self::LEFT;

        foreach ($fields as [$label, $value, $width, $align]) {
            $pdf->SetFont('Helvetica', 'B', 7.5);
            $pdf->put($x, $y, 44, 12, $label);
            $pdf->rule($x + 46, $y + 12, $x + 46 + $width, $y + 12, 0.75);
            $pdf->SetFont('Helvetica', '', 9);
            $pdf->put($x + 48, $y, $width - 4, 12, $value, $align);
            $x += 46 + $width + 24;
        }
        $y += 22;

        $y = $this->table($pdf, $y, $rows, $closure, Format::date($weekEnd));
        $this->signatures($pdf, $y + 28);

        return $pdf->Output('S');
    }

    /**
     * The eight lines of the form: the first seven categories and everything else in one.
     *
     * @param  list<array{code: string, concept: string, ves: string, usd: string, cop: string}>  $lines
     * @return list<array{code: string, concept: string, ves: string, usd: string, cop: string}|null>
     */
    private function rows(array $lines): array
    {
        if (count($lines) > self::ROWS) {
            $tail = array_slice($lines, self::ROWS - 1);
            $lines = [...array_slice($lines, 0, self::ROWS - 1), [
                'code' => '',
                'concept' => 'OTROS INGRESOS',
                'ves' => $this->sum(Currency::VES, array_column($tail, 'ves')),
                'usd' => $this->sum(Currency::USD, array_column($tail, 'usd')),
                'cop' => $this->sum(Currency::COP, array_column($tail, 'cop')),
            ]];
        }

        return [...$lines, ...array_fill(0, self::ROWS - count($lines), null)];
    }

    /**
     * @param  list<array{code: string, concept: string, ves: string, usd: string, cop: string}|null>  $rows
     */
    private function table(Document $pdf, float $y, array $rows, WeeklyClosure $closure, string $lineDate): float
    {
        $x = array_map(fn (int $i): float => self::LEFT + array_sum(array_slice(self::COLUMNS, 0, $i)), range(0, count(self::COLUMNS)));
        $top = $y;
        $height = self::HEAD + self::ROWS * self::ROW + 26;
        $right = self::LEFT + self::WIDTH;

        $pdf->box(self::LEFT, $y, self::WIDTH, self::HEAD, 1, 0, 238);
        $pdf->SetFont('Times', 'B', 8);

        foreach (['NO.', 'FECHA', 'CÓDIGO', 'CONCEPTO'] as $i => $label) {
            $pdf->put($x[$i], $y, self::COLUMNS[$i], self::HEAD, $label, 'C');
        }

        $pdf->put($x[4], $y, 216, 22, 'INGRESOS', 'C');
        $pdf->put($x[7], $y, 154, 22, 'CONTABILIZADO POR:', 'C');

        foreach (['BOLÍVARES', 'USD', 'COP'] as $i => $label) {
            $pdf->put($x[4 + $i], $y + 22, 72, 22, $label, 'C');
        }

        $pdf->put($x[7], $y + 22, 102.7, 22, 'FIRMA', 'C');
        $pdf->put($x[7] + 102.7, $y + 22, 51.3, 22, 'C.I.', 'C');
        $pdf->rule($x[4], $y + 22, $right, $y + 22, 1);
        $pdf->rule($x[4] + 72, $y + 22, $x[4] + 72, $y + self::HEAD, 1);
        $pdf->rule($x[4] + 144, $y + 22, $x[4] + 144, $y + self::HEAD, 1);
        $pdf->rule($x[7] + 102.7, $y + 22, $x[7] + 102.7, $y + self::HEAD, 1);
        $y += self::HEAD;

        foreach ($rows as $i => $row) {
            $pdf->rule(self::LEFT, $y + self::ROW, $right, $y + self::ROW, 1);
            $pdf->SetFont('Helvetica', '', 8.5);
            $pdf->put($x[0], $y, self::COLUMNS[0], self::ROW, (string) ($i + 1), 'C');
            $pdf->put($x[1], $y, self::COLUMNS[1], self::ROW, $row === null ? '' : $lineDate, 'C');
            $pdf->put($x[2], $y, self::COLUMNS[2], self::ROW, $row['code'] ?? '', 'C');
            $pdf->put($x[3] + 5, $y, self::COLUMNS[3] - 10, self::ROW, $row['concept'] ?? '');

            foreach (['ves', 'usd', 'cop'] as $c => $key) {
                $value = $row[$key] ?? '0';
                $pdf->put($x[4 + $c] + 4, $y, 64, self::ROW, Format::cell($value) === '-' ? '' : Format::amount($value), 'R');
            }

            $pdf->rule($x[7], $y + self::ROW / 2, $right, $y + self::ROW / 2, 1);
            $pdf->SetFont('Helvetica', '', 7);
            foreach ([0, 1] as $slot) {
                $pdf->put($x[7] + 4, $y + $slot * self::ROW / 2, 90, self::ROW / 2, 'FIRMA');
                $pdf->put($x[7] + 102.7 + 4, $y + $slot * self::ROW / 2, 44, self::ROW / 2, 'C.I.');
            }

            $y += self::ROW;
        }

        $rowsEnd = $y;
        $pdf->box(self::LEFT, $y, self::WIDTH, 26, 1, 0, 238);
        $pdf->SetFont('Times', 'B', 9);
        $pdf->put(self::LEFT, $y, $x[4] - self::LEFT - 12, 26, 'TOTALES GENERALES:', 'R');
        $pdf->SetFont('Helvetica', 'B', 8.5);

        foreach ([$closure->total_ves, $closure->total_usd, $closure->total_cop] as $c => $value) {
            $pdf->put($x[4 + $c] + 4, $y, 64, 26, Format::cell((string) $value) === '-' ? '' : Format::amount((string) $value), 'R');
        }

        foreach ([1, 2, 3, 4, 7] as $i) {
            $pdf->rule($x[$i], $top, $x[$i], $rowsEnd, 1);
        }

        foreach ([5, 6] as $i) {
            $pdf->rule($x[$i], $top + 22, $x[$i], $rowsEnd, 1);
        }

        foreach ([4, 5, 6, 7] as $i) {
            $pdf->rule($x[$i], $rowsEnd, $x[$i], $rowsEnd + 26, 1);
        }

        $pdf->box(self::LEFT, $top, self::WIDTH, $height, 1);

        return $rowsEnd + 26;
    }

    private function signatures(Document $pdf, float $y): void
    {
        $slot = self::WIDTH / count(self::SIGNATORIES);

        foreach (self::SIGNATORIES as $i => $label) {
            $center = self::LEFT + $slot * $i + $slot / 2;
            $pdf->rule($center - $slot * 0.39, $y, $center + $slot * 0.39, $y, 1);
            $pdf->SetFont('Helvetica', 'B', 8);
            $pdf->put($center - $slot / 2, $y + 4, $slot, 10, $label, 'C');
        }
    }

    /**
     * @param  list<array{code: string, concept: string, ves: string, usd: string, cop: string}>  $lines
     */
    private function footerLine(WeeklyClosure $closure, array $lines): string
    {
        $code = strtoupper(implode('-', str_split(substr(Canonical::hash([
            'week' => $closure->week_key,
            'count' => $closure->offerings_count,
            'totals' => [(string) $closure->total_ves, (string) $closure->total_usd, (string) $closure->total_cop],
            'lines' => $lines,
        ]), 0, 16), 4)));
        $seal = $this->sealed->of('weekly', [$closure->week_key])[$closure->week_key] ?? null;

        return "Código de verificación {$code} · ".($seal === null ? 'Semana aún sin sellar' : 'Sellada en el bloque '.$seal['height'].' '.substr($seal['hash'], 0, 16)).' · Impreso el '.Carbon::now()->format('d/m/Y H:i');
    }

    /**
     * @param  list<string>  $values
     */
    private function sum(Currency $currency, array $values): string
    {
        return Money::sum($currency, ...array_map(fn (string $value): Money => Money::of($value, $currency), $values))->amount();
    }
}
